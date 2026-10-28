<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Publishing\PartPreview;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;

// The preview of what a part does to a car and to its laps, on the content as it is being worked on: a new part, a part that was changed, and a part that is in
// the shop set of the test car.

function previewOf(string $part, string $car = PartPreview::CAR, int $rarity = 1): array
{
    return app(PartPreview::class)->preview($part, $car, $rarity);
}

/** A part of the repository as a draft with other stats, or as a new part when it has another slug. @param  array<string, int|float>  $stats */
function draftPart(string $from, string $slug, array $stats): void
{
    $document = [...JsonDocument::decode((string) repositoryContent()->raw("parts/{$from}")), 'slug' => $slug];
    $document['stats'] = [...$document['stats'], ...$stats];
    $admin = anAdmin();

    if ($slug === $from) {
        app(ContentDrafts::class)->save(ContentDefinition::query()->where('kind', 'part')->where('slug', $slug)->firstOrFail(), $document, $admin);
    } else {
        app(ContentDrafts::class)->create('part', $document, $admin);
    }
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
});

describe('a new part', function () {
    it('is compared with the part of the shop set that it would replace, on every track', function () {
        draftPart('street-tires-t2', 'street-tires-t9', ['grip' => 140, 'handling' => 138, 'braking' => 140]);

        $preview = previewOf('street-tires-t9');

        expect($preview['baseline'])->toBe('shop')->and($preview['baseline_part'])->toBe('street-tires-t2')
            ->and(array_keys($preview['tracks']))->toBe(array_keys(repositoryDocument('rules/starter')['tracks']))
            ->and($preview['tracks']['zlomowo-street']['dry']['after'])->toBeLessThan($preview['tracks']['zlomowo-street']['dry']['before'])
            ->and($preview['tracks']['zlomowo-street']['rain']['after'])->toBeLessThan($preview['tracks']['zlomowo-street']['rain']['before'])
            ->and($preview['stats']['grip']['after'])->toBeGreaterThan($preview['stats']['grip']['before'])
            ->and($preview['tracks']['zlomowo-street']['income']['after'])->toBeGreaterThan($preview['tracks']['zlomowo-street']['income']['before']);
    });

    it('says nothing of the rain on a track where it does not rain', function () {
        draftPart('street-tires-t2', 'street-tires-t9', ['grip' => 140]);

        $rainless = collect(previewOf('street-tires-t9')['tracks'])->filter(fn (array $track): bool => $track['rain']['after'] === null)->keys();
        $dry = collect(repositoryDocument('rules/starter')['regions'])->filter(fn (array $region): bool => ! isset($region['weather_ppm']['rain']))->keys();

        expect($rainless->count())->toBe(collect(repositoryDocument('rules/starter')['tracks'])->filter(fn (array $track): bool => $dry->contains($track['region']))->count());
    });
});

describe('a part that was changed', function () {
    it('is compared with the part that is live', function () {
        draftPart('street-tires-t2', 'street-tires-t2', ['grip' => 120, 'handling' => 118, 'braking' => 120]);

        $preview = previewOf('street-tires-t2');

        expect($preview['baseline'])->toBe('published')->and($preview['baseline_part'])->toBe('street-tires-t2')
            ->and($preview['stats']['grip']['before'])->toBeLessThan($preview['stats']['grip']['after'])
            ->and($preview['tracks']['zlomowo-street']['dry']['after'])->toBeLessThan($preview['tracks']['zlomowo-street']['dry']['before']);
    });
});

describe('a part of the shop set that was not changed', function () {
    it('is compared with the car without it, the stock part of the slot', function () {
        $preview = previewOf('street-tires-t2');

        expect($preview['baseline'])->toBe('stock')->and($preview['baseline_part'])->toBeNull()
            ->and($preview['tracks']['zlomowo-street']['dry']['after'])->toBeLessThan($preview['tracks']['zlomowo-street']['dry']['before']);
    });

    it('does more as it is rarer', function () {
        $common = previewOf('street-tires-t2', rarity: 1);
        $legendary = previewOf('street-tires-t2', rarity: 5);

        expect($legendary['tracks']['zlomowo-street']['dry']['after'])->toBeLessThan($common['tracks']['zlomowo-street']['dry']['after']);
    });
});

describe('what cannot be previewed', function () {
    it('is a part or a car that is not there', function () {
        expect(fn () => previewOf('part-from-nowhere'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => previewOf('street-tires-t2', 'car-from-nowhere'))->toThrow(InvalidArgumentException::class);
    });
});
