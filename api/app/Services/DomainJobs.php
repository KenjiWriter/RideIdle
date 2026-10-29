<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainJobRefused;
use App\Models\Admin;
use App\Models\DomainJob;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the panel asks of the server about the domain of a game, and what the worker on the server does with it. The panel `request`s: it writes a row, after it has made
 * sure that there is something to ask (a domain that a certificate can be asked for, an address for Let's Encrypt, no request that is still open). The worker `claim`s the
 * oldest row, which no other worker can also get, does the thing and `report`s how it went. A worker that dies leaves a row `running` for good, so a row that has been
 * running for too long is given up on the next time one is claimed.
 */
final class DomainJobs
{
    /** How long a job may be `running` before it is taken for lost, in minutes. */
    public const STALE_MINUTES = 30;

    /** How much of the log of the worker is kept, in bytes: its end, which is where it says what went wrong. */
    public const LOG_BYTES = 20_000;

    /** The exit code that a job that was lost or whose domain had changed is reported with. */
    public const EXIT_LOST = 124;

    public const EXIT_REFUSED = 2;

    /**
     * Asks the server to do something about the domain of a game.
     *
     * @throws DomainJobRefused when there is nothing to ask: the game has no domain that a certificate can be asked for, no address is set for Let's Encrypt, or the last
     *                          request has not been answered yet
     */
    public function request(Game $game, string $action, ?Admin $by = null): DomainJob
    {
        if (! in_array($action, DomainJob::ACTIONS, true)) {
            throw new DomainJobRefused(DomainJobRefused::ACTION, $action);
        }

        $domain = strtolower((string) $game->domain);

        if (! DomainChecker::isValidHost($domain)) {
            throw new DomainJobRefused(DomainJobRefused::DOMAIN, $domain);
        }

        $email = $action === DomainJob::ACTION_PROVISION ? $this->email() : null;

        if ($action === DomainJob::ACTION_PROVISION && $email === null) {
            throw new DomainJobRefused(DomainJobRefused::EMAIL);
        }

        return DB::transaction(function () use ($game, $action, $domain, $email, $by): DomainJob {
            if (DomainJob::query()->where('game_id', $game->id)->whereIn('status', [DomainJob::STATUS_REQUESTED, DomainJob::STATUS_RUNNING])->lockForUpdate()->exists()) {
                throw new DomainJobRefused(DomainJobRefused::BUSY);
            }

            return DomainJob::query()->create([
                'game_id' => $game->id,
                'domain' => $domain,
                'action' => $action,
                'status' => DomainJob::STATUS_REQUESTED,
                'email' => $email,
                'requested_by' => $by?->id,
            ]);
        });
    }

    /**
     * The oldest job that waits, now `running`, or null when there is none. A job whose game has another domain by now (or none) is not done: it is reported refused and the
     * next one is looked at, because a root script must never be given a domain that nobody asked for any more.
     */
    public function claim(?CarbonImmutable $now = null): ?DomainJob
    {
        $now ??= CarbonImmutable::now();
        $this->giveUpOnLost($now);

        while (($next = DomainJob::query()->where('status', DomainJob::STATUS_REQUESTED)->orderBy('id')->first()) !== null) {
            $taken = DomainJob::query()->whereKey($next->id)->where('status', DomainJob::STATUS_REQUESTED)->update(['status' => DomainJob::STATUS_RUNNING, 'started_at' => $now, 'updated_at' => $now]);

            if ($taken !== 1) {
                continue;
            }

            $job = $next->refresh();

            if (strtolower((string) $job->game?->domain) !== $job->domain || ! DomainChecker::isValidHost($job->domain)) {
                $this->report($job, self::EXIT_REFUSED, 'The domain of the game is not the one that was asked for any more: nothing was done.', null, $now);

                continue;
            }

            return $job;
        }

        return null;
    }

    /**
     * What the worker says of a job it claimed: `done` when it exited with 0, `failed` otherwise, with the end of its log and the end date of the certificate if there is one.
     * A job that is not running (it was reported already, or was lost) is left as it is.
     */
    public function report(DomainJob $job, int $exitCode, ?string $log = null, ?string $expires = null, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $changed = DomainJob::query()->whereKey($job->id)->where('status', DomainJob::STATUS_RUNNING)->update([
            'status' => $exitCode === 0 ? DomainJob::STATUS_DONE : DomainJob::STATUS_FAILED,
            'exit_code' => max(0, min(65_535, $exitCode)),
            'log' => $this->tail($log),
            'certificate_expires_at' => $this->date($expires),
            'finished_at' => $now,
            'updated_at' => $now,
        ]);

        return $changed === 1;
    }

    /** The last thing asked of the server about the domain of a game, if anything was. */
    public function latestOf(Game $game): ?DomainJob
    {
        return $game->domainJobs()->reorder('id', 'desc')->first();
    }

    /** The address that Let's Encrypt writes to, if one is set and looks like an address. */
    public function email(): ?string
    {
        $email = trim((string) config('ride.network.acme_email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function giveUpOnLost(CarbonImmutable $now): void
    {
        DomainJob::query()->where('status', DomainJob::STATUS_RUNNING)->where('started_at', '<', $now->subMinutes(self::STALE_MINUTES))->update([
            'status' => DomainJob::STATUS_FAILED,
            'exit_code' => self::EXIT_LOST,
            'log' => 'The worker did not report in '.self::STALE_MINUTES.' minutes: the job was given up on.',
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The end of a text that is as long as the log may be, as text that the database takes (what is not UTF-8 is dropped, and so are control characters but the line breaks). */
    private function tail(?string $log): ?string
    {
        if ($log === null || $log === '') {
            return null;
        }

        $end = strlen($log) > self::LOG_BYTES ? substr($log, -self::LOG_BYTES) : $log;
        $clean = (string) iconv('UTF-8', 'UTF-8//IGNORE', $end);

        return preg_replace('/[^\P{C}\n\t]/u', '', $clean) ?? $clean;
    }

    private function date(?string $date): ?string
    {
        return $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && CarbonImmutable::hasFormat($date, 'Y-m-d') ? $date : null;
    }
}
