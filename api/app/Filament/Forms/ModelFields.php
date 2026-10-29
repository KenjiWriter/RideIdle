<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\GameModel;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * The model of the game as a form: the slots that parts go into, each with its key, the role that the formulas know it by (or none) and whether the garage hides it,
 * and the statistics of a car that the screens show, in the order of the list.
 */
final class ModelFields
{
    /** @return list<mixed> */
    public static function schema(): array
    {
        return [
            Text::make(__('admin.model.hint')),
            Section::make(__('admin.model.slots'))
                ->description(__('admin.model.slots_hint'))
                ->schema([
                    Repeater::make('slots')
                        ->hiddenLabel()
                        ->reorderable()
                        ->minItems(1)
                        ->maxItems(24)
                        ->addActionLabel(__('admin.model.slot_add'))
                        ->itemLabel(fn (array $state): ?string => $state['key'] ?? null)
                        ->columns(3)
                        ->schema([
                            TextInput::make('key')->label(__('admin.model.slot_key'))->helperText(__('admin.model.slot_key_hint'))->required()->maxLength(32)->regex('/^[a-z][a-z0-9_]*$/')->distinct(),
                            Select::make('role')->label(__('admin.model.slot_role'))->options(self::roles())->placeholder(__('admin.model.no_role')),
                            Toggle::make('hidden')->label(__('admin.model.slot_hidden'))->inline(false),
                        ]),
                ]),
            Section::make(__('admin.model.stats'))
                ->description(__('admin.model.stats_hint'))
                ->schema([
                    Repeater::make('stats')
                        ->hiddenLabel()
                        ->reorderable()
                        ->minItems(1)
                        ->maxItems(count(GameModel::STATS))
                        ->addActionLabel(__('admin.model.stat_add'))
                        ->itemLabel(fn (array $state): ?string => isset($state['key']) ? __("admin.model.stat_names.{$state['key']}") : null)
                        ->schema([
                            Select::make('key')->label(__('admin.model.stat'))->options(self::stats())->required()->distinct(),
                        ]),
                ]),
        ];
    }

    /** @return array<string, string> the roles of a slot, by what they do */
    private static function roles(): array
    {
        return array_combine(GameModel::ROLES, array_map(static fn (string $role): string => __("admin.model.roles.{$role}"), GameModel::ROLES));
    }

    /** @return array<string, string> the statistics that the formulas make, by their names */
    private static function stats(): array
    {
        return array_combine(GameModel::STATS, array_map(static fn (string $stat): string => __("admin.model.stat_names.{$stat}"), GameModel::STATS));
    }
}
