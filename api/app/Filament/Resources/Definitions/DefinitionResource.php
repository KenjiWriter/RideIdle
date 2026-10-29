<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions;

use App\Filament\Concerns\ForAGame;
use App\Models\ContentDefinition;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * What the resources of the cars and of the parts have in common: they are the definitions of one kind, listed with their place in the life of a definition and
 * whether a draft is waiting, and an admin makes and changes drafts in them. Nothing that is saved here reaches the game until a pack is made live that has it.
 */
abstract class DefinitionResource extends Resource
{
    use ForAGame;

    protected static ?string $model = ContentDefinition::class;

    /** The kind of definition that the resource is for (`ContentDefinition::KIND_*`). */
    abstract public static function kind(): string;

    /**
     * The columns that are about this kind of definition.
     *
     * @return list<TextColumn>
     */
    abstract protected static function kindColumns(): array;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', static::kind())->with('latestVersion')->withCount('versions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    /** Any admin makes drafts: they are only drafts. */
    public static function canCreate(): bool
    {
        return Auth::guard('admin')->check();
    }

    /** An archived definition is not changed: it is put back first. */
    public static function canEdit($record): bool
    {
        return $record->status !== ContentDefinition::STATUS_ARCHIVED;
    }

    /** Nothing is deleted from the list: a definition that was never published is deleted on its page, and one that was is withdrawn or archived. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slug')
            ->columns([
                TextColumn::make('slug')->label(__('admin.definitions.slug'))->searchable()->sortable(),
                TextColumn::make('title')->label(__('admin.definitions.name'))->state(fn (ContentDefinition $record): string => $record->title(str_replace('_', '-', app()->getLocale()))),
                ...static::kindColumns(),
                TextColumn::make('status')->label(__('admin.definitions.status'))->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __("admin.definitions.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        ContentDefinition::STATUS_ACTIVE => 'success',
                        ContentDefinition::STATUS_WITHDRAWN => 'warning',
                        ContentDefinition::STATUS_ARCHIVED => 'gray',
                        default => 'info',
                    }),
                IconColumn::make('draft')->label(__('admin.definitions.draft'))->boolean()
                    ->state(fn (ContentDefinition $record): bool => $record->hasDraft())
                    ->tooltip(fn (ContentDefinition $record): ?string => $record->hasDraft() ? __('admin.definitions.draft_since', ['when' => $record->draft_saved_at?->format('Y-m-d H:i')]) : null),
                TextColumn::make('versions_count')->label(__('admin.definitions.versions'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.definitions.status'))->options(array_combine(ContentDefinition::STATUSES, array_map(fn (string $status): string => __("admin.definitions.statuses.{$status}"), ContentDefinition::STATUSES))),
                Filter::make('draft')->label(__('admin.definitions.has_draft'))->query(fn (Builder $query): Builder => $query->whereNotNull('draft')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
