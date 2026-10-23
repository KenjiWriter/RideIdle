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
        $texts = [...$step['sms'], ...(isset($step['reply']) ? [$step['reply']] : [])];

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
        ->and($steps->numberOf('first-lap'))->toBe(2)
        ->and($steps->numberOf('nope'))->toBeNull()
        ->and($steps->find('refuel')['target'])->toBe('refuel');
});
