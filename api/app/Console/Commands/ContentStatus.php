<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentActivation;
use App\Models\ContentPack;
use App\Services\ContentPacks;
use Illuminate\Console\Command;

/** Says where the game reads its content from, and lists the packs of the database with the live one marked. */
final class ContentStatus extends Command
{
    protected $signature = 'content:status';

    protected $description = 'Show where the game reads its content from and the packs in the database';

    public function handle(ContentPacks $packs): int
    {
        $source = (string) config('ride.content_source');
        $this->line($source === 'database'
            ? 'The game reads its content from the database.'
            : 'The game reads its content from the files in '.config('ride.content_path').'.');

        $active = $packs->active();
        $rows = ContentPack::query()->orderByDesc('id')->get()->map(fn (ContentPack $pack): array => [
            $pack->id === $active?->id ? '*' : '',
            $pack->number,
            substr($pack->checksum, 0, 12),
            $pack->created_at?->format('Y-m-d H:i'),
            $pack->versions()->count(),
            ContentActivation::query()->where('content_pack_id', $pack->id)->count(),
        ])->all();

        if ($rows === []) {
            $this->line('There is no pack in the database. content:import makes one.');

            return self::SUCCESS;
        }

        $this->table(['live', 'number', 'checksum', 'made', 'documents', 'times live'], $rows);

        return self::SUCCESS;
    }
}
