<?php

declare(strict_types=1);

namespace App\Filament\Resources\Players;

use App\Content\Catalog;
use App\Content\ContentException;
use App\Content\Rules;
use App\Filament\Concerns\ForAGame;
use App\Filament\Resources\Players\Pages\ListPlayers;
use App\Filament\Resources\Players\Pages\ViewPlayer;
use App\Models\AccountIdentity;
use App\Models\Admin;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\PlayerLevels;
use App\Support\I18nText;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

/**
 * The accounts of the game that is open in the panel: who they are, how far they got and what they paid, to look at and to take away as a CSV file. Nothing here changes an
 * account. It shows e-mail addresses, so only a publisher of the game sees it, and a file that was taken is written in the trail of the panel.
 */
class PlayerResource extends Resource
{
    use ForAGame;

    public const TYPE_GUEST = 'guest';

    public const TYPE_SECURED = 'secured';

    /** How long without a visit makes an account inactive, in days. */
    public const INACTIVE_DAYS = 30;

    /** How many of the latest purchases and postings of the ledger the page of an account lists. */
    public const HISTORY_LINES = 20;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'players';

    protected static ?int $navigationSort = -1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('admin.players.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.players.many');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.players.nav');
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * The accounts of the game (the scope of the game takes care of that) with what the list shows, as columns of the same query: the experience and the balances of the
     * wallet, an e-mail of a way of signing in, and the number of cars and of paid purchases. They are columns, not relations, so that the list can sort by them and the CSV
     * file can be read line by line.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        $wallet = fn (string $column) => Wallet::query()->select($column)->whereColumn('wallets.user_id', 'users.id');

        return parent::getEloquentQuery()
            ->select('users.*')
            ->addSelect([
                'xp' => $wallet('xp'),
                'cash_cents' => $wallet('cash_cents'),
                'premium_coins' => $wallet('premium_coins'),
                'identity_email' => AccountIdentity::query()->select('email')->whereColumn('account_identities.user_id', 'users.id')->whereNotNull('email')->orderBy('account_identities.id')->limit(1),
            ])
            ->withCount([
                'cars',
                'purchases as paid_purchases_count' => fn (Builder $query) => $query->where('status', Purchase::STATUS_PAID),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.players.csv_hint'))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')->label(__('admin.players.id'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->label(__('admin.players.name'))->searchable()->sortable(),
                TextColumn::make('account')
                    ->label(__('admin.players.account'))
                    ->badge()
                    ->state(fn (User $record): string => $record->isGuest() ? self::TYPE_GUEST : self::TYPE_SECURED)
                    ->formatStateUsing(fn (string $state): string => __("admin.players.type.{$state}"))
                    ->color(fn (string $state): string => $state === self::TYPE_GUEST ? 'gray' : 'success'),
                TextColumn::make('email')
                    ->label(__('admin.players.email'))
                    ->state(fn (User $record): ?string => $record->email ?: $record->getAttribute('identity_email'))
                    ->placeholder('-')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Lower case on both sides: LIKE does not ignore case in every database.
                        $term = '%'.mb_strtolower($search).'%';

                        return $query->where(fn (Builder $inner) => $inner
                            ->whereRaw('lower(users.email) like ?', [$term])
                            ->orWhereHas('identities', fn (Builder $identities) => $identities->whereRaw('lower(email) like ?', [$term])));
                    }),
                TextColumn::make('level')
                    ->label(__('admin.players.level'))
                    ->state(fn (User $record): int|string => self::level((int) $record->getAttribute('xp')))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('xp', $direction)),
                TextColumn::make('cash_cents')->label(__('admin.players.cash'))->state(fn (User $record): string => self::cash((int) $record->getAttribute('cash_cents')))->sortable(),
                TextColumn::make('premium_coins')->label(self::coinsLabel())->numeric()->sortable()->toggleable(),
                TextColumn::make('cars_count')->label(__('admin.players.cars'))->numeric()->sortable(),
                TextColumn::make('paid_purchases_count')->label(__('admin.players.paid'))->numeric()->sortable(),
                TextColumn::make('locale')->label(__('admin.players.locale'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('admin.players.created'))->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('last_seen_at')->label(__('admin.players.seen'))->dateTime('Y-m-d H:i')->sortable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('account')
                    ->label(__('admin.players.account'))
                    ->options([self::TYPE_GUEST => __('admin.players.type.guest'), self::TYPE_SECURED => __('admin.players.type.secured')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        self::TYPE_GUEST => $query->whereNull('users.claimed_at'),
                        self::TYPE_SECURED => $query->whereNotNull('users.claimed_at'),
                        default => $query,
                    }),
                SelectFilter::make('activity')
                    ->label(__('admin.players.filter.activity'))
                    ->options(['day' => __('admin.players.filter.day'), 'week' => __('admin.players.filter.week'), 'inactive' => __('admin.players.filter.inactive', ['days' => self::INACTIVE_DAYS])])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'day' => $query->where('users.last_seen_at', '>=', now()->subDay()),
                        'week' => $query->where('users.last_seen_at', '>=', now()->subDays(7)),
                        'inactive' => $query->where(fn (Builder $inner) => $inner->whereNull('users.last_seen_at')->orWhere('users.last_seen_at', '<', now()->subDays(self::INACTIVE_DAYS))),
                        default => $query,
                    }),
                Filter::make('new_week')
                    ->label(__('admin.players.filter.new_week'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('users.created_at', '>=', now()->subDays(7))),
                TernaryFilter::make('paid')
                    ->label(__('admin.players.filter.paid'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('purchases', fn (Builder $purchases) => $purchases->where('status', Purchase::STATUS_PAID)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('purchases', fn (Builder $purchases) => $purchases->where('status', Purchase::STATUS_PAID)),
                    ),
                SelectFilter::make('locale')->label(__('admin.players.locale'))->options(config('ride.locale_names')),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.players.section.account'))->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.players.name')),
                TextEntry::make('account')
                    ->label(__('admin.players.account'))
                    ->badge()
                    ->state(fn (User $record): string => $record->isGuest() ? self::TYPE_GUEST : self::TYPE_SECURED)
                    ->formatStateUsing(fn (string $state): string => __("admin.players.type.{$state}"))
                    ->color(fn (string $state): string => $state === self::TYPE_GUEST ? 'gray' : 'success'),
                TextEntry::make('email')->label(__('admin.players.email'))->state(fn (User $record): ?string => $record->email ?: $record->getAttribute('identity_email'))->placeholder('-'),
                TextEntry::make('login_methods')
                    ->label(__('admin.players.login_methods'))
                    ->state(fn (User $record): string => $record->identities()->orderBy('id')->pluck('provider')->unique()->implode(', ') ?: '-'),
                TextEntry::make('locale')->label(__('admin.players.locale'))->placeholder('-'),
                TextEntry::make('id')->label(__('admin.players.id')),
                TextEntry::make('created_at')->label(__('admin.players.created'))->dateTime('Y-m-d H:i'),
                TextEntry::make('last_seen_at')->label(__('admin.players.seen'))->dateTime('Y-m-d H:i')->placeholder('-'),
                TextEntry::make('visits')
                    ->label(__('admin.players.visits'))
                    ->state(fn (User $record): string => $record->visits()->count().' / '.$record->visits()->where('at', '>=', now()->subDays(7))->count()),
                TextEntry::make('terms_accepted_at')->label(__('admin.players.terms'))->state(fn (User $record): string => $record->terms_accepted_at === null ? '-' : $record->terms_accepted_at->format('Y-m-d').($record->terms_version ? " ({$record->terms_version})" : '')),
                TextEntry::make('age_confirmed_at')->label(__('admin.players.age'))->dateTime('Y-m-d')->placeholder('-'),
                TextEntry::make('recovery')->label(__('admin.players.recovery'))->state(fn (User $record): string => $record->hasRecoveryCode() ? __('admin.players.yes') : __('admin.players.no')),
            ]),
            Section::make(__('admin.players.section.progress'))->columns(4)->schema([
                TextEntry::make('level')->label(__('admin.players.level'))->state(fn (User $record): int|string => self::level((int) $record->getAttribute('xp'))),
                TextEntry::make('xp')->label(__('admin.players.xp'))->numeric()->placeholder('0'),
                TextEntry::make('cash_cents')->label(__('admin.players.cash'))->state(fn (User $record): string => self::cash((int) $record->getAttribute('cash_cents'))),
                TextEntry::make('premium_coins')->label(self::coinsLabel())->numeric()->placeholder('0'),
            ]),
            View::make('filament.players.history')->viewData(fn (User $record): array => self::history($record)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlayers::route('/'),
            'view' => ViewPlayer::route('/{record}'),
        ];
    }

    /** The cash of the game written for a person: dollars with cents. */
    public static function cash(int $cents): string
    {
        return '$'.number_format($cents / 100, 2, ',', ' ');
    }

    /** The name of the premium coin in the rules of the game, or a plain name when the rules have none. */
    public static function coinsLabel(): string
    {
        try {
            return app(Rules::class)->premiumCurrency()['name'] ?? __('admin.players.coins');
        } catch (ContentException) {
            return __('admin.players.coins');
        }
    }

    /** The level of an experience in this game, or a dash when the game has no live pack to say. */
    public static function level(int $xp): int|string
    {
        return app(PlayerLevels::class)->of($xp) ?? '-';
    }

    /**
     * What the page of an account lists under its numbers: the cars, the latest purchases for real money, and the latest postings of the ledger.
     *
     * @return array{cars: list<array<string, mixed>>, purchases: list<array<string, mixed>>, ledger: list<array<string, mixed>>}
     */
    public static function history(User $player): array
    {
        try {
            $catalog = app(Catalog::class);
        } catch (ContentException) {
            $catalog = null;
        }

        return [
            'cars' => $player->cars()->orderByDesc('level')->orderBy('id')->get()->map(function (Car $car) use ($catalog, $player): array {
                $vehicle = $catalog?->vehicle((string) $car->vehicle_slug);

                return [
                    'name' => isset($vehicle['name']) ? I18nText::from($vehicle['name'])->for() : (string) $car->vehicle_slug,
                    'level' => (int) $car->level,
                    'km' => number_format($car->mileage_m / 1000, 1, ',', ' '),
                    'condition' => (int) round($car->conditionPpm() / 10_000),
                    'value' => $car->value_cents === null ? '-' : self::cash((int) $car->value_cents),
                    'selected' => $player->selected_car_id === $car->id,
                ];
            })->all(),
            'purchases' => $player->purchases()->orderByDesc('id')->limit(self::HISTORY_LINES)->get()->map(fn (Purchase $purchase): array => [
                'when' => $purchase->created_at?->format('Y-m-d H:i') ?? '-',
                'product' => (string) $purchase->product,
                'coins' => (int) $purchase->coins,
                'amount' => number_format($purchase->amount_minor / 100, 2, ',', ' ').' '.$purchase->currency,
                'status' => Lang::has("admin.players.purchase_status.{$purchase->status}") ? __("admin.players.purchase_status.{$purchase->status}") : (string) $purchase->status,
            ])->all(),
            'ledger' => $player->ledgerEntries()->orderByDesc('id')->limit(self::HISTORY_LINES)->get()->map(fn (LedgerEntry $entry): array => [
                'when' => $entry->created_at?->format('Y-m-d H:i') ?? '-',
                'currency' => $entry->currency === Ledger::CASH ? __('admin.players.currency.cash') : __('admin.players.currency.premium'),
                'reason' => (string) $entry->reason,
                'change' => $entry->currency === Ledger::CASH
                    ? ($entry->delta >= 0 ? '+' : '-').self::cash(abs($entry->delta))
                    : ($entry->delta >= 0 ? '+' : '-').abs($entry->delta),
                'balance' => $entry->currency === Ledger::CASH ? self::cash($entry->balance_after) : (string) $entry->balance_after,
            ])->all(),
        ];
    }
}
