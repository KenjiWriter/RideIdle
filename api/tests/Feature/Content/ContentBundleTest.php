<?php

declare(strict_types=1);

use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\Definitions\JsonDocument;
use App\Content\Publishing\ContentBundle;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentPack;
use App\Services\ContentPublisher;

// A pack as one file: it goes out of one environment and into another, and what arrives is what left, to the checksum.

function bundles(): ContentBundle
{
    return app(ContentBundle::class);
}

/** The text of a bundle of the content of the repository with a car that has another name in French. */
function aBundleOfAnotherPack(string $number = '2026.10.7'): string
{
    $source = contentWith(['vehicles/rdzak-gt-4' => function (array $car): array {
        $car['name']['fr'] = 'Rdzak GT-4 (autre)';

        return $car;
    }]);

    return bundles()->export($source, $number);
}

describe('the file', function () {
    it('holds the pack as JSON objects, with their empty objects and the keys that look like numbers', function () {
        $text = bundles()->export(repositoryContent(), '2026.10.0');

        expect($text)->toContain('"bundle": 1')->and($text)->toContain('"stat_ppm": {}')->and($text)->toContain('"1": 1000000')
            ->and(json_decode($text, true)['checksum'])->toBe(repositoryContent()->checksum())
            ->and(json_decode($text, true)['pack'])->not->toHaveKey('checksum');
    });

    it('is read back as the same content, to the checksum', function () {
        $content = bundles()->read(bundles()->export(repositoryContent(), '2026.10.0'));

        expect($content->checksum())->toBe(repositoryContent()->checksum())
            ->and($content->number())->toBe('2026.10.0')
            ->and($content->keys())->toBe(repositoryContent()->keys());
    });

    it('is the content of a pack of the database, as the game reads it', function () {
        $import = contentPacks()->import(repositoryContent(), checkTables: false);

        $content = bundles()->read(app(ContentPublisher::class)->export($import->pack));

        expect($content->checksum())->toBe($import->pack->checksum)->and($content->number())->toBe($import->pack->number);
    });
});

describe('a file that is not what it says', function () {
    it('is refused: not JSON, not a bundle, another version of the shape', function (string $text, string $says) {
        expect(fn () => bundles()->read($text))->toThrow(ContentException::class, $says);
    })->with([
        'not JSON' => ['{ nope', 'not JSON'],
        'a list' => ['[1, 2]', 'not a bundle'],
        'a pack as the tree has it' => ['{"schema": 0, "name": "core", "version": "2026.10.0"}', 'not a bundle'],
        'another version of the shape' => ['{"bundle": 2, "number": "2026.10.0"}', 'not a bundle'],
    ]);

    it('is refused when its content was changed after it was made', function () {
        $bundle = json_decode(aBundleOfAnotherPack(), true);
        $bundle['documents']['vehicles/rdzak-gt-4']['power_kw'] = 150;

        expect(fn () => bundles()->read(json_encode($bundle)))->toThrow(ContentException::class, 'changed after it was made');
    });

    it('is refused when it names something that a pack does not have, such as a path', function (string $key) {
        $bundle = json_decode(aBundleOfAnotherPack(), true);
        $bundle['documents'][$key] = ['slug' => 'x'];

        expect(fn () => bundles()->read(json_encode($bundle)))->toThrow(ContentException::class, 'does not have');
    })->with(['../outside', 'vehicles/../parts/x', 'vehicles/Not A Slug', 'economy/x', 'vehicles/']);

    it('is refused for a number that is not one', function () {
        $bundle = json_decode(aBundleOfAnotherPack(), true);
        $bundle['number'] = 'latest';

        expect(fn () => bundles()->read(json_encode($bundle)))->toThrow(ContentException::class, 'not a number');
    });
});

describe('importing it', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
    });

    it('makes the pack under the number it came with, and leaves it waiting unless it is made live', function () {
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);
        $live = contentPacks()->active();

        $import = app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack());

        expect($import->created)->toBeTrue()->and($import->pack->number)->toBe('2026.10.7')
            ->and(JsonDocument::decode((string) contentPacks()->sourceOf($import->pack)->raw('vehicles/rdzak-gt-4'))['name']['fr'])->toBe('Rdzak GT-4 (autre)')
            ->and(contentPacks()->active()->id)->toBe($live->id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_imported')->value('subject'))->toBe('content_pack:2026.10.7');
    });

    it('makes it live when asked to', function () {
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);

        $import = app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack(), activate: true);

        expect(contentPacks()->active()->id)->toBe($import->pack->id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->count())->toBe(1);
    });

    it('can give it a number of its own, and is refused a number that is taken by other content', function () {
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);

        $import = app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack(), number: '2026.10.8');

        expect($import->pack->number)->toBe('2026.10.8')
            ->and(fn () => app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack(), number: '2026.10.0'))->toThrow(ContentException::class, 'already exists with other content');
    });

    it('is one pack when it is imported twice', function () {
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);
        $first = app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack());
        $second = app(ContentPublisher::class)->import($admin, aBundleOfAnotherPack());

        expect($second->created)->toBeFalse()->and($second->pack->id)->toBe($first->pack->id)->and(ContentPack::query()->count())->toBe(2);
    });

    it('is refused when its content does not pass the validation, and nothing is made', function () {
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);
        $source = contentWith(['vehicles/rdzak-gt-4' => fn (array $car): array => [...$car, 'power_kw' => -5]]);

        expect(fn () => app(ContentPublisher::class)->import($admin, bundles()->export($source, '2026.10.7')))->toThrow(ContentPackInvalid::class)
            ->and(ContentPack::query()->count())->toBe(1);
    });
});
