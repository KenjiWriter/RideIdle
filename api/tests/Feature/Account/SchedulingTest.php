<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/** The commands the scheduler runs, as written. */
function scheduled(): array
{
    return collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->all();
}

it('removes the guests nobody returns to, every day', function () {
    expect(collect(scheduled())->contains(fn (string $command) => str_contains($command, 'accounts:prune-guests')))->toBeTrue();
});

it('removes the one-time secrets that expired, every hour', function () {
    expect(collect(scheduled())->contains(fn (string $command) => str_contains($command, 'accounts:prune-tokens')))->toBeTrue();
});

it('removes the mails that failed to be sent after a week, so that their addresses do not stay in the database', function () {
    $command = collect(scheduled())->first(fn (string $command) => str_contains($command, 'queue:prune-failed'));

    expect($command)->not->toBeNull()->and($command)->toContain('--hours=168');
});
