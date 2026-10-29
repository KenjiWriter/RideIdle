<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\PackContent;
use App\Games\CurrentGame;
use App\Services\ContentExporter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The gates of the simulators on the content of a pack: the tracks (every tire asks for its surface, the terrain counts, and no car wins more than two tracks) and
 * the economy (the pace of three kinds of players up the ladder of the cars is the one of the design, the numbers of the rules are the anchors', and so are the
 * ladder and the fleet of the game). The simulators read the content as the game does, so the pack is written as the `content/` tree in a folder of its own, the game
 * is pointed at that folder for as long as they run, and everything is put back after.
 */
final class PackGates
{
    /** The commands and what they are given: they exit with 1 when what they judge is not right. */
    private const GATES = [
        'tracks:simulate --strict' => ['tracks:simulate', ['--strict' => true]],
        'economy:simulate --strict --strict-game' => ['economy:simulate', ['--strict' => true, '--strict-game' => true]],
    ];

    /** How much of what a gate said is kept when it did not pass. */
    private const EXCERPT = 4000;

    public function __construct(private readonly ContentExporter $exporter, private readonly Application $app) {}

    /**
     * @return list<array{name: string, passed: bool, ms: int, excerpt: string}>
     */
    public function run(PackContent $candidate): array
    {
        return $this->inContentOf($candidate, function (): array {
            $results = [];

            foreach (self::GATES as $name => [$command, $options]) {
                $started = hrtime(true);
                $exit = Artisan::call($command, $options);
                $output = trim(Artisan::output());

                $results[] = [
                    'name' => $name,
                    'passed' => $exit === 0,
                    'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                    'excerpt' => $exit === 0 ? '' : Str::limit($output, self::EXCERPT, '...'),
                ];
            }

            return $results;
        });
    }

    /**
     * What the economy simulator says of the content of a pack, as numbers: the days the three kinds of players buy each car of the ladder on (against the days of the
     * design), and what each level of the ladder of the game earns an hour (against the anchor). It is what a person who changes a number wants to see change.
     *
     * @return array{pace: list<array{player: string, car: string, target_day: int, day: int, deviation: float, passed: bool}>, ladder: list<array{level: int, anchor: int|float, game: int|float, deviation: float, xp_game: int|float|null, xp_ok: bool|null, ok: bool}>}
     */
    public function measure(PackContent $content): array
    {
        return $this->inContentOf($content, function (): array {
            Artisan::call('economy:simulate', ['--json' => true]);
            $report = json_decode(trim(Artisan::output()), true) ?? [];

            return [
                'pace' => array_map(
                    static fn (array $row): array => ['player' => (string) $row['player'], 'car' => (string) $row['car'], 'target_day' => (int) $row['target_day'], 'day' => (int) $row['day'], 'deviation' => (float) $row['deviation'], 'passed' => (bool) $row['passed']],
                    $report['gate']['rows'] ?? [],
                ),
                'ladder' => array_map(
                    static fn (array $row): array => ['level' => (int) $row['level'], 'anchor' => $row['anchor'], 'game' => $row['game'], 'deviation' => (float) $row['deviation'], 'xp_game' => $row['xp_game'] ?? null, 'xp_ok' => $row['xp_ok'] ?? null, 'ok' => (bool) $row['ok']],
                    $report['game_ladder']['rows'] ?? [],
                ),
            ];
        });
    }

    /**
     * Runs `$work` with the game reading the content of a pack: the pack is written as the `content/` tree in a folder of its own, the game is pointed at it for as long
     * as `$work` runs, and everything is put back after.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inContentOf(PackContent $candidate, callable $work): mixed
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ride-gates-'.Str::lower(Str::random(12));
        $before = config('ride');
        // The game that asked for the pack: forgetting what the container holds drops it too, and the work after the gates (the pack is made, and made for a game)
        // would be done for the default game. The gates themselves read the candidate from files, which only the default game has, so they run as it.
        $current = $this->app->make(CurrentGame::class);

        try {
            $this->exporter->write($candidate, $directory, $candidate->number());
            // The schemas are the ones of the code that runs: the simulators check the rules they are given against them.
            File::copyDirectory(rtrim((string) $before['content_path'], '/\\').'/schema', "{$directory}/schema");
            config(['ride.content_source' => 'files', 'ride.content_path' => $directory]);
            $this->app->forgetScopedInstances();

            return $work();
        } finally {
            config(['ride' => $before]);
            $this->app->forgetScopedInstances();
            // The very object, put back: whoever holds it (the job that is making the pack) is still talking to the game that is in force. It is still a scoped
            // binding, so the end of the request or of the job forgets it as it does any other.
            $this->app->instance(CurrentGame::class, $current);
            File::deleteDirectory($directory);
        }
    }
}
