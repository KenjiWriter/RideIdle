<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Support\Locale;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** @return array<string, mixed> */
function tutorialData(): array
{
    return json_decode((string) file_get_contents(config('ride.content_path').'/rules/tutorial.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Errors of the tutorial schema for some data, empty when it is valid.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function tutorialSchemaErrors(array $data): array
{
    $validator = new Validator;
    $validator->resolver()?->registerPrefix('https://rideidle.local/schema/', config('ride.content_path').'/schema');

    // An empty list or object of the data must stay what it is: the reward of a step with none is an object.
    $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR));

    foreach ($decoded->steps as $index => $step) {
        if ($data['steps'][$index]['reward'] === []) {
            $step->reward = new stdClass;
        }
    }

    $outcome = $validator->validate($decoded, 'https://rideidle.local/schema/tutorial.schema.json');

    return $outcome->isValid() ? [] : (new ErrorFormatter)->formatKeyed($outcome->error());
}

it('matches its schema', function () {
    expect(tutorialSchemaErrors(tutorialData()))->toBe([]);
});

it('is refused by the schema when a step waits for something the server cannot read', function () {
    $data = tutorialData();
    $data['steps'][1]['completion'] = 'the_driver_feels_ready';

    expect(tutorialSchemaErrors($data))->not->toBe([]);
});

it('is refused by the schema when a step has three messages', function () {
    $data = tutorialData();
    $data['steps'][1]['sms'][] = $data['steps'][1]['sms'][0];

    expect(tutorialSchemaErrors($data))->not->toBe([]);
});

it('has steps with keys of their own, the first of which is the choice of a wreck', function () {
    $keys = array_column(tutorialData()['steps'], 'key');

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and($keys[0])->toBe('wreck');
});

it('writes the messages and the answer of every step in all five languages', function () {
    $missing = [];

    foreach (tutorialData()['steps'] as $step) {
        $texts = [...$step['sms'], $step['goal'], ...array_filter([$step['reply'] ?? null, $step['waiting'] ?? null, $step['decline'] ?? null])];

        foreach ($texts as $text) {
            foreach (array_diff(Locale::supported(), array_keys($text)) as $locale) {
                $missing[] = "{$step['key']}: {$locale}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('gives an answer to every step that shows messages, and signs the last message as the mentor does', function () {
    $problems = [];

    foreach (tutorialData()['steps'] as $step) {
        if ($step['sms'] !== [] && ! isset($step['reply'])) {
            $problems[] = "{$step['key']}: messages with no answer";
        }

        if ($step['sms'] !== [] && ! str_ends_with(end($step['sms'])['en'], '— K.')) {
            $problems[] = "{$step['key']}: the last message is not signed";
        }
    }

    expect($problems)->toBe([]);
});

it('does not let the mentor write twice from the same number', function () {
    $senders = array_column(tutorialData()['steps'], 'sender');

    expect($senders)->toBe(array_values(array_unique($senders)));
});

it('lets the driver finish what it started: a step that is triggered by one fact is completed by another', function () {
    $problems = [];

    foreach (tutorialData()['steps'] as $step) {
        if ($step['trigger'] === $step['completion'] && $step['trigger'] !== 'none') {
            $problems[] = "{$step['key']}: the same fact starts and ends it, so it would be done the moment it started";
        }
    }

    expect($problems)->toBe([]);
});

it('numbers the steps from one', function () {
    $steps = app(TutorialSteps::class);

    expect($steps->count())->toBe(count(tutorialData()['steps']))
        ->and($steps->numberOf('wreck'))->toBe(1)
        ->and($steps->numberOf('start'))->toBe(2)
        ->and($steps->numberOf('first-lap'))->toBe(3)
        ->and($steps->numberOf('nope'))->toBeNull()
        ->and($steps->find('refuel')['target'])->toBe('refuel');
});

it('goes through the phases in order, the guided first minutes in the first two', function () {
    $steps = tutorialData()['steps'];
    $phases = array_column($steps, 'phase');
    $sorted = $phases;
    sort($sorted);

    expect($phases)->toBe($sorted)
        ->and(array_column(array_slice($steps, 0, 10), 'phase'))->each->toBeLessThanOrEqual(2)
        ->and(array_column(array_slice($steps, 10), 'phase'))->each->toBeGreaterThanOrEqual(3);
});

it('does not pay experience or garages after the guided first minutes, so that the levels are the work of the laps', function () {
    $paid = [];

    foreach (array_slice(tutorialData()['steps'], 10) as $step) {
        if (isset($step['reward']['xp']) || isset($step['reward']['garage'])) {
            $paid[] = $step['key'];
        }
    }

    expect($paid)->toBe([]);
});

it('lets the driver leave only an optional step, which has the words of the button, and gives the parts a merge needs', function () {
    $problems = [];

    foreach (tutorialData()['steps'] as $step) {
        if (($step['optional'] ?? false) && ! isset($step['decline'])) {
            $problems[] = "{$step['key']}: optional with no button";
        }

        if (in_array('merge', $step['arranges'] ?? [], true) && ! (($step['gift']['count'] ?? 1) >= 2)) {
            $problems[] = "{$step['key']}: arranges a merge but gives fewer than two parts";
        }

        if (in_array('car_bought', TutorialSteps::conditions($step['completion']), true) && ! isset(tutorialData()['script']['exchange_listing'])) {
            $problems[] = "{$step['key']}: waits for a bought car and the script has no listing";
        }
    }

    expect($problems)->toBe([]);
});

it('names a goal for every step, and says what a step waits for when its trigger is more than the step before it being done', function () {
    $problems = [];

    foreach (tutorialData()['steps'] as $step) {
        $own = array_diff(TutorialSteps::conditions($step['trigger']), ['none', 'run_started']);

        if ($own !== [] && ! isset($step['waiting']) && $step['phase'] >= 3) {
            $problems[] = "{$step['key']}: waits for a fact and does not say it";
        }
    }

    expect($problems)->toBe([]);
});
