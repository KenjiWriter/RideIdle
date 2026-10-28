<?php

declare(strict_types=1);

use App\Content\BotProfiles;
use App\Content\BotProfileTable;
use App\Content\Catalog;
use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\ContentSource;
use App\Content\FileContentSource;
use App\Content\PackContent;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Models\ContentActivation;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use App\Models\ContentVersion;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\ContentExporter;
use App\Services\RunService;
use Illuminate\Support\Facades\File;

// A pack is made of the content of a source, once: a definition keeps its versions, a pack names one version of each, and the one that was made live last is what
// the game reads. These tests use the real content of the repository, and leave the table of the bot profiles alone (checkTables: false) unless they are about it.

describe('making a pack', function () {
    it('makes a definition of every document, with one version and a ULID, and a pack that names them all', function () {
        $import = contentPacks()->import(repositoryContent(), checkTables: false);

        expect($import->created)->toBeTrue()
            ->and($import->pack->number)->toBe('2026.10.0')
            ->and($import->pack->checksum)->toBe(repositoryContent()->checksum())
            ->and($import->newVersions)->toBe(105)
            ->and(ContentDefinition::query()->count())->toBe(105)
            ->and(ContentVersion::query()->count())->toBe(105)
            ->and($import->pack->versions()->count())->toBe(105)
            ->and(ContentDefinition::query()->where('kind', 'vehicle')->count())->toBe(23)
            ->and(ContentDefinition::query()->where('kind', 'rules')->pluck('slug')->all())->toEqualCanonicalizing(['economy', 'starter', 'tutorial', 'vocabulary'])
            ->and(ContentDefinition::query()->first()->uid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
            ->and(json_decode($import->pack->manifest, true)['name'])->toBe('core');
    });

    it('keeps the text of a document as it was written, so that what comes out is what went in', function () {
        contentPacks()->import(repositoryContent(), checkTables: false);

        $version = ContentVersion::query()->whereHas('definition', fn ($query) => $query->where('slug', 'starter'))->firstOrFail();

        expect($version->document)->toBe(repositoryContent()->raw('rules/starter'));
    });

    it('gives the same pack back for the same content, and refuses a number that other content already has', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false);
        $again = contentPacks()->import(repositoryContent(), checkTables: false);

        expect($again->created)->toBeFalse()
            ->and($again->pack->id)->toBe($first->pack->id)
            ->and(ContentPack::query()->count())->toBe(1)
            ->and(fn () => contentPacks()->import(contentWith(stronger()), checkTables: false))->toThrow(ContentException::class, 'already exists with other content');
    });

    it('shares what did not change: a new pack adds a version only to the definitions that differ', function () {
        contentPacks()->import(repositoryContent(), checkTables: false);
        $second = contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false);

        expect($second->created)->toBeTrue()
            ->and($second->newVersions)->toBe(1)
            ->and(ContentVersion::query()->count())->toBe(106)
            ->and(ContentDefinition::query()->count())->toBe(105)
            ->and($second->pack->versions()->count())->toBe(105)
            ->and(ContentVersion::query()->whereHas('definition', fn ($query) => $query->where('slug', 'rdzak-gt-4'))->orderBy('number')->pluck('number')->all())->toBe([1, 2]);
    });

    it('does not make a new version of a document that comes back to what it was, but names the old one', function () {
        contentPacks()->import(repositoryContent(), checkTables: false);
        contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false);
        $third = contentPacks()->import(repositoryContent(), '2026.10.2', checkTables: false);

        expect($third->created)->toBeTrue()
            ->and($third->newVersions)->toBe(0)
            ->and(ContentVersion::query()->count())->toBe(106)
            // The same content under another number is another pack with the same checksum.
            ->and($third->pack->checksum)->toBe(ContentPack::query()->where('number', '2026.10.0')->value('checksum'));
    });

    it('takes the number from pack.json, or from the one it is given, and only a number of the form year.month.n', function () {
        $named = contentPacks()->import(repositoryContent(), '2027.1.12', checkTables: false);

        expect($named->pack->number)->toBe('2027.1.12')
            ->and(fn () => contentPacks()->import(repositoryContent(), 'next', checkTables: false))->toThrow(ContentException::class, 'not a number of a pack');
    });

    it('refuses content that is not valid, and writes nothing', function () {
        $broken = contentWith(['rules/starter' => fn (array $rules): array => [...$rules, 'stock_tires' => 'tires-from-nowhere']]);

        try {
            contentPacks()->import($broken, checkTables: false);
            $this->fail('The content should have been refused.');
        } catch (ContentPackInvalid $problem) {
            expect(array_column($problem->result->errors(), 'pointer'))->toContain('/stock_tires');
        }

        expect(ContentPack::query()->count())->toBe(0)
            ->and(ContentDefinition::query()->count())->toBe(0);
    });

    it('refuses a pack whose pack.json states a checksum that is not the one of its content: it was changed after it was exported', function () {
        $documents = repositoryDocuments();
        $documents['pack'] = json_encode([...json_decode($documents['pack'], true), 'checksum' => str_repeat('0', 64)]);

        expect(fn () => contentPacks()->import(new PackContent('2026.10.0', $documents), checkTables: false))->toThrow(ContentException::class, 'changed after it was exported');
    });

    it('checks the table of the bot profiles against what this content makes, and refuses a table that is stale', function () {
        // The Rdzak with 150 kW is quicker than the table says, so the bots would drive around a lap the content does not make.
        $stale = contentWith(stronger());

        try {
            contentPacks()->import($stale);
            $this->fail('The stale table should have been refused.');
        } catch (ContentPackInvalid $problem) {
            expect(array_column($problem->result->errors(), 'file'))->toBe(['tables/bot-profiles.json']);
        }

        // With the table made for it, the same content goes in.
        $catalog = Catalog::fromSource($stale);
        $rules = Rules::fromSource($stale);
        $table = (new BotProfileTable($catalog, $rules, new RunInputsFactory($catalog, $rules)))->computedJson();
        $documents = [...repositoryDocuments(), 'tables/bot-profiles' => $table];
        $documents['vehicles/rdzak-gt-4'] = (string) $stale->raw('vehicles/rdzak-gt-4');

        expect(contentPacks()->import(new PackContent('2026.10.0', $documents), '2026.10.1')->created)->toBeTrue()
            // The table of the repository is the one its content makes.
            ->and(contentPacks()->import(repositoryContent())->created)->toBeTrue();
    });
});

describe('the pack that is live', function () {
    it('is none until one is made live, and then the last one, and going back is making an earlier one live', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        $second = contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false)->pack;

        expect(contentPacks()->active())->toBeNull()
            ->and(contentPacks()->activate($first, 'the first', checked: true))->toBeTrue()
            ->and(contentPacks()->active()->number)->toBe('2026.10.0')
            // The Rdzak of the second pack is stronger than the table of the bot profiles in it says, so only a pack that was checked goes live.
            ->and(fn () => contentPacks()->activate($second))->toThrow(ContentPackInvalid::class)
            ->and(contentPacks()->activate($second, checked: true))->toBeTrue()
            ->and(contentPacks()->active()->number)->toBe('2026.10.1')
            ->and(contentPacks()->activate($second))->toBeFalse()
            ->and(contentPacks()->activate($first, 'back'))->toBeTrue()
            ->and(contentPacks()->active()->number)->toBe('2026.10.0')
            // Nothing is overwritten: three rows say what happened, in order.
            ->and(ContentActivation::query()->orderBy('id')->pluck('reason', 'content_pack_id')->all())->toBe([$first->id => 'back', $second->id => null]);
    });

    it('is made live with the import when it is asked for', function () {
        $import = contentPacks()->import(repositoryContent(), reason: 'installed', activate: true, checkTables: false);

        expect(contentPacks()->active()->id)->toBe($import->pack->id)
            ->and(ContentActivation::query()->value('reason'))->toBe('installed');
    });

    it('has the documents it was made with, from the database, and the number it is called', function () {
        $pack = contentPacks()->import(contentWith(stronger()), '2026.10.4', activate: true, checkTables: false)->pack;
        $source = contentPacks()->activeSource();

        expect($source->rulesetVersion())->toBe('2026.10.4')
            ->and($source->document('vehicles/rdzak-gt-4')['power_kw'])->toBe(150)
            ->and($source->checksum())->toBe($pack->checksum)
            ->and($source->keys())->toBe(contentWith(stronger())->keys())
            ->and($source->raw('pack'))->toBe($pack->manifest);
    });

    it('is not made live when this version of the game does not accept it, as a pack of an older game would not be', function () {
        $pack = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        // What an older game let in and this one does not: the document of the rules points to tires that are not there.
        $rules = json_decode((string) repositoryContent()->raw('rules/starter'), true);
        ContentVersion::query()->whereHas('definition', fn ($query) => $query->where('slug', 'starter'))
            ->update(['document' => json_encode([...$rules, 'stock_tires' => 'tires-from-nowhere'])]);

        expect(fn () => contentPacks()->activate($pack))->toThrow(ContentPackInvalid::class)
            ->and(contentPacks()->active())->toBeNull();
    });
});

describe('the game reading from the database', function () {
    it('reads the cars, the parts, the rules and the steps of the tutorial of the live pack, and the version it is called', function () {
        contentPacks()->import(contentWith(stronger()), '2026.10.4', activate: true, checkTables: false);
        readFromDatabase();

        expect(app(ContentSource::class))->toBeInstanceOf(PackContent::class)
            ->and(app(Catalog::class)->vehicle('rdzak-gt-4')['power_kw'])->toBe(150)
            ->and(app(Catalog::class)->vehicles())->toHaveCount(23)
            ->and(app(Catalog::class)->parts())->toHaveCount(74)
            ->and(app(Rules::class)->stockTires())->toBe('street-tires-t1')
            ->and(app(TutorialSteps::class)->all()[0]['key'])->toBe('wreck')
            ->and(app(BotProfiles::class)->referenceMs('zlomowo-street', 2))->toBeInt();
    });

    it('says what is wrong when no pack is live, instead of reading from nowhere', function () {
        readFromDatabase();

        expect(fn () => app(Rules::class))->toThrow(ContentException::class, 'No content pack is live');
    });

    it('is the pack that is live now each time the container is asked again: a worker that lives for hours sees a pack made live in between', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        $second = contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false)->pack;
        contentPacks()->activate($first, checked: true);
        readFromDatabase();

        expect(app(Catalog::class)->vehicle('rdzak-gt-4')['power_kw'])->toBe(97);

        contentPacks()->activate($second, checked: true);
        // Within one request nothing changes under the code that has read the content.
        expect(app(Catalog::class)->vehicle('rdzak-gt-4')['power_kw'])->toBe(97);

        app()->forgetScopedInstances();

        expect(app(Catalog::class)->vehicle('rdzak-gt-4')['power_kw'])->toBe(150);

        contentPacks()->activate($first, 'back', checked: true);
        app()->forgetScopedInstances();

        expect(app(Catalog::class)->vehicle('rdzak-gt-4')['power_kw'])->toBe(97);
    });

    it('freezes a car of the pack that is live, so that a car added to the content is one that can race', function () {
        $cloned = contentWith([
            'vehicles/rdzak-gt-9' => fn (array $unused): array => [...json_decode((string) repositoryContent()->raw('vehicles/rdzak-gt-4'), true), 'slug' => 'rdzak-gt-9', 'sources' => ['exchange']],
        ]);
        contentPacks()->import($cloned, '2026.10.1', activate: true, checkTables: false);
        readFromDatabase();

        $inputs = app(RunInputsFactory::class)->make('rdzak-gt-9', 'zlomowo-street');

        expect($inputs->car->stats['accel'])->toBe(app(RunInputsFactory::class)->make('rdzak-gt-4', 'zlomowo-street')->car->stats['accel'])
            ->and(app(Catalog::class)->vehicles())->toHaveCount(24);
    });
});

describe('a run when the pack that is live changes', function () {
    it('is settled with the lap it was frozen with, up to now, and frozen again for the new content from there', function () {
        contentPacks()->import(repositoryContent(), checkTables: false);
        contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false);
        contentPacks()->activate(ContentPack::query()->where('number', '2026.10.0')->firstOrFail(), checked: true);
        readFromDatabase();

        $driver = User::factory()->guest()->create();
        $t0 = RUN_T0;
        $run = app(RunService::class)->start($driver->id, 'rdzak-gt-4', 'zlomowo-street', app(RunInputsFactory::class)->make('rdzak-gt-4', 'zlomowo-street'), 45_000, $t0);
        // The scripted breakdown and the small tank of the first run of a driver are not what this is about.
        $run->forceFill(['break_after_laps' => null, 'fuel_ml' => 45_000])->save();

        expect($run->ruleset_version)->toBe('2026.10.0')
            ->and($run->config['lap_ms']['dry'])->toBe(75_000);

        contentPacks()->activate(ContentPack::query()->where('number', '2026.10.1')->firstOrFail(), checked: true);
        app()->forgetScopedInstances();

        // 29 minutes and 20 seconds later: 23 laps of 75 s, which is what the car was driving, and not the 29 laps that the new lap would give from the start.
        app(RunService::class)->settle($run->id, $t0 + 1_760_000);
        $fresh = $run->fresh();

        expect($fresh->laps_done)->toBe(23)
            ->and($fresh->ruleset_version)->toBe('2026.10.1')
            ->and($fresh->config['lap_ms']['dry'])->toBeLessThan(75_000);

        // From there it drives the new lap (with a full tank, because the stronger car burns more): ten minutes are more than the 8 laps of the old one.
        $fresh->forceFill(['fuel_ml' => 45_000])->save();
        app(RunService::class)->settle($run->id, $t0 + 1_760_000 + 600_000);

        expect($run->fresh()->laps_done - 23)->toBeGreaterThan(8);
    });

    it('keeps the lap of a run whose car is not in the new content, and does not try again at every settlement', function () {
        contentPacks()->import(repositoryContent(), checkTables: false);
        // The Kuna is gone from the content, and so is the price of its wreck.
        $withoutKuna = contentWith(['rules/starter' => function (array $rules): array {
            unset($rules['garage']['wreck_price_cents']['kuna-4x4'], $rules['garage']['wreck_mileage_km']['kuna-4x4']);

            return $rules;
        }], removed: ['vehicles/kuna-4x4']);
        contentPacks()->import($withoutKuna, '2026.10.1', checkTables: false);
        contentPacks()->activate(ContentPack::query()->where('number', '2026.10.0')->firstOrFail(), checked: true);
        readFromDatabase();

        $driver = User::factory()->guest()->create();
        $run = app(RunService::class)->start($driver->id, 'rdzak-gt-4', 'zlomowo-street', app(RunInputsFactory::class)->make('rdzak-gt-4', 'zlomowo-street'), 45_000, RUN_T0);
        $run->forceFill(['break_after_laps' => null, 'fuel_ml' => 45_000, 'car_slug' => 'kuna-4x4', 'car_id' => null])->save();
        $before = $run->fresh()->config;

        contentPacks()->activate(ContentPack::query()->where('number', '2026.10.1')->firstOrFail(), checked: true);
        app()->forgetScopedInstances();
        app(RunService::class)->settle($run->id, RUN_T0 + 610_000);
        $fresh = $run->fresh();

        expect($fresh->laps_done)->toBe(8)
            ->and($fresh->ruleset_version)->toBe('2026.10.1')
            ->and($fresh->config)->toBe($before);
    });

    it('does nothing to a run while the content is read from files, whatever the version of its row says', function () {
        $driver = User::factory()->guest()->create();
        $run = app(RunService::class)->start($driver->id, 'rdzak-gt-4', 'zlomowo-street', app(RunInputsFactory::class)->make('rdzak-gt-4', 'zlomowo-street'), 45_000, RUN_T0);
        $run->forceFill(['break_after_laps' => null, 'ruleset_version' => 'an-older-one'])->save();
        $before = $run->fresh()->config;

        app(RunService::class)->settle($run->id, RUN_T0 + 610_000);

        // The version of the files is the one of the configuration, which is not what the row says, so the run is frozen again, for the files, which have not changed.
        expect($run->fresh()->ruleset_version)->toBe('dev')
            ->and($run->fresh()->config['lap_ms'])->toBe($before['lap_ms'])
            ->and(RaceRun::query()->count())->toBe(1);
    });
});

describe('writing a pack out and taking it in', function () {
    it('writes the tree of the content as it was read, with the number and the checksum in pack.json, and nothing else changed', function () {
        $pack = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        $directory = sys_get_temp_dir().'/ride-export-'.bin2hex(random_bytes(4));

        try {
            $files = app(ContentExporter::class)->write(contentPacks()->sourceOf($pack), $directory, $pack->number);

            $manifest = json_decode((string) file_get_contents("{$directory}/packs/core/pack.json"), true);

            expect($files)->toBe(106)
                ->and($manifest['version'])->toBe('2026.10.0')
                ->and($manifest['checksum'])->toBe($pack->checksum)
                ->and(file_get_contents("{$directory}/packs/core/vehicles/rdzak-gt-4.json"))->toBe(file_get_contents(config('ride.content_path').'/packs/core/vehicles/rdzak-gt-4.json'))
                ->and(file_get_contents("{$directory}/rules/starter.json"))->toBe(file_get_contents(config('ride.content_path').'/rules/starter.json'))
                ->and(file_get_contents("{$directory}/tables/bot-profiles.json"))->toBe(file_get_contents(config('ride.content_path').'/tables/bot-profiles.json'));

            // Taken in on another machine (an empty database), it is the same pack.
            $source = new FileContentSource($directory);

            expect($source->checksum())->toBe($pack->checksum)
                ->and(contentPacks()->validate($source, checkTables: false)->isValid())->toBeTrue();
        } finally {
            File::deleteDirectory($directory);
        }
    });

    it('is the same pack when it comes back, and refused when a document was changed on the way', function () {
        $pack = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        $directory = sys_get_temp_dir().'/ride-export-'.bin2hex(random_bytes(4));

        try {
            app(ContentExporter::class)->write(contentPacks()->sourceOf($pack), $directory, $pack->number);
            $source = new FileContentSource($directory);

            expect(contentPacks()->import($source, checkTables: false)->created)->toBeFalse();

            $path = "{$directory}/packs/core/vehicles/rdzak-gt-4.json";
            $vehicle = json_decode((string) file_get_contents($path), true);
            $vehicle['power_kw'] = 98;
            file_put_contents($path, json_encode($vehicle, JSON_PRETTY_PRINT));

            expect(fn () => contentPacks()->import(new FileContentSource($directory), checkTables: false))->toThrow(ContentException::class, 'changed after it was exported');
        } finally {
            File::deleteDirectory($directory);
        }
    });

    it('does not write over a folder that has something in it, unless it is told to', function () {
        $pack = contentPacks()->import(repositoryContent(), checkTables: false)->pack;
        $directory = sys_get_temp_dir().'/ride-export-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/keep.txt", 'mine');

        try {
            expect(fn () => app(ContentExporter::class)->write(contentPacks()->sourceOf($pack), $directory, $pack->number))->toThrow(ContentException::class, 'is not empty')
                ->and(app(ContentExporter::class)->write(contentPacks()->sourceOf($pack), $directory, $pack->number, overwrite: true))->toBe(106)
                ->and(file_get_contents("{$directory}/keep.txt"))->toBe('mine');
        } finally {
            File::deleteDirectory($directory);
        }
    });
});
