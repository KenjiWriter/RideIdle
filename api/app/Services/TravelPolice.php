<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Domain\Settlement\Ppm;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RuleRefusedException;
use App\Models\RaceRun;
use App\Models\Travel as Journey;

/**
 * The police on a boosted journey. Whether a boosted journey is stopped, where on the way and what the fine is are drawn when it starts (`plan`), from the server's
 * secret and the run, so they do not depend on when the driver looks. The stop is told to the client only when it has happened (`view`); then the driver has a
 * little time to choose (`resolve`): pay the fine, give a bribe (more, and it may not work) or have a lawyer (premium coins). When the time runs out, or the car is
 * about to arrive, the fine is paid for them.
 *
 * All of it runs under the lock of the wallet and the runs (the caller's), and every posting has a key of the journey, so nothing is charged twice.
 */
final class TravelPolice
{
    /** What the driver can choose to do. */
    public const CHOICES = ['pay', 'bribe', 'lawyer'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Premium $premium,
        private readonly Rules $rules,
        private readonly TrackChoices $choices,
    ) {}

    /**
     * Whether the journey is stopped, when, and the fine: nothing when the journey is not boosted or the draw says no.
     *
     * @return array{police_at_ms: int|null, police_fine_cents: int|null}
     */
    public function plan(RaceRun $run, int $startMs, int $durationMs): array
    {
        $boost = $this->rules->travelBoost();
        $none = ['police_at_ms' => null, 'police_fine_cents' => null];

        if ($boost === null || $this->draw('police', $run->id, $startMs) >= $boost['police_chance_ppm']) {
            return $none;
        }

        $span = $boost['police_to_ppm'] - $boost['police_from_ppm'];
        $share = $boost['police_from_ppm'] + intdiv($span * $this->draw('police-at', $run->id, $startMs), 1_000_000);

        // The fine is what the car earns in some minutes after the fuel, so that it follows the progress of the driver; there is a least.
        $netCentsPerHour = $this->choices->forCar($run, $run->track_slug)['net_cents_per_hour'];

        return [
            'police_at_ms' => $startMs + intdiv($durationMs * $share, 1_000_000),
            'police_fine_cents' => max($boost['fine_min_cents'], intdiv($netCentsPerHour * $boost['fine_minutes'], 60)),
        ];
    }

    /**
     * What the client may know of the stop: nothing before it happens. While the driver has not chosen it says what each choice costs; after, what came of it.
     *
     * @return array{at_ms: int, decide_by_ms: int, fine_cents: int, status: string, choice: string|null, outcome: string|null, paid_cents: int|null, options: array<string, mixed>|null}|null
     */
    public function view(Journey $journey, int $now): ?array
    {
        $boost = $this->rules->travelBoost();

        if ($boost === null || $journey->police_at_ms === null || $now < $journey->police_at_ms) {
            return null;
        }

        $resolved = $journey->police_choice !== null;

        return [
            'at_ms' => $journey->police_at_ms,
            'decide_by_ms' => $journey->police_at_ms + $boost['decide_ms'],
            'fine_cents' => (int) $journey->police_fine_cents,
            'status' => $resolved ? 'resolved' : 'pending',
            'choice' => $journey->police_choice,
            'outcome' => $journey->police_outcome,
            'paid_cents' => $journey->police_paid_cents,
            'options' => $resolved ? null : $this->options($journey),
        ];
    }

    /**
     * What each choice costs and risks.
     *
     * @return array{pay: array{cents: int, stop_ms: int}, bribe: array{cents: int, risk_ppm: int, fail_cents: int, fail_stop_ms: int}, lawyer: array{coins: int}|null}
     */
    public function options(Journey $journey): array
    {
        $boost = $this->rules->travelBoost() ?? throw new RuleRefusedException('boost_unavailable');
        $fine = (int) $journey->police_fine_cents;

        return [
            'pay' => ['cents' => $fine, 'stop_ms' => $boost['stop_ms']],
            'bribe' => [
                'cents' => Ppm::mul($fine, $boost['bribe_ppm']),
                'risk_ppm' => $boost['bribe_fail_ppm'],
                'fail_cents' => Ppm::mul($fine, $boost['bribe_fail_fine_ppm']),
                'fail_stop_ms' => $boost['bribe_fail_stop_ms'],
            ],
            // The lawyer is paid in premium coins, which the game sells only when the rules have the catalog.
            'lawyer' => $this->rules->premiumCurrency() === null ? null : ['coins' => $boost['lawyer_coins']],
        ];
    }

    /**
     * Puts the stop right now, for the choice of the driver (or, `$byDefault`, for the fine that is paid for them when the time ran out), and moves the arrival
     * by the time the car stood. `$at` is the moment it happens.
     *
     * @throws InsufficientFundsException a bribe the driver cannot pay (nothing is changed)
     * @throws RuleRefusedException `insufficient_premium` for a lawyer the driver cannot pay, `police_no_lawyer` when the game sells none
     */
    public function resolve(Journey $journey, string $choice, int $userId, int $at, bool $byDefault = false): Journey
    {
        $boost = $this->rules->travelBoost() ?? throw new RuleRefusedException('boost_unavailable');
        $fine = (int) $journey->police_fine_cents;
        $key = "police:{$journey->id}";
        $cash = $this->ledger->balance($userId);
        $paid = 0;
        $stopMs = 0;

        if ($choice === 'lawyer') {
            $options = $this->options($journey);

            if ($options['lawyer'] === null) {
                throw new RuleRefusedException('police_no_lawyer');
            }

            $this->premium->spend($userId, $options['lawyer']['coins'], 'police_lawyer', "{$key}:lawyer");
            $outcome = 'lawyered';
        } elseif ($choice === 'bribe') {
            $price = Ppm::mul($fine, $boost['bribe_ppm']);

            // The driver has to be able to pay it before they offer it.
            if ($cash < $price) {
                throw new InsufficientFundsException($cash, $price);
            }

            if ($this->draw('bribe', (int) $journey->race_run_id, (int) $journey->start_ms) >= $boost['bribe_fail_ppm']) {
                $paid = $price;
                $outcome = 'bribed';
            } else {
                // The officer takes the bribe and writes the fine all the same, and a bigger one.
                $paid = min(Ppm::mul($fine, $boost['bribe_fail_fine_ppm']), $cash);
                $stopMs = $boost['bribe_fail_stop_ms'];
                $outcome = 'bribe_failed';
            }
        } else {
            // The fine is paid with what the driver has: a car that earns little does not push the cash below zero.
            $paid = min($fine, $cash);
            $stopMs = $boost['stop_ms'];
            $outcome = $byDefault ? 'defaulted' : 'paid';
        }

        if ($paid > 0) {
            $this->ledger->post($userId, -$paid, $choice === 'bribe' ? 'police_bribe' : 'police_fine', $key);
        }

        $journey->forceFill([
            'police_choice' => $byDefault ? 'default' : $choice,
            'police_outcome' => $outcome,
            'police_paid_cents' => $paid,
            'police_resolved_ms' => $at,
            'arrive_ms' => $journey->arrive_ms + $stopMs,
        ])->save();

        return $journey;
    }

    /**
     * A number from 0 to 999 999 that nobody can know before the journey starts and nobody can change after: the server's secret, a label of what is drawn, and the
     * run and the moment of the start.
     */
    private function draw(string $label, int $runId, int $startMs): int
    {
        return (int) (hexdec(substr(hash_hmac('sha256', "{$label}|{$runId}|{$startMs}", (string) config('app.key')), 0, 8)) % 1_000_000);
    }
}
