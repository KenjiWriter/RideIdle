<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentPacks;

use App\Content\ContentPackInvalid;
use App\Filament\Resources\ContentPacks\Pages\ManageContentPacks;
use App\Models\Admin;
use App\Models\ContentPack;
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
 * The packs of the content that are in the database. They are looked at here, and a publisher makes one of them live (also to go back to an earlier one); the
 * packs themselves are made by content:import, and a pack is never changed or deleted.
 */
class ContentPackResource extends Resource
{
    protected static ?string $model = ContentPack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.packs.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.packs.many');
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('versions'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.packs.number'))->searchable()->sortable(),
                IconColumn::make('live')->label(__('admin.packs.live'))->boolean()->state(fn (ContentPack $record): bool => $record->id === self::liveId()),
                TextColumn::make('checksum')->label(__('admin.packs.checksum'))->limit(12)->tooltip(fn (ContentPack $record): string => $record->checksum)->copyable()->copyableState(fn (ContentPack $record): string => $record->checksum),
                TextColumn::make('versions_count')->label(__('admin.packs.documents'))->sortable(),
                TextColumn::make('created_at')->label(__('admin.packs.made'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('export')
                    ->label(__('admin.packs.export'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (ContentPack $record) => response()->streamDownload(function () use ($record): void {
                        echo app(ContentPublisher::class)->export($record);
                    }, "pack-{$record->number}.json", ['Content-Type' => 'application/json'])),
                Action::make('activate')
                    ->label(__('admin.packs.activate'))
                    ->icon(Heroicon::OutlinedBolt)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ContentPack $record): string => __('admin.packs.activate_heading', ['number' => $record->number]))
                    ->modalDescription(__('admin.packs.activate_description'))
                    ->visible(fn (ContentPack $record): bool => self::mayActivate() && $record->id !== self::liveId())
                    ->action(fn (ContentPack $record) => self::activate($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContentPacks::route('/'),
        ];
    }

    /** The pack that is live, asked every time: a table that was built before a pack was made live is shown after it, and must not still say the old one is. */
    private static function liveId(): ?int
    {
        return app(ContentPacks::class)->active()?->id;
    }

    /** Whether the admin who is signed in may make a pack live: a publisher, and nobody else. */
    private static function mayActivate(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isPublisher();
    }

    /** The button is only shown to a publisher, but the request is checked as well: a button that is hidden is not a lock. */
    private static function activate(ContentPack $pack): void
    {
        abort_unless(self::mayActivate(), 403);

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
