<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use App\Content\Catalog;
use App\Filament\Support\FieldHelp;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** The form of a part. The statistics are lines of a name and a number, because which ones a part has depends on its slot and the schema leaves that open. */
final class PartForm
{
    /** The statistics that parts have had so far, suggested when a name is typed; any name that the schema allows can be written. */
    private const KNOWN_STATS = ['grip', 'handling', 'braking', 'clearance', 'power_mult', 'lag', 'fuel_mult', 'engine_load', 'boost_gain', 'mass_kg', 'launch', 'top_speed_cap_kmh', 'capacity_l', 'power_kw', 'engine_mass_kg'];

    /**
     * The slots are the ones of the model of the game as it is being worked on, and what a part has depends on the role of its slot: the kind of tires for the slot
     * that is the tires, the layout and the displacement for the slot that is the engine.
     *
     * @return list<mixed>
     */
    public static function schema(): array
    {
        $model = app(ContentDrafts::class)->workingModel();
        $tires = fn (Get $get): bool => $model->is((string) $get('slot'), 'tires');
        $engine = fn (Get $get): bool => $model->is((string) $get('slot'), 'engine');
        $slots = [];

        foreach ($model->slots() as ['key' => $key, 'role' => $role]) {
            $slots[$key] = $role === null || $role === $key ? $key : "{$key} ({$role})";
        }

        return [
            FieldHelp::guide('part'),
            Section::make(__('admin.definitions.sections.identification'))->schema([
                DefinitionFields::slug(),
                DefinitionFields::texts('name', __('admin.definitions.name'), 'definition.name'),
            ]),
            DefinitionFields::image(),
            Section::make(__('admin.definitions.sections.part'))->schema([
                Grid::make(4)->schema([
                    Select::make('slot')->helpKey('part.slot')->options($slots)->required()->live(),
                    Select::make('tire_kind')->helpKey('part.tire_kind')->options(self::options(['all_season', 'street', 'slick', 'rain', 'offroad', 'winter']))->visible($tires)->required($tires),
                    Select::make('tier')->helpKey('part.tier')->options(array_combine(range(1, 10), range(1, 10)))->required(),
                    TextInput::make('price')->helpKey('part.price')->numeric()->prefix('$')->required(),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('engine_layout')->helpKey('part.engine_layout')->visible($engine)->required($engine),
                    TextInput::make('engine_displacement_l')->helpKey('part.engine_displacement_l')->numeric()->visible($engine)->required($engine),
                ]),
            ]),
            Section::make(__('admin.definitions.stats'))->schema([
                Repeater::make('stats')
                    ->helpKey('part.stats')
                    ->hiddenLabel()
                    ->minItems(1)
                    ->addActionLabel(__('admin.definitions.stat_add'))
                    ->columns(2)
                    ->schema([
                        Select::make('key')->helpKey('part.stat_name')->options(fn (): array => FieldHelp::options('part.stat_name', self::statNames()))->searchable()->required()->distinct(),
                        TextInput::make('value')->helpKey('part.stat_value')->numeric()->required(),
                    ]),
            ]),
            Section::make(__('admin.definitions.sections.requirements'))->schema([
                Grid::make(3)->schema([
                    TextInput::make('requires_min_cylinders')->helpKey('part.requires_min_cylinders')->numeric(),
                    Toggle::make('requires_booster')->helpKey('part.requires_booster'),
                    Select::make('fits_powertrain')->helpKey('part.fits_powertrain')->options(self::options(['ice', 'ev']))->placeholder('-'),
                ]),
                CheckboxList::make('fits_classes')->helpKey('part.fits_classes')->options(self::options(['hatchback', 'hot_hatch', 'sedan', 'coupe', 'muscle', 'pickup', 'van', 'gt', 'super', 'hyper']))->columns(5),
            ])->collapsed(),
        ];
    }

    /** @return list<string> */
    private static function statNames(): array
    {
        $names = self::KNOWN_STATS;

        foreach (app(Catalog::class)->parts() as $part) {
            array_push($names, ...array_keys($part['stats'] ?? []));
        }

        // A statistic that only a draft has is a name too, or the line that has it would be an empty choice.
        foreach (ContentDefinition::query()->where('kind', ContentDefinition::KIND_PART)->whereNotNull('draft')->get() as $draft) {
            array_push($names, ...array_keys((array) ($draft->draftDocument()['stats'] ?? [])));
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values): array
    {
        return array_combine($values, $values);
    }
}
