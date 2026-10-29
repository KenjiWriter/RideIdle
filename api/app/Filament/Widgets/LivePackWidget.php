<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\PendingChangesPage;
use App\Games\CurrentGame;
use App\Models\ContentActivation;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use App\Services\ContentPacks;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** What the game reads now: the pack that is live, since when, how many packs there are, and whether the game reads from the database at all. */
class LivePackWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $pack = app(ContentPacks::class)->active();
        $since = $pack === null ? null : ContentActivation::query()->where('content_pack_id', $pack->id)->latest('id')->value('created_at');
        // The files of the repository are the content of the default game: any other game reads its pack from the database, whatever the configuration says.
        $fromDatabase = config('ride.content_source') === 'database' || ! app(CurrentGame::class)->isDefault();

        return [
            Stat::make(__('admin.widget.live_pack'), $pack?->number ?? __('admin.widget.none'))
                ->description($pack === null ? __('admin.widget.no_pack') : __('admin.widget.since', ['when' => $since?->format('Y-m-d H:i')]))
                ->color($pack === null ? 'danger' : 'success'),
            Stat::make(__('admin.widget.source'), $fromDatabase ? __('admin.widget.database') : __('admin.widget.files'))
                ->description($fromDatabase ? __('admin.widget.database_hint') : __('admin.widget.files_hint'))
                ->color($fromDatabase ? 'success' : 'warning'),
            Stat::make(__('admin.widget.packs'), (string) ContentPack::query()->count()),
            Stat::make(__('admin.widget.drafts'), (string) ContentDefinition::query()->whereNotNull('draft')->count())->description(__('admin.widget.drafts_hint'))->url(PendingChangesPage::getUrl()),
        ];
    }
}
