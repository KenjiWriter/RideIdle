<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\PackContent;
use App\Content\PackValidator;
use App\Content\Rules;

// A pack that withdraws cars and parts keeps them (what players own runs) and offers them no more: the shop and the crates lose the parts, the cars lose their
// sources. The manifest says which; the validator checks that they are there and that a new driver still has a car to start with.

/** The pack with the manifest changed. @param  list<string>  $withdrawn */
function packWithdrawing(array $withdrawn): PackContent
{
    return contentWith(['pack' => fn (array $manifest): array => [...$manifest, 'withdrawn' => $withdrawn]]);
}

describe('a withdrawn car', function () {
    it('is still a car but has no source, so nobody starts with it and the market does not offer it', function () {
        $source = packWithdrawing(['rdzak-gt-2', 'bizon-m-2']);
        $catalog = Catalog::fromSource($source);

        expect($catalog->vehicle('rdzak-gt-2')['sources'])->toBe([])
            ->and($catalog->vehicle('rdzak-gt-2')['power_kw'])->toBe(Catalog::fromSource(repositoryContent())->vehicle('rdzak-gt-2')['power_kw'])
            ->and(array_keys($catalog->startingVehicles()))->not->toContain('bizon-m-2')->and(array_keys($catalog->startingVehicles()))->toContain('kuna-4x4')
            ->and(Catalog::fromSource(repositoryContent())->vehicle('rdzak-gt-2')['sources'])->not->toBe([]);
    });
});

describe('a withdrawn part', function () {
    it('leaves the shop, and with it the crates, but is still a part of the pack', function () {
        $before = Rules::fromSource(repositoryContent())->shopParts();
        $source = packWithdrawing(['street-tires-t2']);

        expect($before)->toContain('street-tires-t2')
            ->and(Rules::fromSource($source)->shopParts())->not->toContain('street-tires-t2')
            ->and(count(Rules::fromSource($source)->shopParts()))->toBe(count($before) - 1)
            ->and(Catalog::fromSource($source)->part('street-tires-t2'))->not->toBeNull();
    });

    it('changes the checksum of the pack, so that a pack that withdraws is another pack', function () {
        expect(packWithdrawing(['street-tires-t2'])->checksum())->not->toBe(repositoryContent()->checksum());
    });
});

describe('the validation of a pack that withdraws', function () {
    it('accepts what is in the pack', function () {
        $result = PackValidator::fromConfig()->validateSource(packWithdrawing(['rdzak-gt-2', 'street-tires-t2']));

        expect($result->isValid())->toBeTrue();
    });

    it('refuses a slug that is neither a car nor a part, and a repeated one', function () {
        $unknown = PackValidator::fromConfig()->validateSource(packWithdrawing(['nothing-of-the-kind']));
        $twice = PackValidator::fromConfig()->validateSource(packWithdrawing(['rdzak-gt-2', 'rdzak-gt-2']));

        expect(array_column($unknown->errors(), 'pointer'))->toContain('/withdrawn/0')
            ->and($twice->isValid())->toBeFalse();
    });

    it('refuses to withdraw every car that a new driver can start with', function () {
        $result = PackValidator::fromConfig()->validateSource(packWithdrawing(['bizon-m-2', 'kuna-4x4', 'rdzak-gt-4']));

        expect($result->isValid())->toBeFalse()->and(array_column($result->errors(), 'message'))->toContain('Every car that a new driver can start with is withdrawn.');
    });
});
