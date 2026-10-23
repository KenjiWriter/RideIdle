<?php

declare(strict_types=1);

use App\Domain\Economy\Finding;
use App\Domain\Economy\RulesConsistency;

/**
 * The numbers of a game that agrees with the anchors: the lap of 75 s, 1.5 l of fuel at $2, a reward of $15.50, the
 * experience of a lap the anchors give (a little under two), and a set of tires at the price of its tier.
 *
 * @param  array<string, mixed>  $changes
 * @return array{lap_ms: int, fuel_ml: int, fuel_price_cents: int, reward_cents: int, xp_per_lap: int, parts: list<array{slug: string, tier: int, slot: string, price_cents: int}>}
 */
function gameNumbers(array $changes = []): array
{
    return [
        'lap_ms' => 75_000,
        'fuel_ml' => 1_500,
        'fuel_price_cents' => 200,
        'reward_cents' => 1_550,
        'xp_per_lap' => 2,
        'parts' => [['slug' => 'street-tires-t2', 'tier' => 2, 'slot' => 'tires', 'price_cents' => 57_000]],
        ...$changes,
    ];
}

/** @param  list<Finding>  $findings */
function findingFor(array $findings, string $subject): Finding
{
    return collect($findings)->first(static fn (Finding $finding): bool => $finding->subject === $subject);
}

it('finds nothing amiss in a game that agrees with the anchors', function () {
    $findings = (new RulesConsistency)->check(economyRules(), gameNumbers());

    expect(collect($findings)->every(static fn (Finding $finding): bool => $finding->ok))->toBeTrue()
        ->and($findings)->toHaveCount(6);
});

it('says by how much a number of the game is off', function () {
    $findings = (new RulesConsistency)->check(economyRules(), gameNumbers(['xp_per_lap' => 25]));
    $xp = findingFor($findings, 'experience per lap');

    expect($xp->ok)->toBeFalse()
        ->and($xp->expected)->toBe(1.875)
        ->and($xp->actual)->toBe(25.0)
        ->and(round($xp->deviation(), 2))->toBe(12.33)
        ->and(findingFor($findings, 'reward per lap')->ok)->toBeTrue();
});

it('allows a fifth either way, and not a little more', function () {
    $rules = economyRules();
    $reward = static fn (int $cents): bool => findingFor((new RulesConsistency)->check($rules, gameNumbers(['reward_cents' => $cents])), 'reward per lap')->ok;

    // The anchors give $15.50.
    expect($reward(1_850))->toBeTrue()
        ->and($reward(1_870))->toBeFalse()
        ->and($reward(1_250))->toBeTrue()
        ->and($reward(1_230))->toBeFalse();
});

it('uses the tolerance of the ruleset', function () {
    $rules = economyRules(['consistency', 'tolerance'], 0.5);

    expect(findingFor((new RulesConsistency)->check($rules, gameNumbers(['reward_cents' => 2_200])), 'reward per lap')->ok)->toBeTrue();
});

it('sets the price of every part against the anchors of its tier and slot', function () {
    $parts = [
        ['slug' => 'street-tires-t2', 'tier' => 2, 'slot' => 'tires', 'price_cents' => 57_000],
        ['slug' => 'greedy-tires-t2', 'tier' => 2, 'slot' => 'tires', 'price_cents' => 90_000],
    ];
    $findings = (new RulesConsistency)->check(economyRules(), gameNumbers(['parts' => $parts]));

    expect(findingFor($findings, 'price of street-tires-t2')->ok)->toBeTrue()
        ->and(findingFor($findings, 'price of greedy-tires-t2')->ok)->toBeFalse()
        ->and(round(findingFor($findings, 'price of greedy-tires-t2')->deviation(), 2))->toBe(0.58);
});

it('reports a part that no anchor speaks of, instead of passing it over', function () {
    $parts = [['slug' => 'warp-drive-t11', 'tier' => 11, 'slot' => 'engine', 'price_cents' => 100]];
    $finding = findingFor((new RulesConsistency)->check(economyRules(), gameNumbers(['parts' => $parts])), 'price of warp-drive-t11');

    expect($finding->expected)->toBeNull()
        ->and($finding->deviation())->toBeNull()
        ->and($finding->ok)->toBeFalse();
});
