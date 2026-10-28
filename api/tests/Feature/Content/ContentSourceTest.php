<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\ContentException;
use App\Content\PackContent;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\TutorialSteps;

// The content of the game is a set of JSON documents named by keys; the files of the repository and a pack in the database are two ways to hold it.

describe('the files of the repository as a content source', function () {
    it('lists the documents of every folder by name, and the keys of all of them but the manifest', function () {
        $source = repositoryContent();

        expect($source->slugs('vehicles'))->toHaveCount(23)->toContain('rdzak-gt-4')
            ->and($source->slugs('parts'))->toHaveCount(74)
            ->and($source->slugs('rules'))->toBe(['economy', 'starter', 'tutorial'])
            ->and($source->slugs('tables'))->toBe(['bot-profiles', 'elo-expected-ppm', 'rarity-pace', 'unit-returns'])
            ->and($source->slugs('anything-else'))->toBe([])
            ->and($source->keys())->toHaveCount(23 + 74 + 3 + 4)->toContain('vehicles/rdzak-gt-4', 'rules/starter', 'tables/bot-profiles')->not->toContain('pack');
    });

    it('reads a document as written, decoded, and says it when there is none', function () {
        $source = repositoryContent();

        expect($source->document('vehicles/rdzak-gt-4')['slug'])->toBe('rdzak-gt-4')
            ->and($source->manifest()['name'])->toBe('core')
            ->and($source->has('parts/street-tires-t2'))->toBeTrue()
            ->and($source->has('parts/nothing-like-it'))->toBeFalse()
            ->and(fn () => $source->document('parts/nothing-like-it'))->toThrow(ContentException::class, 'no document parts/nothing-like-it');
    });

    it('does not let a key out of the folders of the content', function () {
        $source = repositoryContent();

        expect($source->raw('vehicles/../../rules/starter'))->toBeNull()
            ->and($source->raw('rules/../tables/elo-expected-ppm'))->toBeNull()
            ->and($source->raw('elsewhere/starter'))->toBeNull()
            ->and($source->raw('rules'))->toBeNull();
    });

    it('is what the game reads while the configuration says files, with the version of the configuration', function () {
        expect(app(Rules::class)->version())->toBe(json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true)['version'])
            ->and(app(Catalog::class)->vehicle('rdzak-gt-4')['name']['en'])->toBe('Rdzak GT-4')
            ->and(app(TutorialSteps::class)->all()[0]['key'])->toBe('wreck')
            ->and(repositoryContent()->rulesetVersion())->toBe((string) config('ride.ruleset_version'));
    });
});

describe('the checksum of the content', function () {
    it('is the same for the same content, whatever the white space of its documents', function () {
        $documents = repositoryDocuments();
        $spaced = $documents;
        $spaced['vehicles/rdzak-gt-4'] = json_encode(json_decode($documents['vehicles/rdzak-gt-4']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        expect((new PackContent('2026.10.0', $spaced))->checksum())->toBe((new PackContent('2026.10.0', $documents))->checksum())
            ->and(repositoryContent()->checksum())->toBe((new PackContent('2026.10.0', $documents))->checksum())
            ->and(repositoryContent()->checksum())->toMatch('/^[0-9a-f]{64}$/');
    });

    it('changes with a number, and with the order of the keys, which can mean something (the first track is where a run starts)', function () {
        $changed = contentWith(['vehicles/rdzak-gt-4' => fn (array $vehicle): array => [...$vehicle, 'power_kw' => 98]]);
        $reordered = contentWith(['rules/starter' => function (array $rules): array {
            $tracks = $rules['tracks'];
            $first = array_key_first($tracks);
            $reordered = [...array_slice($tracks, 1), $first => $tracks[$first]];

            return [...$rules, 'tracks' => $reordered];
        }]);

        expect($changed->checksum())->not->toBe(repositoryContent()->checksum())
            ->and($reordered->checksum())->not->toBe(repositoryContent()->checksum())
            ->and($changed->checksum())->not->toBe($reordered->checksum());
    });

    it('does not count the version and the checksum of the manifest, which say which copy it is and not what it holds', function () {
        $documents = repositoryDocuments();
        $stamped = $documents;
        $stamped['pack'] = json_encode([...json_decode($documents['pack'], true), 'version' => '2030.1.1', 'checksum' => str_repeat('a', 64)]);

        expect((new PackContent('2030.1.1', $stamped))->checksum())->toBe((new PackContent('2026.10.0', $documents))->checksum());
    });

    it('sees an empty object and an empty list as two things', function () {
        $object = new PackContent('2026.10.0', [...repositoryDocuments(), 'tables/probe' => '{"a": {}}']);
        $list = new PackContent('2026.10.0', [...repositoryDocuments(), 'tables/probe' => '{"a": []}']);

        expect($object->checksum())->not->toBe($list->checksum());
    });
});

describe('a pack held in memory', function () {
    it('lists, reads and has the version of the pack it is', function () {
        $pack = new PackContent('2026.10.7', ['pack' => '{"name": "x"}', 'parts/b-part' => '{}', 'parts/a-part' => '{}', 'rules/starter' => '{}']);

        expect($pack->slugs('parts'))->toBe(['a-part', 'b-part'])
            ->and($pack->slugs('vehicles'))->toBe([])
            ->and($pack->keys())->toBe(['parts/a-part', 'parts/b-part', 'rules/starter'])
            ->and($pack->raw('parts/a-part'))->toBe('{}')
            ->and($pack->raw('parts/c-part'))->toBeNull()
            ->and($pack->rulesetVersion())->toBe('2026.10.7')
            ->and($pack->number())->toBe('2026.10.7');
    });

    it('refuses a document that is not JSON or not an object', function () {
        $pack = new PackContent('2026.10.7', ['pack' => '[1, 2]', 'rules/starter' => '{broken']);

        expect(fn () => $pack->document('rules/starter'))->toThrow(ContentException::class, 'not JSON')
            ->and(fn () => $pack->manifest())->toThrow(ContentException::class, 'not a JSON object');
    });
});

describe('the validation of a content source', function () {
    it('passes the content of the repository, and counts what is in it', function () {
        $result = PackValidator::fromConfig()->validateSource(repositoryContent());

        expect($result->isValid())->toBeTrue()
            ->and($result->errors())->toBe([])
            ->and($result->counts())->toBe(['vehicles' => 23, 'parts' => 74]);
    });

    it('checks every document against its schema: a car, a part, the rules and the steps of the tutorial', function (string $key, Closure $change, string $file) {
        $result = PackValidator::fromConfig()->validateSource(contentWith([$key => $change]));

        expect($result->isValid())->toBeFalse()
            ->and(array_column($result->errors(), 'file'))->toContain($file);
    })->with([
        'a car without a tier' => ['vehicles/rdzak-gt-4', function (array $vehicle): array {
            unset($vehicle['tier']);

            return $vehicle;
        }, 'vehicles/rdzak-gt-4.json'],
        'a part in a slot there is none of' => ['parts/street-tires-t2', fn (array $part): array => [...$part, 'slot' => 'nowhere'], 'parts/street-tires-t2.json'],
        'rules without the stock tires' => ['rules/starter', function (array $rules): array {
            unset($rules['stock_tires']);

            return $rules;
        }, 'rules/starter.json'],
        'a tutorial without steps' => ['rules/tutorial', function (array $tutorial): array {
            unset($tutorial['steps']);

            return $tutorial;
        }, 'rules/tutorial.json'],
        'a manifest without a name' => ['pack', function (array $manifest): array {
            unset($manifest['name']);

            return $manifest;
        }, 'pack.json'],
    ]);

    it('wants a document to be named after its slug, and the dealer level to follow the tier', function () {
        $misnamed = PackValidator::fromConfig()->validateSource(contentWith(['vehicles/rdzak-gt-4' => fn (array $vehicle): array => [...$vehicle, 'slug' => 'rdzak-gt-44']]));
        $level = PackValidator::fromConfig()->validateSource(contentWith(['vehicles/rdzak-gt-4' => fn (array $vehicle): array => [...$vehicle, 'min_player_level' => 20]]));

        expect(array_column($misnamed->errors(), 'message'))->toContain('The document must be named after its slug: rdzak-gt-4.')
            ->and(array_column($level->errors(), 'pointer'))->toContain('/min_player_level');
    });

    it('wants what the rules point to to be in the pack: the stock tires, the parts of the shop, the cars with a wreck price, the regions of the tracks and the roads', function (Closure $change, string $pointer) {
        $result = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => $change]));

        expect($result->isValid())->toBeFalse()
            ->and(array_column($result->errors(), 'pointer'))->toContain($pointer);
    })->with([
        'stock tires that are not a part' => [fn (array $rules): array => [...$rules, 'stock_tires' => 'tires-from-nowhere'], '/stock_tires'],
        'a shop that sells a part that is not there' => [function (array $rules): array {
            $rules['shop']['parts'][] = 'part-from-nowhere';

            return $rules;
        }, '/shop/parts/71'],
        'a wreck price of a car that is not there' => [function (array $rules): array {
            $rules['garage']['wreck_price_cents']['car-from-nowhere'] = 100;

            return $rules;
        }, '/garage/wreck_price_cents/car-from-nowhere'],
        'a track in a region that is not there' => [function (array $rules): array {
            $rules['tracks']['zlomowo-street']['region'] = 'region-from-nowhere';

            return $rules;
        }, '/tracks/zlomowo-street/region'],
        'a road to a region that is not there' => [function (array $rules): array {
            $rules['roads'][0]['to'] = 'region-from-nowhere';

            return $rules;
        }, '/roads/0/to'],
    ]);

    it('asks for the rules and the tutorial, and only warns about the anchors of the economy and a missing translation', function () {
        $documents = repositoryDocuments();
        unset($documents['rules/starter'], $documents['rules/tutorial'], $documents['rules/economy']);
        $without = PackValidator::fromConfig()->validateSource(new PackContent('2026.10.0', $documents));

        $translated = contentWith(['vehicles/rdzak-gt-4' => function (array $vehicle): array {
            unset($vehicle['name']['de']);

            return $vehicle;
        }]);
        $warned = PackValidator::fromConfig()->validateSource($translated);

        expect(array_column($without->errors(), 'file'))->toContain('rules/starter.json', 'rules/tutorial.json')->not->toContain('rules/economy.json')
            ->and(array_column($without->warnings(), 'file'))->toContain('rules/economy.json')
            ->and($warned->isValid())->toBeTrue()
            ->and(array_column($warned->warnings(), 'pointer'))->toContain('/name/de');
    });

    it('takes a table that is JSON, and refuses one that is not', function () {
        $broken = new PackContent('2026.10.0', [...repositoryDocuments(), 'tables/probe' => '{broken']);

        expect(PackValidator::fromConfig()->validateSource($broken)->isValid())->toBeFalse();
    });
});
