<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Definitions\SchemaChoices;
use App\Filament\Resources\Definitions\Forms\DefinitionFields;
use App\Models\ContentDefinition;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * The tutorial as a form: the arrangements of the first run, and the steps, each with the fact that starts it and the one that ends it, the messages of the mentor
 * and what the driver is given. What the schema asks is its to say when the draft is saved.
 */
final class TutorialFields
{
    /** @return list<mixed> */
    public static function schema(): array
    {
        $targets = SchemaChoices::targets();
        $conditions = [...SchemaChoices::conditions(), ...array_map(fn (string $name): string => $name.':', SchemaChoices::conditionParameters())];
        $jobs = SchemaChoices::arrangedJobs();

        return [
            Section::make(__('admin.tutorial.script'))->description(__('admin.tutorial.script_hint'))->schema([
                Grid::make(4)->schema([
                    TextInput::make('version')->label(__('admin.tutorial.version'))->helperText(__('admin.tutorial.version_hint'))->required()->regex('/^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$/'),
                    TextInput::make('first_run_fuel_ml')->label(__('admin.tutorial.first_run_fuel_ml'))->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('breakdown_lap')->label(__('admin.tutorial.breakdown_lap'))->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('first_mount_ms')->label(__('admin.tutorial.first_mount_ms'))->helperText(__('admin.tutorial.first_mount_ms_hint'))->numeric()->integer()->minValue(1)->required(),
                ]),
                Grid::make(4)->schema([
                    TextInput::make('covers_up_to')->label(__('admin.tutorial.covers_up_to'))->helperText(__('admin.tutorial.covers_up_to_hint'))->numeric()->prefix('$')->minValue(0)->required(),
                ]),
                Fieldset::make(__('admin.tutorial.listing'))->schema([
                    Grid::make(5)->schema([
                        Select::make('listing_vehicle')->label(__('admin.tutorial.listing_vehicle'))->helperText(__('admin.tutorial.listing_hint'))->options(fn (): array => ContentOptions::cars())->placeholder('-')->searchable(),
                        TextInput::make('listing_age_years')->label(__('admin.tutorial.listing_age_years'))->numeric()->integer()->minValue(1),
                        TextInput::make('listing_mileage_km')->label(__('admin.tutorial.listing_mileage_km'))->numeric()->integer()->minValue(1),
                        TextInput::make('listing_condition')->label(__('admin.tutorial.listing_condition'))->numeric()->suffix('%')->minValue(0.0001)->maxValue(100),
                        TextInput::make('listing_price')->label(__('admin.tutorial.listing_price'))->numeric()->prefix('$')->minValue(0.01),
                    ]),
                ])->columns(1),
            ]),
            Section::make(__('admin.tutorial.steps'))->description(__('admin.tutorial.steps_hint'))->schema([
                Repeater::make('steps')
                    ->hiddenLabel()
                    ->minItems(1)
                    ->collapsible()
                    ->collapsed()
                    ->reorderableWithButtons()
                    ->addActionLabel(__('admin.tutorial.step_add'))
                    ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('key')->label(__('admin.tutorial.key'))->required()->maxLength(64)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->distinct(),
                            Select::make('phase')->label(__('admin.tutorial.phase'))->helperText(__('admin.tutorial.phase_hint'))->options(array_combine(range(1, 4), range(1, 4)))->required(),
                            TextInput::make('sender')->label(__('admin.tutorial.sender'))->helperText(__('admin.tutorial.sender_hint'))->required()->regex('/^\+48 555 010 [0-9]{3}$/'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('trigger')->label(__('admin.tutorial.trigger'))->helperText(__('admin.tutorial.condition_hint'))->datalist($conditions)->required(),
                            TextInput::make('completion')->label(__('admin.tutorial.completion'))->helperText(__('admin.tutorial.condition_hint'))->datalist($conditions)->required(),
                            Select::make('target')->label(__('admin.tutorial.target'))->helperText(__('admin.tutorial.target_hint'))->options(array_combine($targets, $targets))->placeholder(__('admin.tutorial.no_target')),
                        ]),
                        Repeater::make('sms')
                            ->label(__('admin.tutorial.sms'))
                            ->helperText(__('admin.tutorial.sms_hint'))
                            ->maxItems(2)
                            ->collapsible()
                            ->addActionLabel(__('admin.tutorial.sms_add'))
                            ->schema([DefinitionFields::texts('', __('admin.tutorial.message'), long: true)]),
                        DefinitionFields::texts('reply', __('admin.tutorial.reply'), required: false),
                        Toggle::make('optional')->label(__('admin.tutorial.optional'))->helperText(__('admin.tutorial.optional_hint'))->inline(false),
                        DefinitionFields::texts('decline', __('admin.tutorial.decline'), required: false),
                        CheckboxList::make('arranges')->label(__('admin.tutorial.arranges'))->helperText(__('admin.tutorial.arranges_hint'))->options(array_combine($jobs, $jobs))->columns(5),
                        Fieldset::make(__('admin.tutorial.gift'))->schema([
                            Grid::make(3)->schema([
                                Select::make('gift_part')->label(__('admin.tutorial.gift_part'))->helperText(__('admin.tutorial.gift_hint'))->options(fn (): array => ContentOptions::parts())->placeholder('-')->searchable(),
                                Select::make('gift_rarity')->label(__('admin.tutorial.gift_rarity'))->options(array_combine(['common', 'uncommon', 'rare', 'epic', 'legendary'], ['common', 'uncommon', 'rare', 'epic', 'legendary']))->placeholder('common'),
                                TextInput::make('gift_count')->label(__('admin.tutorial.gift_count'))->numeric()->integer()->minValue(1)->maxValue(4),
                            ]),
                        ])->columns(1),
                        Fieldset::make(__('admin.tutorial.reward'))->schema([
                            Grid::make(5)->schema([
                                TextInput::make('reward_cash')->label(__('admin.tutorial.reward_cash'))->numeric()->prefix('$')->minValue(0.01),
                                TextInput::make('reward_car_xp')->label(__('admin.tutorial.reward_car_xp'))->numeric()->integer()->minValue(1),
                                TextInput::make('reward_xp')->label(__('admin.tutorial.reward_xp'))->numeric()->integer()->minValue(1),
                                Select::make('reward_part')->label(__('admin.tutorial.reward_part'))->options(fn (): array => ContentOptions::parts())->placeholder('-')->searchable(),
                                Select::make('reward_garage')->label(__('admin.tutorial.reward_garage'))->options(fn (): array => self::cities())->placeholder('-'),
                            ]),
                        ])->columns(1),
                    ]),
            ]),
        ];
    }

    /**
     * The cities of the rules as they are being worked on: the garage that a step gives is in one of them.
     *
     * @return array<string, string>
     */
    private static function cities(): array
    {
        $rules = ContentDefinition::query()->where('kind', ContentDefinition::KIND_RULES)->where('slug', 'starter')->with('latestVersion')->first();
        $options = [];

        foreach ($rules?->workingDocument()['regions'] ?? [] as $slug => $region) {
            $options[(string) $slug] = ContentOptions::name($region['name'] ?? [], (string) $slug)." ({$slug})";
        }

        return $options;
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): ?string
    {
        $key = is_string($state['key'] ?? null) ? trim($state['key']) : '';

        return $key === '' ? null : ($state['phase'] ?? '?').' - '.$key;
    }
}
