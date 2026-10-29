<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Games\CurrentGame;
use App\Models\ContentPublication;
use App\Services\ContentPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Builds the pack of a publication: the gates of the simulators take minutes, so it is not done while a publisher waits for the page. */
class BuildContentPack implements ShouldQueue
{
    use Queueable;

    /** A pack is built once: a publication that failed is asked for again, as a new one. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $publicationId) {}

    /** The pack is built in the game that it was asked for in: the worker is for no game, and the content that it reads is that game's. */
    public function handle(CurrentGame $current): void
    {
        $publication = ContentPublication::query()->withoutGlobalScopes()->findOrFail($this->publicationId);

        $current->within($publication->game_id, fn () => app(ContentPublisher::class)->build($publication));
    }

    /** The worker was stopped or ran out of time, so the publisher did not get to say what happened. */
    public function failed(Throwable $problem): void
    {
        $publication = ContentPublication::query()->withoutGlobalScopes()->find($this->publicationId);

        if ($publication?->isOpen()) {
            app(CurrentGame::class)->within($publication->game_id, fn () => app(ContentPublisher::class)->fail($publication, 'job', $problem->getMessage()));
        }
    }
}
