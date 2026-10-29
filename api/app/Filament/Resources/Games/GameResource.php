<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games;

use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Models\Admin;
use App\Models\Game;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The games that this installation runs: their name, their domain and the colour of their title. Only an admin who may work in every game sees them, because making a
 * game and giving it a domain is about the installation, not about one game. A game is never deleted here (the accounts and the content that belong to it would be
 * left without one), and which one is the default is not changed from the panel.
 */
class GameResource extends Resource
{
    /** What a slug looks like: lower case words joined by dashes, the first one a letter. */
    public const SLUG = '/^[a-z][a-z0-9-]{1,31}$/';

    /** A host in lower case: the names of the parts of a domain joined by dots. */
    public const HOST = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    protected static ?string $model = Game::class;

    protected static ?string $slug = 'games';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.games.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('admin.games.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.games.many');
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->all_games;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('slug')
                ->label(__('admin.games.slug'))
                ->helperText(__('admin.games.slug_hint'))
                ->required()
                ->regex(self::SLUG)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Game $record): bool => $record !== null)
                ->dehydrated(fn (?Game $record): bool => $record === null),
            TextInput::make('name')->label(__('admin.games.name'))->required()->maxLength(64),
            TextInput::make('domain')
                ->label(__('admin.games.domain'))
                ->helperText(__('admin.games.domain_hint'))
                ->maxLength(190)
                ->regex(self::HOST)
                ->unique(ignoreRecord: true),
            Select::make('locale')->label(__('admin.games.locale'))->options(config('ride.locale_names'))->required()->default('en'),
            TextInput::make('theme_color')->label(__('admin.games.theme_color'))->helperText(__('admin.games.theme_color_hint'))->required()->regex('/^#[0-9a-fA-F]{6}$/')->default('#0e1013'),
            Select::make('status')
                ->label(__('admin.games.status'))
                ->helperText(__('admin.games.status_hint'))
                ->options([Game::STATUS_LIVE => __('admin.games.status_live'), Game::STATUS_DRAFT => __('admin.games.status_draft')])
                ->required()
                ->default(Game::STATUS_DRAFT),
            Toggle::make('is_default')->label(__('admin.games.is_default'))->helperText(__('admin.games.is_default_hint'))->disabled()->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label(__('admin.games.name'))->searchable(),
                TextColumn::make('slug')->label(__('admin.games.slug'))->badge()->color('gray'),
                TextColumn::make('domain')->label(__('admin.games.domain'))->placeholder('-'),
                TextColumn::make('status')->label(__('admin.games.status'))->badge()->formatStateUsing(fn (string $state): string => __("admin.games.status_{$state}"))->color(fn (string $state): string => $state === Game::STATUS_LIVE ? 'success' : 'warning'),
                IconColumn::make('is_default')->label(__('admin.games.is_default'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'create' => CreateGame::route('/create'),
            'edit' => EditGame::route('/{record}/edit'),
        ];
    }
}
