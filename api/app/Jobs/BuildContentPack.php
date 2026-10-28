<?php

declare(strict_types=1);

namespace App\Jobs;

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

    public function handle(ContentPublisher $publisher): void
    {
        $publisher->build(ContentPublication::query()->findOrFail($this->publicationId));
    }

    /** The worker was stopped or ran out of time, so the publisher did not get to say what happened. */
    public function failed(Throwable $problem): void
    {
        $publication = ContentPublication::query()->find($this->publicationId);

        if ($publication?->isOpen()) {
            app(ContentPublisher::class)->fail($publication, 'job', $problem->getMessage());
        }
    }
}
