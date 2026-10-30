<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\CityWorkshop;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\Purchase;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\StorageOpening;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\UserMission;
use App\Models\UserMissionPeriod;
use App\Models\UserVisit;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Everything the game holds about one account, for a person who asked for a copy of their data: who they are to the
 * game, how they sign in, which devices are signed in, and what they did and earned. It is plain data that can be
 * written as JSON and read by a person or a program.
 *
 * It holds no secret. The password, the recovery code, the device tokens and the one-time links are kept as hashes that
 * mean nothing to the person and could be used against them, so they are left out. The internals of a run (the seed
 * and the frozen rules) are not the person's data either.
 */
final class AccountExport
{
    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        return [
            'exported_at' => $this->day(now()),
            'account' => $this->account($user),
            'identities' => $user->identities()->orderBy('id')->get()->map(fn (AccountIdentity $identity): array => [
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'email' => $identity->email,
                'linked_at' => $this->day($identity->created_at),
            ])->all(),
            'devices' => $user->tokens()->orderBy('id')->get()->map(fn (PersonalAccessToken $token): array => [
                'name' => $token->name,
                'created_at' => $this->day($token->created_at),
                'last_used_at' => $this->day($token->last_used_at),
            ])->all(),
            'pending_links' => AccountToken::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (AccountToken $token): array => [
                'purpose' => $token->purpose,
                'provider' => $token->provider,
                'email' => $token->email,
                'created_at' => $this->day($token->created_at),
                'expires_at' => $this->day($token->expires_at),
                'used_at' => $this->day($token->used_at),
            ])->all(),
            'game' => $this->game($user),
        ];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'locale' => $user->locale,
            'email' => $user->email,
            'email_verified_at' => $this->day($user->email_verified_at),
            'created_at' => $this->day($user->created_at),
            'selected_car_id' => $user->selected_car_id,
            'secured_at' => $this->day($user->claimed_at),
            'last_seen_at' => $this->day($user->last_seen_at),
            'terms_accepted_at' => $this->day($user->terms_accepted_at),
            'terms_version' => $user->terms_version,
            'age_confirmed_at' => $this->day($user->age_confirmed_at),
            'has_recovery_code' => $user->hasRecoveryCode(),
            'recovery_code_set_at' => $this->day($user->recovery_code_set_at),
        ];
    }

    /** @return array<string, mixed> */
    private function game(User $user): array
    {
        $wallet = Wallet::query()->find($user->id);

        return [
            'wallet' => $wallet === null ? null : [
                'cash_cents' => $wallet->cash_cents,
                'xp' => $wallet->xp,
                'premium_coins' => $wallet->premium_coins,
                'auto_refills' => $wallet->auto_refills,
                'auto_refill_enabled' => $wallet->auto_refill_enabled,
                'vip_until' => $this->day($wallet->vip_until),
                'infinity_until' => $this->day($wallet->infinity_until),
                'updated_at' => $this->day($wallet->updated_at),
            ],
            'cars' => Car::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get()->map(
                fn (Car $car): array => [
                    ...$car->makeHidden(['user_id'])->toArray(),
                    'parts' => Part::query()->where('car_id', $car->id)->orderBy('id')->get()
                        ->map(fn (Part $part): array => $part->makeHidden(['user_id', 'car_id'])->toArray())->all(),
                ],
            )->all(),
            'inventory' => Part::query()->where('user_id', $user->id)->whereNull('car_id')->orderBy('id')->get()
                ->map(fn (Part $part): array => $part->makeHidden(['user_id', 'car_id', 'mounted_slot'])->toArray())->all(),
            'garages' => CityGarage::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (CityGarage $garage): array => $garage->makeHidden(['user_id'])->toArray())->all(),
            'workshops' => CityWorkshop::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (CityWorkshop $workshop): array => $workshop->makeHidden(['user_id'])->toArray())->all(),
            'storage_openings' => StorageOpening::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (StorageOpening $opening): array => $opening->makeHidden(['user_id'])->toArray())->all(),
            'crate_openings' => CrateOpening::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (CrateOpening $opening): array => $opening->makeHidden(['user_id'])->toArray())->all(),
            'merge_attempts' => MergeAttempt::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (MergeAttempt $attempt): array => $attempt->makeHidden(['user_id'])->toArray())->all(),
            'workshop_jobs' => WorkshopJob::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (WorkshopJob $job): array => $job->makeHidden(['user_id'])->toArray())->all(),
            'travels' => Travel::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (Travel $journey): array => $journey->makeHidden(['user_id'])->toArray())->all(),
            'tutorial' => TutorialProgress::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (TutorialProgress $step): array => $step->makeHidden(['id', 'user_id'])->toArray())->all(),
            'runs' => RaceRun::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get()->map(
                fn (RaceRun $run): array => [
                    ...$run->makeHidden(['config', 'seed', 'user_id'])->toArray(),
                    'settlements' => Settlement::query()->where('race_run_id', $run->id)->with('events')->orderBy('id')->get()
                        ->map(fn (Settlement $settlement): array => $settlement->makeHidden(['race_run_id'])->toArray())->all(),
                ],
            )->all(),
            'missions' => UserMission::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (UserMission $mission): array => $mission->makeHidden(['user_id'])->toArray())->all(),
            'mission_sets' => UserMissionPeriod::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (UserMissionPeriod $set): array => $set->makeHidden(['user_id'])->toArray())->all(),
            'visits' => UserVisit::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (UserVisit $visit): array => ['at' => $this->day($visit->at), 'gap_minutes' => $visit->gap_minutes])->all(),
            'purchases' => Purchase::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (Purchase $purchase): array => $purchase->makeHidden(['id', 'user_id'])->toArray())->all(),
            'ledger' => LedgerEntry::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (LedgerEntry $entry): array => $entry->makeHidden(['user_id', 'settlement_id', 'idem_key'])->toArray())->all(),
        ];
    }

    private function day(?Carbon $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
