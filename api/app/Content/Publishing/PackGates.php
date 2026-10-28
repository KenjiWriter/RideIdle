<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\PackContent;
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
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ride-gates-'.Str::lower(Str::random(12));
        $before = config('ride');

        try {
            $this->exporter->write($candidate, $directory, $candidate->number());
            // The schemas are the ones of the code that runs: the simulators check the rules they are given against them.
            File::copyDirectory(rtrim((string) $before['content_path'], '/\\').'/schema', "{$directory}/schema");
            config(['ride.content_source' => 'files', 'ride.content_path' => $directory]);
            $this->app->forgetScopedInstances();

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
        } finally {
            config(['ride' => $before]);
            $this->app->forgetScopedInstances();
            File::deleteDirectory($directory);
        }
    }
}
