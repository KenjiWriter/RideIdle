<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Definitions\SchemaChoices;
use App\Filament\Resources\Definitions\Forms\DefinitionFields;
use App\Filament\Support\FieldHelp;
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
            FieldHelp::guide('tutorial'),
            Section::make(__('admin.tutorial.script'))->description(__('admin.tutorial.script_hint'))->schema([
                Grid::make(4)->schema([
                    TextInput::make('version')->helpKey('tutorial.version')->required()->regex('/^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$/'),
                    TextInput::make('first_run_fuel_ml')->helpKey('tutorial.first_run_fuel_ml')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('breakdown_lap')->helpKey('tutorial.breakdown_lap')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('first_mount_ms')->helpKey('tutorial.first_mount_ms')->numeric()->integer()->minValue(1)->required(),
                ]),
                Grid::make(4)->schema([
                    TextInput::make('covers_up_to')->helpKey('tutorial.covers_up_to')->numeric()->prefix('$')->minValue(0)->required(),
                ]),
                Fieldset::make(__('admin.tutorial.listing'))->schema([
                    Grid::make(5)->schema([
                        Select::make('listing_vehicle')->helpKey('tutorial.listing_vehicle')->options(fn (): array => ContentOptions::cars())->placeholder('-')->searchable(),
                        TextInput::make('listing_age_years')->helpKey('tutorial.listing_age_years')->numeric()->integer()->minValue(1),
                        TextInput::make('listing_mileage_km')->helpKey('tutorial.listing_mileage_km')->numeric()->integer()->minValue(1),
                        TextInput::make('listing_condition')->helpKey('tutorial.listing_condition')->numeric()->suffix('%')->minValue(0.0001)->maxValue(100),
                        TextInput::make('listing_price')->helpKey('tutorial.listing_price')->numeric()->prefix('$')->minValue(0.01),
                    ]),
                ])->columns(1),
            ]),
            Section::make(__('admin.tutorial.steps'))->description(__('admin.tutorial.steps_hint'))->schema([
                Repeater::make('steps')
                    ->helpKey('tutorial.steps')
                    ->hiddenLabel()
                    ->minItems(1)
                    ->collapsible()
                    ->collapsed()
                    ->reorderableWithButtons()
                    ->addActionLabel(__('admin.tutorial.step_add'))
                    ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('key')->helpKey('tutorial.key')->required()->maxLength(64)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->distinct(),
                            Select::make('phase')->helpKey('tutorial.phase')->options(array_combine(range(1, 4), range(1, 4)))->required(),
                            TextInput::make('sender')->helpKey('tutorial.sender')->required()->regex('/^\+48 555 010 [0-9]{3}$/'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('trigger')->helpKey('tutorial.trigger')->datalist($conditions)->required(),
                            TextInput::make('completion')->helpKey('tutorial.completion')->datalist($conditions)->required(),
                            Select::make('target')->helpKey('tutorial.target')->options(array_combine($targets, $targets))->placeholder(__('admin.tutorial.no_target')),
                        ]),
                        DefinitionFields::texts('goal', __('admin.tutorial.goal'), 'tutorial.goal'),
                        DefinitionFields::texts('waiting', __('admin.tutorial.waiting'), 'tutorial.waiting', required: false),
                        Repeater::make('sms')
                            ->helpKey('tutorial.sms')
                            ->maxItems(2)
                            ->collapsible()
                            ->addActionLabel(__('admin.tutorial.sms_add'))
                            ->schema([DefinitionFields::texts('', __('admin.tutorial.message'), 'tutorial.message', long: true)]),
                        DefinitionFields::texts('reply', __('admin.tutorial.reply'), 'tutorial.reply', required: false),
                        Toggle::make('optional')->helpKey('tutorial.optional')->inline(false),
                        DefinitionFields::texts('decline', __('admin.tutorial.decline'), 'tutorial.decline', required: false),
                        CheckboxList::make('arranges')->helpKey('tutorial.arranges')->options(array_combine($jobs, $jobs))->columns(5),
                        Fieldset::make(__('admin.tutorial.gift'))->schema([
                            Grid::make(3)->schema([
                                Select::make('gift_part')->helpKey('tutorial.gift_part')->options(fn (): array => ContentOptions::parts())->placeholder('-')->searchable(),
                                Select::make('gift_rarity')->helpKey('tutorial.gift_rarity')->options(array_combine(['common', 'uncommon', 'rare', 'epic', 'legendary'], ['common', 'uncommon', 'rare', 'epic', 'legendary']))->placeholder('common'),
                                TextInput::make('gift_count')->helpKey('tutorial.gift_count')->numeric()->integer()->minValue(1)->maxValue(4),
                            ]),
                        ])->columns(1),
                        Fieldset::make(__('admin.tutorial.reward'))->schema([
                            Grid::make(5)->schema([
                                TextInput::make('reward_cash')->helpKey('tutorial.reward_cash')->numeric()->prefix('$')->minValue(0.01),
                                TextInput::make('reward_car_xp')->helpKey('tutorial.reward_car_xp')->numeric()->integer()->minValue(1),
                                TextInput::make('reward_xp')->helpKey('tutorial.reward_xp')->numeric()->integer()->minValue(1),
                                Select::make('reward_part')->helpKey('tutorial.reward_part')->options(fn (): array => ContentOptions::parts())->placeholder('-')->searchable(),
                                Select::make('reward_garage')->helpKey('tutorial.reward_garage')->options(fn (): array => self::cities())->placeholder('-'),
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
