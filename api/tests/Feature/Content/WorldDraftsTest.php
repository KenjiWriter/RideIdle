<?php

declare(strict_types=1);

use App\Content\ContentPackInvalid;
use App\Content\ContentSource;
use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\TutorialForm;
use App\Content\Definitions\WorldForm;
use App\Content\PackContent;
use App\Content\PackValidator;
use App\Models\ContentDefinition;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Services\ContentDrafts;
use App\Services\DefinitionUsage;
use Illuminate\Support\Facades\DB;

// The rules and the tutorial as documents that people edit in sections: nothing is lost in the round trip, a draft of them has to describe a world that can be
// played, and what the players are in cannot be taken out of it.

function rulesDocument(): array
{
    return JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
}

function tutorialDocument(): array
{
    return JsonDocument::decode((string) repositoryContent()->raw('rules/tutorial'));
}

function theRules(string $slug = 'starter'): ContentDefinition
{
    return ContentDefinition::query()->where('kind', 'rules')->where('slug', $slug)->firstOrFail();
}

beforeEach(fn () => contentPacks()->import(repositoryContent(), checkTables: false));

describe('the documents of the rules', function () {
    it('go through a person and back as they were, with their empty objects and the keys that look like numbers', function (string $key) {
        $raw = (string) repositoryContent()->raw($key);

        expect(ContentSource::documentChecksum(JsonDocument::encode(JsonDocument::decode($raw))))->toBe(ContentSource::documentChecksum($raw));
    })->with(['rules/starter', 'rules/tutorial']);

    it('would lose them in an array that went through json_decode and json_encode, which is why the documents are not made that way', function () {
        $raw = (string) repositoryContent()->raw('rules/starter');

        expect(ContentSource::documentChecksum(json_encode(json_decode($raw, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->not->toBe(ContentSource::documentChecksum($raw));
    });

    it('are read from the database as they are, empty objects too', function () {
        expect(theRules()->publishedDocument()['weather']['dry']['stat_ppm'])->toBeInstanceOf(stdClass::class)
            ->and(JsonDocument::encode(theRules()->publishedDocument()))->toContain('"stat_ppm": {}');
    });
});

describe('the form of the world', function () {
    it('makes the cities, the roads and the tracks of the rules out of the state it shows them as, as they were written', function () {
        $rules = rulesDocument();
        $made = WorldForm::apply($rules, WorldForm::toState($rules));

        expect($made)->toEqual($rules)
            ->and(array_keys($made))->toBe(array_keys($rules))
            ->and(array_keys($made['regions']))->toBe(array_keys($rules['regions']))
            ->and(array_keys($made['tracks']))->toBe(array_keys($rules['tracks']));

        foreach ($rules['regions'] as $slug => $region) {
            expect(array_keys($made['regions'][$slug]))->toBe(array_keys($region), "region {$slug}")
                ->and(array_keys($made['regions'][$slug]['weather_ppm']))->toBe(array_keys($region['weather_ppm']));
        }

        foreach ($rules['tracks'] as $slug => $track) {
            expect(array_keys($made['tracks'][$slug]))->toBe(array_keys($track), "track {$slug}")
                ->and(array_keys($made['tracks'][$slug]['demand']))->toBe(array_keys($track['demand']))
                ->and(array_keys($made['tracks'][$slug]['reference']))->toBe(array_keys($track['reference']));
        }

        expect(JsonDocument::encode($made))->toBe(JsonDocument::encode($rules));
    });

    it('takes the price of a lap in dollars, the position on the map as numbers, and leaves out a line that has no name', function () {
        $rules = rulesDocument();
        $state = WorldForm::toState($rules);
        $state['tracks'][0]['reward'] = '15.55';
        $state['regions'][0]['map'] = ['x' => '80', 'y' => '300'];
        $state['regions'][0]['weather'][] = ['weather' => '', 'ppm' => 5];

        $made = WorldForm::apply($rules, $state);

        expect($made['tracks']['zlomowo-street']['reward_cents_per_lap'])->toBe(1555)
            ->and($made['regions']['zlomowo']['map'])->toBe(['x' => 80, 'y' => 300])
            ->and($made['regions']['zlomowo']['weather_ppm'])->toEqual($rules['regions']['zlomowo']['weather_ppm']);
    });

    it('does not touch what is not the cities, the roads and the tracks', function () {
        $rules = rulesDocument();
        $state = WorldForm::toState($rules);
        $state['regions'] = [];

        $made = WorldForm::apply($rules, $state);

        expect($made['regions'])->toBe([])
            ->and($made['shop'])->toEqual($rules['shop'])
            ->and($made['weather'])->toEqual($rules['weather'])
            ->and(array_keys($made))->toBe(array_keys($rules));
    });
});

describe('the form of the tutorial', function () {
    it('makes the version, the script and the steps out of the state it shows them as, as they were written', function () {
        $tutorial = tutorialDocument();
        $made = TutorialForm::apply($tutorial, TutorialForm::toState($tutorial));

        // The order of the languages inside a text is not the order of the file (the file starts some with Polish), and does not mean anything.
        expect($made)->toEqual($tutorial)
            ->and(array_keys($made))->toBe(array_keys($tutorial));

        foreach ($tutorial['steps'] as $index => $step) {
            expect(array_keys($made['steps'][$index]))->toBe(array_keys($step), "step {$step['key']}")
                ->and(array_keys((array) $made['steps'][$index]['reward']))->toBe(array_keys((array) $step['reward']));
        }
    });

    it('gives a step that gives nothing the empty object of the file, takes cash in dollars and leaves out a message with no text', function () {
        $tutorial = tutorialDocument();
        $state = TutorialForm::toState($tutorial);
        $state['steps'][0]['reward_xp'] = null;
        $state['steps'][3]['reward_cash'] = '4.5';
        $state['steps'][1]['sms'][1] = DefinitionForm::texts([]);

        $made = TutorialForm::apply($tutorial, $state);

        expect($made['steps'][0]['reward'])->toBeInstanceOf(stdClass::class)
            ->and($made['steps'][3]['reward']['cash_cents'])->toBe(450)
            ->and($made['steps'][1]['sms'])->toHaveCount(1);
    });
});

describe('a draft of the rules', function () {
    it('is kept when the world it describes can be played, next to what was published, with what it changes written to the trail', function () {
        $admin = anAdmin();
        $rules = rulesDocument();
        $rules['regions']['zlomowo']['map'] = ['x' => 90, 'y' => 300];

        app(ContentDrafts::class)->save(theRules(), $rules, $admin);
        $definition = theRules()->refresh();

        expect($definition->draftDocument()['regions']['zlomowo']['map'])->toBe(['x' => 90, 'y' => 300])
            ->and($definition->publishedDocument()['regions']['zlomowo']['map'])->toBe(['x' => 78, 'y' => 290])
            // The empty objects of the document are still objects in the draft.
            ->and(JsonDocument::encode($definition->draftDocument()))->toContain('"stat_ppm": {}');
    });

    it('is not kept when the weather of a city does not add up, a demand does not, or a track asks for what it has no reference for', function (Closure $change, string $pointer) {
        $rules = rulesDocument();
        $change($rules);

        try {
            app(ContentDrafts::class)->save(theRules(), $rules, anAdmin());
            $this->fail('The draft should have been refused.');
        } catch (ContentPackInvalid $problem) {
            expect(array_column($problem->result->errors(), 'pointer'))->toContain($pointer);
        }

        expect(theRules()->refresh()->hasDraft())->toBeFalse();
    })->with([
        'weather that does not add up' => [function (array &$rules): void {
            $rules['regions']['zlomowo']['weather_ppm']['dry'] = 400000;
        }, '/regions/zlomowo/weather_ppm'],
        'weather the rules do not know' => [function (array &$rules): void {
            $rules['regions']['zlomowo']['weather_ppm']['hail'] = 1;
            $rules['regions']['zlomowo']['weather_ppm']['dry'] -= 1;
        }, '/regions/zlomowo/weather_ppm/hail'],
        'demands that do not add up' => [function (array &$rules): void {
            $rules['tracks']['zlomowo-street']['demand']['grip'] = 30;
        }, '/tracks/zlomowo-street/demand'],
        'a demand with no reference' => [function (array &$rules): void {
            unset($rules['tracks']['zlomowo-street']['reference']['braking']);
        }, '/tracks/zlomowo-street/reference'],
        'a track in a city that is not there' => [function (array &$rules): void {
            $rules['tracks']['zlomowo-street']['region'] = 'nowhere';
        }, '/tracks/zlomowo-street/region'],
        'a city with no track' => [function (array &$rules): void {
            $rules['regions']['lonely'] = $rules['regions']['zlomowo'];
            $rules['roads'][] = ['from' => 'zlomowo', 'to' => 'lonely', 'km' => 10];
        }, '/regions/lonely'],
        'a city that no road leads to' => [function (array &$rules): void {
            $rules['regions']['lonely'] = $rules['regions']['zlomowo'];
            $rules['tracks']['lonely-loop'] = [...$rules['tracks']['zlomowo-street'], 'region' => 'lonely'];
        }, '/regions/lonely'],
        'a road from a city to itself' => [function (array &$rules): void {
            $rules['roads'][] = ['from' => 'zlomowo', 'to' => 'zlomowo', 'km' => 5];
        }, '/roads/4'],
        'a first track that is not open from the first level' => [function (array &$rules): void {
            $rules['tracks']['zlomowo-street']['min_player_level'] = 2;
        }, '/tracks/zlomowo-street/min_player_level'],
    ]);

    it('is checked against the content as it is being worked on: it may sell a part that is a draft, and not one that is archived', function () {
        $admin = anAdmin();
        $part = repositoryDocument('parts/street-tires-t2');
        app(ContentDrafts::class)->create('part', [...$part, 'slug' => 'street-tires-t9'], $admin);

        $rules = rulesDocument();
        $rules['shop']['parts'][] = 'street-tires-t9';

        app(ContentDrafts::class)->save(theRules(), $rules, $admin);

        expect(theRules()->refresh()->hasDraft())->toBeTrue();

        $other = rulesDocument();
        $other['shop']['parts'][] = 'street-tires-t5';
        ContentDefinition::query()->where('slug', 'street-tires-t5')->update(['status' => ContentDefinition::STATUS_ARCHIVED]);

        $result = app(ContentDrafts::class)->validate('rules', $other, 'starter');

        expect($result->isValid())->toBeFalse()->and(array_column($result->errors(), 'message'))->toContain('The shop sells street-tires-t5, which is not a part of the pack.');
    });

    it('cannot be started as a new definition, and has only the documents that a person edits', function () {
        expect(fn () => app(ContentDrafts::class)->create('rules', rulesDocument(), anAdmin()))->toThrow(DefinitionRefused::class);

        $economy = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'economy')->firstOrFail();

        expect(fn () => app(ContentDrafts::class)->validate('rules', ['anything' => 1], 'economy'))->toThrow(InvalidArgumentException::class)
            ->and($economy->status)->toBe(ContentDefinition::STATUS_ACTIVE)
            ->and(PackValidator::RULES)->toBe(['starter' => 'rules.schema.json', 'tutorial' => 'tutorial.schema.json']);
    });
});

describe('what the players are in', function () {
    it('cannot be taken out of the rules: a city that has a garage of a driver, or a track that a run drives', function () {
        $driver = User::factory()->guest()->create();
        DB::table('city_garages')->insert(['user_id' => $driver->id, 'region' => 'kruszyn', 'capacity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $usage = app(DefinitionUsage::class);
        $rules = rulesDocument();
        $without = $rules;
        unset($without['regions']['kruszyn'], $without['tracks']['kruszyn-quarry']);
        $without['roads'] = array_values(array_filter($rules['roads'], fn (array $road): bool => ! in_array('kruszyn', [$road['from'], $road['to']], true)));

        expect($usage->removedInUse('starter', $rules, $without))->toBe(['city kruszyn (1)'])
            ->and($usage->removedInUse('starter', $rules, $rules))->toBe([]);

        driverWithRun(['track_slug' => 'autodrom-circuit']);
        $noTrack = $rules;
        unset($noTrack['regions']['autodrom'], $noTrack['tracks']['autodrom-circuit']);
        $noTrack['roads'] = array_values(array_filter($rules['roads'], fn (array $road): bool => ! in_array('autodrom', [$road['from'], $road['to']], true)));

        expect($usage->removedInUse('starter', $rules, $noTrack))->toBe(['track autodrom-circuit (1)']);

        try {
            app(ContentDrafts::class)->save(theRules(), $noTrack, anAdmin());
            $this->fail('The draft should have been refused.');
        } catch (ContentPackInvalid|DefinitionRefused $problem) {
            expect($problem)->toBeInstanceOf(DefinitionRefused::class)->and($problem->reason)->toBe('removed_in_use');
        }
    });

    it('cannot take a step out of the tutorial that a driver has met, and can add and reorder', function () {
        $driver = User::factory()->guest()->create();
        TutorialProgress::query()->create(['user_id' => $driver->id, 'step_key' => 'first-lap', 'state' => TutorialProgress::STATE_DONE]);

        $usage = app(DefinitionUsage::class);
        $tutorial = tutorialDocument();
        $fewer = $tutorial;
        $fewer['steps'] = array_values(array_filter($tutorial['steps'], fn (array $step): bool => $step['key'] !== 'first-lap'));
        $reordered = $tutorial;
        $reordered['steps'] = array_reverse($tutorial['steps']);

        expect($usage->removedInUse('tutorial', $tutorial, $fewer))->toBe(['step first-lap (1)'])
            ->and($usage->removedInUse('tutorial', $tutorial, $reordered))->toBe([]);
    });
});

describe('the content of a pack', function () {
    it('is refused when its world cannot be played, as a draft of it would be', function () {
        $broken = contentWith(['rules/starter' => function (array $rules): array {
            $rules['regions']['zlomowo']['weather_ppm']['dry'] = 400000;

            return $rules;
        }]);

        $result = PackValidator::fromConfig()->validateSource($broken);

        expect($result->isValid())->toBeFalse()->and(array_column($result->errors(), 'pointer'))->toContain('/regions/zlomowo/weather_ppm');
    });

    it('is warned about a language that a name of the world or a message of the tutorial has no text in, and accepts it', function () {
        $rules = rulesDocument();
        $track = array_key_first($rules['tracks']);
        unset($rules['tracks'][$track]['name']['de']);

        $tutorial = tutorialDocument();
        $step = array_key_first(array_filter($tutorial['steps'], fn (array $step): bool => $step['sms'] !== []));
        unset($tutorial['steps'][$step]['sms'][0]['fr'], $tutorial['steps'][$step]['reply']['pt-BR']);

        $drafts = app(ContentDrafts::class);
        $world = $drafts->validate('rules', $rules, 'starter');
        $steps = $drafts->validate('rules', $tutorial, 'tutorial');

        expect($world->isValid())->toBeTrue()->and(array_column($world->warnings(), 'pointer'))->toBe(["/tracks/{$track}/name/de"])
            ->and($steps->isValid())->toBeTrue()->and(array_column($steps->warnings(), 'pointer'))->toBe(["/steps/{$step}/sms/0/fr", "/steps/{$step}/reply/pt-BR"]);

        $pack = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => function (array $rules) use ($track): array {
            unset($rules['tracks'][$track]['name']['de']);

            return $rules;
        }]));

        expect($pack->isValid())->toBeTrue()->and(array_column($pack->warnings(), 'pointer'))->toContain("/tracks/{$track}/name/de");
    });

    it('is refused when the tutorial gives away what is not there, or two steps share a key', function () {
        $documents = repositoryDocuments();
        $tutorial = JsonDocument::decode($documents['rules/tutorial']);
        $tutorial['steps'][1]['reward'] = ['part' => 'part-from-nowhere'];
        $tutorial['steps'][2]['reward'] = ['garage' => 'city-from-nowhere'];
        $tutorial['steps'][3]['key'] = $tutorial['steps'][2]['key'];
        $documents['rules/tutorial'] = JsonDocument::encode($tutorial);

        $result = PackValidator::fromConfig()->validateSource(new PackContent('2026.10.0', $documents));

        expect(array_column($result->errors(), 'pointer'))->toContain('/steps/1/reward/part', '/steps/2/reward/garage', '/steps/3/key');
    });
});
