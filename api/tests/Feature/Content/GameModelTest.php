<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\ContentSource;
use App\Content\GameModel;
use App\Content\PackContent;
use App\Content\PackValidationResult;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Models\Part;
use App\Models\User;
use App\Services\ContentPublisher;

// The model of the game: the slots that parts go into (with the roles that the formulas know them by) and the statistics the screens show. The car game is the
// model that is there when a pack has none, and a pack that calls its slots otherwise is the same game under other names.

/**
 * The content of the repository with some slots called otherwise, everywhere a slot is named: the model, the parts, and the rules and the economy that are by slot.
 *
 * @param  array<string, string>  $renames  the key of a slot of the car game => its key in the other game
 * @param  bool  $named  whether the vocabulary of the pack names the slots that the app has no name for
 */
function renamedSlots(array $renames, bool $named = true): PackContent
{
    $rename = static fn (string $slot): string => $renames[$slot] ?? $slot;
    $byKey = static fn (array $map): array => array_combine(array_map($rename, array_keys($map)), array_values($map));
    $changes = [];

    foreach (repositoryContent()->slugs('parts') as $slug) {
        $changes["parts/{$slug}"] = static fn (array $part): array => [...$part, 'slot' => $rename($part['slot'])];
    }

    $changes['rules/starter'] = static function (array $rules) use ($rename, $byKey): array {
        $rules['wear']['life_km'] = $byKey($rules['wear']['life_km']);
        $rules['wear']['rough_ppm'] = $byKey($rules['wear']['rough_ppm']);
        $rules['affixes']['unique'] = $byKey($rules['affixes']['unique']);
        $rules['workshop']['base_time_ms'] = $byKey($rules['workshop']['base_time_ms']);
        $rules['shop']['crates']['slots'] = array_map($rename, $rules['shop']['crates']['slots']);

        foreach ($rules['affixes']['definitions'] as $key => $definition) {
            $rules['affixes']['definitions'][$key]['slots'] = array_map($rename, $definition['slots']);
        }

        foreach ($rules['workshop']['equipment'] as $key => $item) {
            if (isset($item['slots'])) {
                $rules['workshop']['equipment'][$key]['slots'] = array_map($rename, $item['slots']);
            }
        }

        return $rules;
    };
    $changes['rules/economy'] = static function (array $economy) use ($byKey): array {
        $economy['anchors']['parts']['slot_share'] = $byKey($economy['anchors']['parts']['slot_share']);

        return $economy;
    };
    $changes['rules/model'] = static fn (array $model): array => [...$model, 'slots' => array_map(static fn (array $slot): array => [...$slot, 'key' => $rename($slot['key'])], $model['slots'])];

    if ($named) {
        $changes['rules/vocabulary'] = static fn (array $vocabulary): array => [
            ...$vocabulary,
            'messages' => array_merge($vocabulary['messages'] === [] ? [] : (array) $vocabulary['messages'], ...array_map(
                static fn (string $slot): array => ["slot.{$slot}" => ['en' => ucfirst($slot), 'pl' => ucfirst($slot)]],
                array_values($renames),
            )),
        ];
    }

    return contentWith($changes);
}

/** @return list<string> the files and the places of the errors of a content, as `file pointer` */
function modelErrors(ContentSource $content): array
{
    return array_map(static fn (array $issue): string => "{$issue['file']} {$issue['pointer']}", PackValidator::fromConfig()->validateSource($content)->errors());
}

/** The same factory of the runs that the game has, for a content. */
function inputsOf(ContentSource $content): RunInputsFactory
{
    return new RunInputsFactory(Catalog::fromSource($content), Rules::fromSource($content));
}

/** A content with a change in the model. */
function withModel(Closure $change): PackContent
{
    return contentWith(['rules/model' => $change]);
}

const OTHER_GAME = ['tires' => 'hull', 'engine' => 'reactor', 'boost' => 'thrusters'];

describe('the model of the car game', function () {
    it('is what the document of the repository says, with the roles that the formulas know', function () {
        $model = GameModel::fromSource(repositoryContent());

        expect($model->toArray())->toBe(GameModel::default()->toArray())
            ->and($model->slotKeys())->toBe(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank', 'ecu'])
            ->and($model->visibleSlotKeys())->toBe(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank'])
            ->and($model->slotOf('tires'))->toBe('tires')
            ->and($model->slotOf('exhaust'))->toBeNull()
            ->and($model->roleOf('boost'))->toBe('boost')
            ->and($model->roleOf('exhaust'))->toBeNull()
            ->and($model->roleOf('no-such-slot'))->toBeNull()
            ->and($model->is('engine', 'engine'))->toBeTrue()
            ->and($model->is('engine', 'tires'))->toBeFalse()
            ->and($model->has('ecu'))->toBeTrue()
            ->and($model->has('sails'))->toBeFalse()
            ->and($model->slotsOfRoles(['gearbox', 'tires', 'no-such-role']))->toBe(['gearbox', 'tires'])
            ->and($model->roleless())->toBe(['exhaust'])
            ->and($model->stats())->toBe(GameModel::STATS);
    });

    it('is the one that a content without the document has', function () {
        $content = contentWith([], ['rules/model']);

        expect($content->has('rules/model'))->toBeFalse()
            ->and(GameModel::fromSource($content)->toArray())->toBe(GameModel::default()->toArray())
            ->and(Rules::fromSource($content)->model()->slotOf('tires'))->toBe('tires')
            ->and(modelErrors($content))->toBe([]);
    });

    it('has the slot of a role under the key that the pack gave it, and lists the statistics in the order of the pack', function () {
        $model = GameModel::fromSource(contentWith(['rules/model' => fn (array $model): array => [
            ...$model,
            'slots' => [['key' => 'hull', 'role' => 'tires'], ['key' => 'sails'], ['key' => 'logbook', 'hidden' => true]],
            'stats' => ['top_speed', 'accel'],
        ]]));

        expect($model->slotOf('tires'))->toBe('hull')
            ->and($model->slotOf('engine'))->toBeNull()
            ->and($model->roleOf('hull'))->toBe('tires')
            ->and($model->visibleSlotKeys())->toBe(['hull', 'sails'])
            ->and($model->roleless())->toBe(['sails'])
            ->and($model->stats())->toBe(['top_speed', 'accel']);
    });
});

describe('the validation of a model', function () {
    it('lets the content of the repository through, and the same game under other names', function () {
        expect(modelErrors(repositoryContent()))->toBe([])
            ->and(modelErrors(renamedSlots(OTHER_GAME)))->toBe([]);
    });

    it('wants a slot once, a role on one slot, and tires in the game', function () {
        $listed = fn (array $slot): array => modelErrors(withModel(fn (array $model): array => [...$model, 'slots' => [...$model['slots'], $slot]]));

        expect($listed(['key' => 'tires']))->toContain('rules/model.json /slots/10/key')
            ->and($listed(['key' => 'spare', 'role' => 'tires']))->toContain('rules/model.json /slots/10/role')
            ->and(modelErrors(withModel(fn (array $model): array => [...$model, 'slots' => array_map(fn (array $slot): array => $slot['key'] === 'tires' ? ['key' => 'tires'] : $slot, $model['slots'])])))->toContain('rules/model.json /slots');
    });

    it('has the schema say what a slot, a role and a statistic are', function () {
        expect(modelErrors(withModel(fn (array $model): array => [...$model, 'slots' => [['key' => 'Hull!']]])))->toContain('rules/model.json /slots/0/key')
            ->and(modelErrors(withModel(fn (array $model): array => [...$model, 'slots' => [['key' => 'hull', 'role' => 'wheels']]])))->toContain('rules/model.json /slots/0/role')
            ->and(modelErrors(withModel(fn (array $model): array => [...$model, 'stats' => ['accel', 'luck']])))->toContain('rules/model.json /stats/1')
            ->and(modelErrors(withModel(fn (array $model): array => [...$model, 'stats' => ['accel', 'accel']])))->toContain('rules/model.json /stats')
            ->and(modelErrors(withModel(fn (array $model): array => [...$model, 'stats' => []])))->toContain('rules/model.json /stats');
    });

    it('wants the slot of a part to be one of the slots of the game, and what its role asks of the part', function () {
        $part = fn (string $slug, Closure $change): array => modelErrors(contentWith(["parts/{$slug}" => $change]));

        expect($part('sport-exhaust-t2', fn (array $p): array => [...$p, 'slot' => 'sails']))->toContain('parts/sport-exhaust-t2.json /slot')
            // The tires have a kind, and nothing else does.
            ->and($part('street-tires-t2', function (array $p): array {
                unset($p['tire_kind']);

                return $p;
            }))->toContain('parts/street-tires-t2.json ')
            ->and($part('sport-exhaust-t2', fn (array $p): array => [...$p, 'tire_kind' => 'street']))->toContain('parts/sport-exhaust-t2.json /tire_kind')
            // The engine has its layout and displacement, and nothing else has.
            ->and($part('engine-r4-2-0-t3', function (array $p): array {
                unset($p['engine']);

                return $p;
            }))->toContain('parts/engine-r4-2-0-t3.json ')
            ->and($part('sport-exhaust-t2', fn (array $p): array => [...$p, 'engine' => ['layout' => 'R4', 'displacement_l' => 2.0]]))->toContain('parts/sport-exhaust-t2.json /engine');
    });

    it('wants the rules that are by slot to name slots of the game, and to give the life and the time of a job for every one of them', function () {
        $rules = fn (Closure $change): array => modelErrors(contentWith(['rules/starter' => $change]));

        expect($rules(function (array $r): array {
            $r['wear']['life_km']['sails'] = 50_000;

            return $r;
        }))->toContain('rules/starter.json /wear/life_km/sails')
            ->and($rules(function (array $r): array {
                unset($r['workshop']['base_time_ms']['tank']);

                return $r;
            }))->toContain('rules/starter.json /workshop/base_time_ms')
            ->and($rules(function (array $r): array {
                $r['shop']['crates']['slots'][] = 'sails';

                return $r;
            }))->toContain('rules/starter.json /shop/crates/slots/'.count(repositoryDocument('rules/starter')['shop']['crates']['slots']))
            ->and(modelErrors(contentWith(['rules/economy' => function (array $e): array {
                $e['anchors']['parts']['slot_share']['sails'] = 0.1;

                return $e;
            }])))->toContain('rules/economy.json /anchors/parts/slot_share/sails');
    });

    it('wants the stock tires to be in the slot that is the tires', function () {
        $errors = modelErrors(contentWith(['rules/starter' => fn (array $rules): array => [...$rules, 'stock_tires' => 'sport-suspension-t1']]));

        expect($errors)->toContain('rules/starter.json /stock_tires');
    });

    it('warns about a slot that neither the app nor the vocabulary has a name for, and the vocabulary may name it', function () {
        $warnings = fn (ContentSource $content): array => array_column(PackValidator::fromConfig()->validateSource($content)->warnings(), 'pointer');
        $unnamed = renamedSlots(OTHER_GAME, named: false);

        expect($warnings($unnamed))->toContain('/slots/0/key', '/slots/3/key', '/slots/4/key')
            ->and(array_filter($warnings(renamedSlots(OTHER_GAME)), fn (string $pointer): bool => str_starts_with($pointer, '/slots')))->toBe([])
            // The hidden slot needs no name: the garage does not show it.
            ->and($warnings(repositoryContent()))->not->toContain('/slots/9/key');

        // A name for a slot that the model does not have is a message that the app does not have either.
        $stray = contentWith(['rules/vocabulary' => fn (array $v): array => [...$v, 'messages' => ['slot.hull' => ['en' => 'Hull', 'pl' => 'Kadłub']]]]);

        expect(modelErrors($stray))->toContain('rules/vocabulary.json /messages/slot.hull');
    });
});

describe('a game with other slots', function () {
    /** The parts that the test car has, by the key of the slot in the car game. */
    $fitted = ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2', 'engine' => 'engine-r4-2-0-t3', 'boost' => 'biturbo-parallel-t5'];
    $renamed = fn (array $parts): array => array_combine(array_map(fn (string $slot): string => OTHER_GAME[$slot] ?? $slot, array_keys($parts)), array_values($parts));

    it('drives as the car game does: the statistics, the lap, the tank and the wear are the same', function () use ($fitted, $renamed) {
        $carGame = inputsOf(repositoryContent());
        $otherGame = inputsOf(renamedSlots(OTHER_GAME));

        expect($otherGame->stats('rdzak-gt-4', $renamed($fitted))->stats)->toEqual($carGame->stats('rdzak-gt-4', $fitted)->stats)
            ->and($otherGame->power('rdzak-gt-4', $renamed($fitted)))->toBe($carGame->power('rdzak-gt-4', $fitted))
            ->and($otherGame->tankMl('rdzak-gt-4', $renamed($fitted)))->toBe($carGame->tankMl('rdzak-gt-4', $fitted));

        $lap = fn (RunInputsFactory $inputs, array $mounted): array => (new RunFreezer)->freeze($inputs->make('rdzak-gt-4', 'zlomowo-street', $mounted), 'model', 0)->config['lap_ms'];
        $wear = fn (RunInputsFactory $inputs, array $mounted): array => array_values($inputs->wearPlan('rdzak-gt-4', 'kruszyn-quarry', $mounted, ['mileage_m' => 0, 'serviced_at_m' => 0])->perKmPpb);

        expect($lap($otherGame, $renamed($fitted)))->toBe($lap($carGame, $fitted))
            // The load of the engine wears the parts of the engine group faster, and the gravel wears the tires: by the role of the slot, not by its key.
            ->and($wear($otherGame, $renamed($fitted)))->toBe($wear($carGame, $fitted));
    });

    it('does not take a part for tires because of the key of its slot', function () {
        // In the car game the key tires is the role of the tires; in a game where the role is the hull's, a slot called tires is a slot like any other.
        $content = renamedSlots(['tires' => 'hull', 'exhaust' => 'tires']);

        expect(Rules::fromSource($content)->model()->slotOf('tires'))->toBe('hull')
            ->and(Rules::fromSource($content)->model()->roleOf('tires'))->toBeNull();
        expect(modelErrors($content))->toBe([]);
    });

    it('is told to the clients: the model, and the slots of a garage with their roles', function () {
        contentPacks()->import(renamedSlots(OTHER_GAME), activate: true, checkTables: false);
        readFromDatabase();

        $model = $this->getJson('/api/v1/meta/model')->assertOk();

        expect(array_column($model->json('slots'), 'key'))->toBe(['hull', 'suspension', 'brakes', 'reactor', 'thrusters', 'gearbox', 'exhaust', 'cooling', 'tank', 'ecu'])
            ->and($model->json('slots.0'))->toBe(['key' => 'hull', 'role' => 'tires', 'hidden' => false])
            ->and($model->json('slots.9'))->toBe(['key' => 'ecu', 'role' => null, 'hidden' => true])
            ->and($model->json('stats'))->toBe(GameModel::STATS);

        [$driver] = driverWithRun();
        $slots = collect($this->getJson('/api/v1/garage', bearerFor($driver))->assertOk()->json('data.car.slots'))->keyBy('slot');

        expect($slots->keys()->all())->toBe(['hull', 'suspension', 'brakes', 'reactor', 'thrusters', 'gearbox', 'exhaust', 'cooling', 'tank'])
            ->and($slots['hull']['role'])->toBe('tires')
            ->and($slots['exhaust']['role'])->toBeNull()
            // The engine of the body is the one in the slot that is the engine, whatever it is called.
            ->and($slots['reactor']['built_in'])->toBeTrue()
            ->and($slots['hull']['built_in'])->toBeFalse();
    });

    it('is what a pack may not do to the players who have parts in a slot', function () {
        contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
        Part::factory()->create(['user_id' => User::factory()->guest()->create()->id, 'part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust']);

        $guards = new ReflectionMethod(ContentPublisher::class, 'guards');
        $judge = function (PackContent $candidate) use ($guards): PackValidationResult {
            $result = new PackValidationResult;
            $guards->invoke(app(ContentPublisher::class), $candidate, contentPacks()->activeSource(), $result);

            return $result;
        };

        // The same slot under another key takes the slot of the parts of the players out of the game.
        $taken = $judge(renamedSlots(['exhaust' => 'chimney']));
        $kept = $judge(renamedSlots(['tires' => 'hull']));

        expect(array_column($taken->errors(), 'message'))->toContain('It takes out the slot exhaust, which players have parts in (1 parts).')
            ->and(array_filter($kept->errors(), fn (array $issue): bool => $issue['file'] === 'rules/model.json'))->toHaveCount(0);
    });
});
