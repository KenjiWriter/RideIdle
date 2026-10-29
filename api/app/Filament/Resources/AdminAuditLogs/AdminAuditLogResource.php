<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminAuditLogs;

use App\Filament\Resources\AdminAuditLogs\Pages\ManageAdminAuditLogs;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Game;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** The trail of the panel: who signed in, who made which pack live, who changed an account. Only publishers read it, and nobody changes it. */
class AdminAuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getModelLabel(): string
    {
        return __('admin.audit.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.audit.many');
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isPublisher();
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
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.audit.when'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('email')->label(__('admin.audit.who'))->searchable()->placeholder('-'),
                TextColumn::make('action')->label(__('admin.audit.action'))->badge()->formatStateUsing(fn (string $state): string => self::actionName($state))->searchable(),
                TextColumn::make('game.name')->label(__('admin.audit.game'))->placeholder('-')->toggleable(),
                TextColumn::make('subject')->label(__('admin.audit.subject'))->searchable()->placeholder('-'),
                TextColumn::make('context')->label(__('admin.audit.context'))->state(fn (AdminAuditLog $record): string => $record->context === null ? '' : (string) json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->limit(60)->tooltip(fn (AdminAuditLog $record): ?string => $record->context === null ? null : (string) json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)),
                TextColumn::make('ip')->label(__('admin.audit.ip'))->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('action')->label(__('admin.audit.action'))->options(fn (): array => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action')->mapWithKeys(fn (string $action): array => [$action => self::actionName($action)])->all()),
                SelectFilter::make('game_id')->label(__('admin.audit.game'))->options(fn (): array => Game::query()->orderBy('id')->pluck('name', 'id')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdminAuditLogs::route('/'),
        ];
    }

    /** The name of an action in the language of the panel, or the action as it is written when the panel does not know it. */
    private static function actionName(string $action): string
    {
        $key = "admin.actions.{$action}";
        $name = __($key);

        return $name === $key ? $action : $name;
    }
}
