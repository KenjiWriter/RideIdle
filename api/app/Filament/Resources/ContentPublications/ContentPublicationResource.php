<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentPublications;

use App\Content\ContentPackInvalid;
use App\Filament\Resources\ContentPublications\Pages\ManageContentPublications;
use App\Models\Admin;
use App\Models\ContentPack;
use App\Models\ContentPublication;
use App\Services\ContentPacks;
use App\Services\ContentPublisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The packs that are being made out of the drafts, and the ones that were (D5): a publisher asks for a pack, a job builds it, and the report says whether it is
 * ready or what stopped it. A pack that is ready is made live from here, which is the activation of the packs with its trail.
 */
class ContentPublicationResource extends Resource
{
    protected static ?string $model = ContentPublication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.publications.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.publications.many');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['requestedBy', 'pack']))
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('number')->label(__('admin.packs.number'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('admin.publications.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.publications.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        ContentPublication::STATUS_READY => 'success',
                        ContentPublication::STATUS_FAILED => 'danger',
                        ContentPublication::STATUS_RUNNING => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('live')->label(__('admin.packs.live'))->boolean()->state(fn (ContentPublication $record): bool => $record->content_pack_id !== null && $record->content_pack_id === self::liveId()),
                TextColumn::make('requestedBy.name')->label(__('admin.publications.by')),
                TextColumn::make('note')->label(__('admin.publications.note'))->limit(40)->tooltip(fn (ContentPublication $record): ?string => $record->note),
                TextColumn::make('failed_step')->label(__('admin.publications.stopped_at'))
                    ->state(fn (ContentPublication $record): ?string => $record->report['failed_step'] ?? null)
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : __("admin.publications.steps.{$state}")),
                TextColumn::make('took')->label(__('admin.publications.took'))
                    ->state(fn (ContentPublication $record): ?string => $record->started_at !== null && $record->finished_at !== null ? $record->started_at->diffInSeconds($record->finished_at).' s' : null),
                TextColumn::make('created_at')->label(__('admin.publications.asked'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('report')
                    ->label(__('admin.publications.report'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->modalHeading(fn (ContentPublication $record): string => __('admin.publications.report_heading', ['number' => $record->number]))
                    ->modalContent(fn (ContentPublication $record) => view('filament.publications.report', ['publication' => $record, 'live' => $record->content_pack_id !== null && $record->content_pack_id === self::liveId()]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.publications.close'))
                    ->modalWidth('4xl'),
                Action::make('activate')
                    ->label(__('admin.packs.activate'))
                    ->icon(Heroicon::OutlinedBolt)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ContentPublication $record): string => __('admin.packs.activate_heading', ['number' => $record->number]))
                    ->modalDescription(__('admin.packs.activate_description'))
                    ->visible(fn (ContentPublication $record): bool => self::mayPublish() && $record->status === ContentPublication::STATUS_READY && $record->content_pack_id !== self::liveId())
                    ->action(fn (ContentPublication $record) => self::activate($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContentPublications::route('/'),
        ];
    }

    /** Whether the admin who is signed in may ask for a pack and make it live: a publisher, and nobody else. */
    public static function mayPublish(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isPublisher();
    }

    /** The pack that is live, asked every time: a table that was built before a pack was made live is shown after it, and must not still say the old one is. */
    private static function liveId(): ?int
    {
        return app(ContentPacks::class)->active()?->id;
    }

    /** The button is only shown to a publisher, but the request is checked as well: a button that is hidden is not a lock. */
    private static function activate(ContentPublication $publication): void
    {
        abort_unless(self::mayPublish(), 403);

        $pack = $publication->pack;

        if (! $pack instanceof ContentPack || $publication->status !== ContentPublication::STATUS_READY) {
            return;
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        try {
            app(ContentPublisher::class)->activate($pack, $admin);
        } catch (ContentPackInvalid $problem) {
            Notification::make()
                ->danger()
                ->title(__('admin.packs.refused'))
                ->body(implode("\n", array_slice(array_map(fn (array $issue): string => "{$issue['file']}: {$issue['message']}", $problem->result->errors()), 0, 5)))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->success()->title(__('admin.packs.activated', ['number' => $pack->number]))->send();
    }
}
