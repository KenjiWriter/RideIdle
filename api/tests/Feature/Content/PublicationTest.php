<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Definitions\JsonDocument;
use App\Content\Publishing\DerivedTables;
use App\Content\Publishing\PublicationRefused;
use App\Content\Rules;
use App\Games\CurrentGame;
use App\Jobs\BuildContentPack;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use App\Models\ContentPublication;
use App\Models\Game;
use App\Models\Part;
use App\Models\User;
use App\Services\ContentDrafts;
use App\Services\ContentPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The way from the drafts to a pack that is live: a pack is asked for, built out of the content as it is being worked on, and made live. It is built only when
// nothing that players use is taken out of it, it validates, its derived tables are made again and the gates of the simulators pass on it.

function publisher(): ContentPublisher
{
    return app(ContentPublisher::class);
}

function aPublisher(): Admin
{
    return Admin::query()->where('email', 'publisher@example.com')->first() ?? anAdmin(['email' => 'publisher@example.com', 'role' => Admin::ROLE_PUBLISHER]);
}

/** Saves a draft of a document of the repository, changed by a function. */
function draftOf(string $key, Closure $change, ?Admin $admin = null): ContentDefinition
{
    [$folder, $slug] = explode('/', $key);
    $kind = ['vehicles' => 'vehicle', 'parts' => 'part', 'rules' => 'rules'][$folder];
    $definition = ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->firstOrFail();

    $editor = Admin::query()->where('email', 'editor@example.com')->first() ?? anAdmin(['email' => 'editor@example.com']);

    return app(ContentDrafts::class)->save($definition, $change(JsonDocument::decode((string) repositoryContent()->raw($key))), $admin ?? $editor);
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
});

describe('what a pack made now would change', function () {
    it('is nothing when there are no drafts, and a pack is not asked for', function () {
        Queue::fake();

        expect(publisher()->changes())->toBe([])
            ->and(fn () => publisher()->queue(aPublisher()))->toThrow(PublicationRefused::class);

        Queue::assertNothingPushed();
    });

    it('lists the documents that a draft changes, the ones that are new, and the cars and parts that are withdrawn', function () {
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);
        ContentDefinition::query()->where('slug', 'street-tires-t2')->update(['status' => ContentDefinition::STATUS_WITHDRAWN]);

        expect(publisher()->changes())->toBe([['key' => 'vehicles/rdzak-gt-4', 'change' => 'changed'], ['key' => 'street-tires-t2', 'change' => 'withdrawn']]);
    });
});

describe('asking for a pack', function () {
    it('queues a job and keeps the number of the pack, which is the next of the month and is not used again', function () {
        Queue::fake();
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);
        $admin = aPublisher();

        $publication = publisher()->queue($admin, 'a dearer Rdzak');

        Queue::assertPushed(BuildContentPack::class, fn (BuildContentPack $job): bool => $job->publicationId === $publication->id);

        expect($publication->status)->toBe(ContentPublication::STATUS_QUEUED)
            ->and($publication->number)->toBe(now()->year.'.'.now()->month.'.1')
            ->and($publication->note)->toBe('a dearer Rdzak')
            ->and($publication->requested_by)->toBe($admin->id)
            ->and(AdminAuditLog::query()->where('action', 'content_publication_queued')->value('subject'))->toBe("content_publication:{$publication->number}");
    });

    it('is refused while another one is waiting or being made', function () {
        Queue::fake();
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);
        publisher()->queue(aPublisher());

        expect(fn () => publisher()->queue(aPublisher()))->toThrow(PublicationRefused::class, 'busy');
    });
});

describe('a pack that is built', function () {
    it('holds the drafts, is ready and is not live until a publisher makes it so', function () {
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);
        $live = contentPacks()->active();

        $publication = publisher()->queue(aPublisher())->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_READY)
            ->and($publication->pack)->not->toBeNull()
            ->and($publication->pack->number)->toBe($publication->number)
            ->and($publication->report['failed_step'])->toBeNull()
            ->and(array_column($publication->report['steps'], 'key'))->toBe(['collect', 'guards', 'validate', 'tables', 'gates', 'make'])
            ->and(array_column($publication->report['gates'], 'passed'))->toBe([true, true])
            ->and($publication->report['changes'])->toBe([['key' => 'vehicles/rdzak-gt-4', 'change' => 'changed']])
            ->and(contentPacks()->active()->id)->toBe($live->id)
            ->and(JsonDocument::decode((string) contentPacks()->sourceOf($publication->pack)->raw('vehicles/rdzak-gt-4'))['price_cents'])->toBe(repositoryDocument('vehicles/rdzak-gt-4')['price_cents'] + 100_000)
            ->and(JsonDocument::decode((string) contentPacks()->activeSource()->raw('vehicles/rdzak-gt-4'))['price_cents'])->toBe(repositoryDocument('vehicles/rdzak-gt-4')['price_cents'])
            ->and(AdminAuditLog::query()->where('action', 'content_publication_ready')->count())->toBe(1);
    });

    it('is the manifest of the live pack under its own number, and its checksum is of its content', function () {
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);

        $pack = publisher()->queue(aPublisher())->refresh()->pack;
        $manifest = json_decode($pack->manifest, true);

        expect($manifest['version'])->toBe($pack->number)->and($manifest['name'])->toBe('core')->and($manifest)->not->toHaveKey('withdrawn')
            ->and(contentPacks()->sourceOf($pack)->checksum())->toBe($pack->checksum);
    });

    it('makes the derived tables again, and they are the ones the pack makes', function () {
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);

        $publication = publisher()->queue(aPublisher())->refresh();

        // The price of a car does not change the lap of any car, so the bots and the pace of the rarities are what they were, but a unit pays back by the value of
        // the cars in it. The pack goes through the check of the bot table when it is made.
        expect($publication->report['tables'])->toBe(['tables/bot-profiles' => 'same', 'tables/rarity-pace' => 'same', 'tables/unit-returns' => 'changed']);
    });

    it('makes a new car, written only as a draft, a car of the game when the pack is live', function () {
        $car = repositoryDocument('vehicles/rdzak-gt-4');
        $admin = aPublisher();
        app(ContentDrafts::class)->create('vehicle', [...$car, 'slug' => 'rdzak-gt-9', 'name' => ['en' => 'Rdzak GT-9', 'pl' => 'Rdzak GT-9'], 'sources' => ['dealer'], 'min_player_level' => $car['min_player_level']], $admin);

        $definition = ContentDefinition::query()->where('slug', 'rdzak-gt-9')->firstOrFail();
        $pack = publisher()->queue($admin)->refresh()->pack;

        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_DRAFT)->and($definition->hasDraft())->toBeTrue();

        publisher()->activate($pack, $admin);
        readFromDatabase();

        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_ACTIVE)
            ->and($definition->hasDraft())->toBeFalse()
            ->and(Catalog::fromConfig()->vehicle('rdzak-gt-9')['name']['pl'])->toBe('Rdzak GT-9')
            ->and(contentPacks()->active()->id)->toBe($pack->id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->value('subject'))->toBe("content_pack:{$pack->number}");
    });

    it('lets a draft that was written after it was made stay a draft when it goes live', function () {
        $admin = aPublisher();
        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);
        $pack = publisher()->queue($admin)->refresh()->pack;

        draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 200_000]);
        publisher()->activate($pack, $admin);

        $definition = ContentDefinition::query()->where('slug', 'rdzak-gt-4')->firstOrFail();

        expect($definition->hasDraft())->toBeTrue()->and($definition->draftDocument()['price_cents'])->toBe(repositoryDocument('vehicles/rdzak-gt-4')['price_cents'] + 200_000);
    });
});

describe('a withdrawn car or part', function () {
    it('stays in the pack and leaves the offer: the shop has no part and the market no car', function () {
        $admin = aPublisher();
        ContentDefinition::query()->whereIn('slug', ['street-tires-t2', 'rdzak-gt-2'])->update(['status' => ContentDefinition::STATUS_WITHDRAWN]);

        $pack = publisher()->queue($admin)->refresh()->pack;
        $source = contentPacks()->sourceOf($pack);

        expect(json_decode($pack->manifest, true)['withdrawn'])->toBe(['rdzak-gt-2', 'street-tires-t2'])
            ->and($source->has('parts/street-tires-t2'))->toBeTrue()
            ->and(Rules::fromSource($source)->shopParts())->not->toContain('street-tires-t2')
            ->and(Catalog::fromSource($source)->vehicle('rdzak-gt-2')['sources'])->toBe([]);

        // What is withdrawn is no longer withdrawn once it is put back, and the next pack says so.
        publisher()->activate($pack, $admin);
        ContentDefinition::query()->where('slug', 'street-tires-t2')->update(['status' => ContentDefinition::STATUS_ACTIVE]);

        expect(publisher()->changes())->toBe([['key' => 'street-tires-t2', 'change' => 'offered']]);
    });
});

describe('what stops a pack', function () {
    it('is a draft that takes out what players use, which the drafts did not know when they were saved', function () {
        draftOf('rules/starter', function (array $rules): array {
            unset($rules['regions']['autodrom']);
            $rules['tracks'] = array_filter($rules['tracks'], fn (array $track): bool => $track['region'] !== 'autodrom');
            $rules['roads'] = array_values(array_filter($rules['roads'], fn (array $road): bool => ! in_array('autodrom', [$road['from'], $road['to']], true)));

            return $rules;
        });
        // A driver arrives in the city after the draft was saved.
        $driver = User::factory()->guest()->create();
        DB::table('city_garages')->insert(['user_id' => $driver->id, 'region' => 'autodrom', 'capacity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $publication = publisher()->queue(aPublisher())->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_FAILED)
            ->and($publication->report['failed_step'])->toBe('guards')
            ->and(array_column($publication->report['errors'], 'message'))->toBe(['It takes out what players use: city autodrom (1).'])
            ->and($publication->pack)->toBeNull()
            ->and(AdminAuditLog::query()->where('action', 'content_publication_failed')->value('context'))->toBe(['step' => 'guards']);
    });

    it('is a part that leaves the pack while a player has it', function () {
        $definition = ContentDefinition::query()->where('slug', 'sport-exhaust-t2')->firstOrFail();
        $definition->forceFill(['status' => ContentDefinition::STATUS_ARCHIVED])->save();
        $driver = User::factory()->guest()->create();
        Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust']);

        $publication = publisher()->queue(aPublisher())->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_FAILED)->and($publication->report['failed_step'])->toBe('guards')
            ->and(array_column($publication->report['errors'], 'file'))->toContain('parts/sport-exhaust-t2.json');
    });

    it('is content that does not validate: a shop that sells a part that was archived since', function () {
        $unsold = collect(repositoryContent()->slugs('parts'))->first(fn (string $slug): bool => ! in_array($slug, repositoryDocument('rules/starter')['shop']['parts'], true));
        draftOf('rules/starter', function (array $rules) use ($unsold): array {
            $rules['shop']['parts'][] = $unsold;

            return $rules;
        });
        ContentDefinition::query()->where('slug', $unsold)->update(['status' => ContentDefinition::STATUS_ARCHIVED]);

        $publication = publisher()->queue(aPublisher())->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_FAILED)->and($publication->report['failed_step'])->toBe('validate')
            ->and(array_column($publication->report['errors'], 'message'))->toContain("The shop sells {$unsold}, which is not a part of the pack.");
    });

    it('is a gate that does not pass, and the game reads the content it read before', function () {
        // The schema lets an hour give three times the XP (it allows up to 3 600 an hour), and the pace of the design does not: the economy gate says so.
        draftOf('rules/starter', function (array $rules): array {
            foreach ($rules['tracks'] as $slug => $track) {
                $rules['tracks'][$slug]['xp_per_hour'] = $track['xp_per_hour'] * 3;
            }

            return $rules;
        });
        $path = config('ride.content_path');

        $publication = publisher()->queue(aPublisher())->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_FAILED)->and($publication->report['failed_step'])->toBe('gates')
            ->and(array_column($publication->report['gates'], 'passed'))->toContain(false)
            ->and(collect($publication->report['gates'])->firstWhere('passed', false)['excerpt'])->not->toBe('')
            ->and($publication->pack)->toBeNull()
            ->and(config('ride.content_path'))->toBe($path)
            ->and(Rules::fromConfig()->version())->toBe(repositoryDocument('rules/starter')['version'])
            ->and(contentPacks()->active()->number)->toBe('2026.10.0');
    });
});

describe('the derived tables', function () {
    it('are the ones that the repository has, made again', function () {
        $made = app(DerivedTables::class)->make(contentPacks()->working());

        foreach ($made as $key => $json) {
            expect(json_decode($json, true))->toEqual(json_decode((string) repositoryContent()->raw($key), true), $key);
        }
    });

    it('are other ones when the cars are other cars', function () {
        $stronger = contentPacks()->working(['vehicles/rdzak-gt-4' => JsonDocument::encode([...repositoryDocument('vehicles/rdzak-gt-4'), 'power_kw' => 130])]);

        $made = app(DerivedTables::class)->make($stronger);

        expect(json_decode($made['tables/bot-profiles'], true))->not->toEqual(repositoryDocument('tables/bot-profiles'));
    });
});

describe('a game other than the first', function () {
    it('has its pack made, and made live, in that game: the gates run on files, which only the first game has, and the game is not lost after them', function () {
        $ships = Game::query()->create(['slug' => 'ships', 'name' => 'Star Ships', 'domain' => 'ships.test', 'status' => Game::STATUS_LIVE]);
        $current = app(CurrentGame::class);
        $first = contentPacks()->active();

        $publication = $current->within($ships, function () {
            contentPacks()->import(repositoryContent(), '2026.10.1', activate: true, checkTables: false);
            draftOf('vehicles/rdzak-gt-4', fn (array $car): array => [...$car, 'price_cents' => $car['price_cents'] + 100_000]);

            return publisher()->queue(aPublisher())->refresh();
        });

        expect($publication->status)->toBe(ContentPublication::STATUS_READY)
            ->and($publication->game_id)->toBe($ships->id)
            ->and(ContentPack::query()->withoutGlobalScopes()->findOrFail($publication->content_pack_id)->game_id)->toBe($ships->id)
            ->and(ContentPack::query()->withoutGlobalScopes()->where('game_id', $ships->id)->pluck('number')->all())->toContain($publication->number)
            ->and(contentPacks()->active()->id)->toBe($first->id)
            ->and($current->id())->not->toBe($ships->id);
    });
});
