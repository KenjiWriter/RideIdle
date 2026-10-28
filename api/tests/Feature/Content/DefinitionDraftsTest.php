<?php

declare(strict_types=1);

use App\Content\ContentPackInvalid;
use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Car;
use App\Models\ContentDefinition;
use App\Models\Part;
use App\Models\User;
use App\Services\ContentDrafts;
use App\Services\DefinitionLifecycle;
use App\Services\DefinitionUsage;

// The drafts of the cars and of the parts, and the life of a definition: what an admin prepares, what is kept, and what a definition that is used may and may not
// become. The content of the repository is what is in the database.

function drafts(): ContentDrafts
{
    return app(ContentDrafts::class);
}

/** A car that is not in the pack: the Rdzak GT-4 under another slug. @return array<string, mixed> */
function aNewCar(string $slug = 'rdzak-gt-9'): array
{
    return [...repositoryDocument('vehicles/rdzak-gt-4'), 'slug' => $slug, 'name' => ['en' => 'Rdzak GT-9', 'pl' => 'Rdzak GT-9']];
}

function definitionOf(string $kind, string $slug): ContentDefinition
{
    return ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
}

beforeEach(fn () => contentPacks()->import(repositoryContent(), checkTables: false));

describe('what the forms hold', function () {
    it('makes the document of every car and part of the pack out of the state it is shown as, as it was written', function (string $kind, string $folder) {
        $keys = repositoryContent()->slugs($folder);

        expect($keys)->not->toBeEmpty();

        foreach ($keys as $slug) {
            $document = repositoryDocument("{$folder}/{$slug}");
            $made = DefinitionForm::toDocument($kind, DefinitionForm::toState($kind, $document));

            expect($made)->toEqual($document, "{$folder}/{$slug}")
                ->and(array_keys($made))->toBe(array_keys($document), "the order of the keys of {$slug}");

            // The statistics of a part are lines, and keep their order.
            if ($kind === 'part') {
                expect(array_keys($made['stats']))->toBe(array_keys($document['stats']), "the statistics of {$slug}");
            }
        }
    })->with([
        'cars' => ['vehicle', 'vehicles'],
        'parts' => ['part', 'parts'],
    ]);

    it('takes a price in dollars and keeps the cents, whole numbers as numbers, and a language with nothing in its box out of the document', function () {
        $document = DefinitionForm::toDocument('vehicle', [
            ...DefinitionForm::toState('vehicle', repositoryDocument('vehicles/rdzak-gt-4')),
            'price' => '21500.50',
            'power_kw' => '98',
            'engine_displacement_l' => '1,8',
            'name' => ['en' => ' Rdzak ', 'pl' => 'Rdzak', 'fr' => '', 'de' => null, 'pt-BR' => '  '],
        ]);

        expect($document['price_cents'])->toBe(2_150_050)
            ->and($document['power_kw'])->toBe(98)
            ->and($document['engine']['displacement_l'])->toBe(1.8)
            ->and($document['name'])->toBe(['en' => 'Rdzak', 'pl' => 'Rdzak']);
    });

    it('leaves out what a kind of car or of part does not have: the electric car has no tank, and a part outside the slot tires no kind of tires', function () {
        $electric = DefinitionForm::toDocument('vehicle', [...DefinitionForm::toState('vehicle', repositoryDocument('vehicles/rdzak-gt-4')), 'powertrain' => 'ev', 'engine_motors' => 2, 'battery_kwh' => 60, 'max_charge_kw' => 150]);
        $part = DefinitionForm::toDocument('part', [...DefinitionForm::toState('part', repositoryDocument('parts/street-tires-t2')), 'slot' => 'brakes']);

        expect($electric)->not->toHaveKeys(['tank_l'])->and($electric['engine'])->toBe(['motors' => 2])->and($electric['battery_kwh'])->toBe(60)
            ->and($part)->not->toHaveKey('tire_kind');
    });

    it('does not let a number that is not a number through as one, so that the schema says what is wrong', function () {
        $document = DefinitionForm::toDocument('vehicle', [...DefinitionForm::toState('vehicle', repositoryDocument('vehicles/rdzak-gt-4')), 'power_kw' => 'plenty', 'seats' => '4.5']);

        expect($document['power_kw'])->toBe('plenty')->and($document['seats'])->toBe(4.5)
            ->and(drafts()->validate('vehicle', $document)->isValid())->toBeFalse();
    });
});

describe('a new definition', function () {
    it('is a draft that is kept, with its slug for good, and written to the trail', function () {
        $admin = anAdmin();

        $definition = drafts()->create('vehicle', aNewCar(), $admin);

        expect($definition->status)->toBe(ContentDefinition::STATUS_DRAFT)
            ->and($definition->uid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
            ->and($definition->versions()->count())->toBe(0)
            ->and($definition->draftDocument()['slug'])->toBe('rdzak-gt-9')
            ->and($definition->draft_admin_id)->toBe($admin->id)
            ->and($definition->title('pl'))->toBe('Rdzak GT-9')
            ->and(AdminAuditLog::query()->where('action', 'definition_created')->value('subject'))->toBe('vehicle:rdzak-gt-9');
    });

    it('is not kept when the schema does not accept it, and nothing is made', function () {
        $document = aNewCar();
        $document['power_kw'] = 5;
        unset($document['tier']);

        try {
            drafts()->create('vehicle', $document, anAdmin());
            $this->fail('The document should have been refused.');
        } catch (ContentPackInvalid $problem) {
            expect(array_column($problem->result->errors(), 'message'))->not->toBeEmpty();
        }

        expect(ContentDefinition::query()->where('slug', 'rdzak-gt-9')->exists())->toBeFalse();
    });

    it('does not take a slug that a definition has, or had: not even an archived one', function () {
        $admin = anAdmin();

        expect(fn () => drafts()->create('vehicle', aNewCar('rdzak-gt-4'), $admin))->toThrow(DefinitionRefused::class);

        $old = definitionOf('vehicle', 'furgon-z-1');
        $old->forceFill(['status' => ContentDefinition::STATUS_ARCHIVED])->save();

        try {
            drafts()->create('vehicle', aNewCar('furgon-z-1'), $admin);
            $this->fail('The slug should have been refused.');
        } catch (DefinitionRefused $refusal) {
            expect($refusal->reason)->toBe('slug_taken')->and($refusal->params)->toBe(['slug' => 'furgon-z-1']);
        }
    });

    it('has a slug of the form of the schema, and a name in English and Polish', function () {
        $document = aNewCar('Not A Slug');
        $named = aNewCar();
        unset($named['name']['pl']);

        expect(drafts()->validate('vehicle', $document)->isValid())->toBeFalse()
            ->and(drafts()->validate('vehicle', $named)->isValid())->toBeFalse();
    });

    it('is deleted when it was never published, and is not when it was', function () {
        $admin = anAdmin();
        $new = drafts()->create('vehicle', aNewCar(), $admin);

        drafts()->delete($new, $admin);

        expect(ContentDefinition::query()->where('slug', 'rdzak-gt-9')->exists())->toBeFalse()
            ->and(AdminAuditLog::query()->where('action', 'definition_deleted')->count())->toBe(1)
            ->and(fn () => drafts()->delete(definitionOf('vehicle', 'rdzak-gt-4'), $admin))->toThrow(DefinitionRefused::class);
    });
});

describe('the draft of a definition that was published', function () {
    it('is kept next to the published document, which does not change', function () {
        $admin = anAdmin();
        $definition = definitionOf('vehicle', 'rdzak-gt-4');
        $document = [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 105];

        drafts()->save($definition, $document, $admin);
        $definition->refresh();

        expect($definition->hasDraft())->toBeTrue()
            ->and($definition->draftDocument()['power_kw'])->toBe(105)
            ->and($definition->publishedDocument()['power_kw'])->toBe(97)
            ->and($definition->workingDocument()['power_kw'])->toBe(105)
            ->and($definition->status)->toBe(ContentDefinition::STATUS_ACTIVE)
            ->and($definition->versions()->count())->toBe(1)
            ->and(AdminAuditLog::query()->where('action', 'definition_draft_saved')->first()->context)->toBe(['changed' => ['power_kw']]);
    });

    it('is no draft at all when it says what the published document says, and clears a draft that was there', function () {
        $admin = anAdmin();
        $definition = definitionOf('vehicle', 'rdzak-gt-4');

        drafts()->save($definition, [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 105], $admin);
        expect($definition->refresh()->hasDraft())->toBeTrue();

        // 97 written as 97.0 is the same number.
        drafts()->save($definition, [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 97.0], $admin);

        expect($definition->refresh()->hasDraft())->toBeFalse()
            ->and(AdminAuditLog::query()->where('action', 'definition_draft_discarded')->count())->toBe(1);
    });

    it('is not kept when it does not pass, and the draft that was there stays', function () {
        $admin = anAdmin();
        $definition = definitionOf('vehicle', 'rdzak-gt-4');
        drafts()->save($definition, [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 105], $admin);

        expect(fn () => drafts()->save($definition, [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 99999], $admin))->toThrow(ContentPackInvalid::class);

        expect($definition->refresh()->draftDocument()['power_kw'])->toBe(105);
    });

    it('cannot change its slug', function () {
        $definition = definitionOf('vehicle', 'rdzak-gt-4');

        try {
            drafts()->save($definition, aNewCar('rdzak-gt-44'), anAdmin());
            $this->fail('The slug should have been refused.');
        } catch (ContentPackInvalid $problem) {
            expect(array_column($problem->result->errors(), 'pointer'))->toContain('/slug');
        }
    });

    it('is thrown away to go back to the published document, and a definition that was never published has none to go back to', function () {
        $admin = anAdmin();
        $definition = definitionOf('vehicle', 'rdzak-gt-4');
        drafts()->save($definition, [...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 105], $admin);

        drafts()->discard($definition, $admin);

        expect($definition->refresh()->hasDraft())->toBeFalse()->and($definition->workingDocument()['power_kw'])->toBe(97)
            ->and(fn () => drafts()->discard(drafts()->create('vehicle', aNewCar(), $admin), $admin))->toThrow(DefinitionRefused::class);
    });

    it('is not made for an archived definition, which is put back first', function () {
        $definition = definitionOf('part', 'street-tires-t2');
        $definition->forceFill(['status' => ContentDefinition::STATUS_ARCHIVED])->save();

        expect(fn () => drafts()->save($definition, repositoryDocument('parts/street-tires-t2'), anAdmin()))->toThrow(DefinitionRefused::class);
    });

    it('warns about a language that is missing and does not refuse for it', function () {
        $document = repositoryDocument('vehicles/rdzak-gt-4');
        unset($document['name']['de'], $document['description']['fr']);

        $result = drafts()->validate('vehicle', $document, 'rdzak-gt-4');

        expect($result->isValid())->toBeTrue()
            ->and(array_column($result->warnings(), 'pointer'))->toBe(['/name/de', '/description/fr']);
    });
});

describe('what uses a definition', function () {
    it('is the cars and the runs of the players for a car, and the parts of the players for a part', function () {
        $driver = User::factory()->guest()->create();
        Car::factory()->create(['user_id' => $driver->id, 'vehicle_slug' => 'furgon-z-1']);
        Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);

        $usage = app(DefinitionUsage::class);

        expect($usage->instances(definitionOf('vehicle', 'furgon-z-1')))->toBe(['cars' => 1])
            ->and($usage->instances(definitionOf('vehicle', 'furgon-z-3')))->toBe([])
            ->and($usage->instances(definitionOf('part', 'street-tires-t2')))->toBe(['parts' => 1]);
    });

    it('is also the places of the pack that is live that name it: the stock tires, the shop, the wreck prices and the gift of the tutorial', function () {
        $usage = app(DefinitionUsage::class);

        expect($usage->references(definitionOf('part', 'street-tires-t1')))->toContain('stock_tires')
            ->and($usage->references(definitionOf('part', 'street-tires-t2')))->toContain('shop.parts')
            ->and($usage->references(definitionOf('vehicle', 'rdzak-gt-4')))->toBe(['garage.wreck_price_cents', 'garage.wreck_mileage_km'])
            ->and($usage->references(definitionOf('vehicle', 'orzel-v12')))->toBe([]);

        $gifts = collect(repositoryDocument('rules/tutorial')['steps'])->filter(fn (array $step): bool => isset($step['reward']['part']))->first();

        expect($usage->references(definitionOf('part', $gifts['reward']['part'])))->toContain("tutorial.{$gifts['key']}");
    });
});

describe('the life of a definition', function () {
    it('goes from on offer to withdrawn and back, and not to archived in one step', function () {
        $admin = anAdmin(['role' => 'publisher']);
        $life = app(DefinitionLifecycle::class);
        $definition = definitionOf('vehicle', 'orzel-v12');

        $life->withdraw($definition, $admin);
        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_WITHDRAWN);

        $life->restore($definition, $admin);
        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_ACTIVE)
            ->and(fn () => $life->archive($definition, $admin))->toThrow(DefinitionRefused::class);

        expect(AdminAuditLog::query()->where('action', 'definition_status_changed')->orderBy('id')->pluck('context')->all())->toBe([
            ['from' => 'active', 'to' => 'withdrawn'],
            ['from' => 'withdrawn', 'to' => 'active'],
        ]);
    });

    it('is archived only when nothing uses it, in the content or among the players, and can come back', function () {
        $admin = anAdmin(['role' => 'publisher']);
        $life = app(DefinitionLifecycle::class);
        $driver = User::factory()->guest()->create();
        Car::factory()->create(['user_id' => $driver->id, 'vehicle_slug' => 'orzel-v12']);

        $used = definitionOf('vehicle', 'orzel-v12');
        $life->withdraw($used, $admin);

        try {
            $life->archive($used, $admin);
            $this->fail('A car in use should not be archived.');
        } catch (DefinitionRefused $refusal) {
            expect($refusal->reason)->toBe('in_use')->and($refusal->params['what'])->toBe('1 cars');
        }

        $named = definitionOf('vehicle', 'rdzak-gt-4');
        $life->withdraw($named, $admin);

        expect(fn () => $life->archive($named, $admin))->toThrow(DefinitionRefused::class, 'referenced');

        $free = definitionOf('vehicle', 'furgon-z-5');
        $life->withdraw($free, $admin);
        $life->archive($free, $admin);

        expect($free->refresh()->status)->toBe(ContentDefinition::STATUS_ARCHIVED);

        $life->restore($free, $admin);

        expect($free->refresh()->status)->toBe(ContentDefinition::STATUS_ACTIVE);
    });

    it('is moved by a publisher and by nobody else, and only a definition that was published', function () {
        $life = app(DefinitionLifecycle::class);
        $editor = anAdmin();
        $publisher = anAdmin(['email' => 'pub@example.com', 'role' => 'publisher']);
        $new = drafts()->create('vehicle', aNewCar(), $editor);

        try {
            $life->withdraw(definitionOf('vehicle', 'orzel-v12'), $editor);
            $this->fail('An editor should not move a definition.');
        } catch (DefinitionRefused $refusal) {
            expect($refusal->reason)->toBe('not_publisher');
        }

        expect(fn () => $life->withdraw($new, $publisher))->toThrow(DefinitionRefused::class, 'unpublished')
            ->and(definitionOf('vehicle', 'orzel-v12')->status)->toBe(ContentDefinition::STATUS_ACTIVE);
    });
});

it('makes a definition of what a pack imports an active one, with no draft', function () {
    $definition = definitionOf('part', 'street-tires-t2');

    expect($definition->status)->toBe(ContentDefinition::STATUS_ACTIVE)->and($definition->hasDraft())->toBeFalse()
        ->and($definition->title('pl'))->toBe('Opony uliczne')
        ->and($definition->title('xx'))->toBe('Street tires')
        ->and(Admin::query()->count())->toBe(0);
});
