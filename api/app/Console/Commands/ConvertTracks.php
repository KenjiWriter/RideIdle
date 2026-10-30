<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInAGame;
use App\Content\ContentSource;
use App\Content\TrackConverter;
use App\Content\TrackNumbers;
use Illuminate\Console\Command;
use JsonException;

/**
 * What the model of the tracks would make of the tracks of a game that say their lap, their fuel, what a lap pays and what it gives by hand: for every track the speed, the
 * pay of an hour and the XP of an hour that make the same four numbers, and the ones that cannot be made exactly (they stay by hand). It changes nothing: a game converts its
 * tracks in the panel (the world page, "Przejdź na model"), which keeps a draft that goes through the gates like any other change.
 */
final class ConvertTracks extends Command
{
    use RunsInAGame;

    protected $signature = 'content:convert-tracks
        {--game= : The game whose live rules are read (the default game without it)}
        {--file= : A rules document to read instead of the live rules}
        {--json : Print the report as JSON}
        {--strict : Exit with 1 when a number cannot be made exactly}';

    protected $description = 'Show what the track model makes of the tracks that say their lap, fuel, pay and XP by hand (changes nothing)';

    public function handle(ContentSource $source): int
    {
        $rules = $this->rules($source);

        if ($rules === null) {
            return self::FAILURE;
        }

        $loads = TrackNumbers::fuelLoads($rules);
        $report = [];
        $byHand = 0;

        foreach ($rules['tracks'] ?? [] as $slug => $track) {
            if (! is_array($track)) {
                continue;
            }

            $converted = TrackConverter::convert($track, $loads);
            $report[$slug] = $converted['report'];
            $byHand += count(array_filter($converted['report'], static fn (array $row): bool => ! $row['converted']));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->print($report, $byHand);
        }

        return $byHand > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function rules(ContentSource $source): ?array
    {
        try {
            $json = is_string($this->option('file')) && $this->option('file') !== '' ? file_get_contents((string) $this->option('file')) : $source->raw('rules/starter');

            return is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : $this->refuse('There are no rules to read.');
        } catch (JsonException) {
            return $this->refuse('The rules are not JSON.');
        }
    }

    private function refuse(string $message): ?array
    {
        $this->error($message);

        return null;
    }

    /** @param array<string, list<array{field: string, before: int|float|null, after: int|float|null, converted: bool, note: string}>> $report */
    private function print(array $report, int $byHand): void
    {
        $rows = [];

        foreach ($report as $slug => $changes) {
            foreach ($changes as $change) {
                $rows[] = [$slug, $change['field'], (string) $change['before'], $change['converted'] ? "{$change['note']}: {$change['after']}" : 'stays by hand: '.$change['note'], $change['converted'] ? 'yes' : 'no'];
            }
        }

        if ($rows === []) {
            $this->info('No track says its numbers by hand: there is nothing to convert.');

            return;
        }

        $this->table(['track', 'said by hand', 'was', 'becomes', 'exact'], $rows);
        $this->line($byHand === 0 ? 'Every number can be made exactly: nothing changes in the game when the tracks are converted.' : "{$byHand} number(s) cannot be made exactly and stay by hand.");
    }
}
