<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Definitions\SchemaChoices;
use App\Content\TrackAdvisor;
use App\Content\TrackConverter;
use App\Content\TrackNumbers;
use App\Content\TrackPreview;
use App\Content\Units\Measures;
use App\Filament\Forms\Components\MapPoint;
use App\Filament\Resources\Definitions\Forms\DefinitionFields;
use App\Filament\Support\FieldHelp;
use App\Services\ContentDrafts;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The world of the rules as a form: the cities (with their place on the map and their weather), the roads between them, and the tracks. What the schema and the
 * rules of the world ask is theirs to say, and they say it when the draft is saved.
 */
final class WorldFields
{
    private const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param  list<string>  $weather  the weather that the rules know
     * @param  array<string, mixed>  $document  the rules that are being worked on: the units of the game and the numbers of the model come from them
     * @return list<mixed>
     */
    public static function schema(array $weather, array $document = []): array
    {
        $measures = Measures::fromRules($document);

        return [
            FieldHelp::guide('world'),
            Tabs::make('world')->persistTabInQueryString()->tabs([
                Tab::make(__('admin.world.cities'))->schema(self::cities($weather)),
                Tab::make(__('admin.world.roads'))->schema(self::roads($measures)),
                Tab::make(__('admin.world.tracks'))->schema(self::tracks($measures, $document)),
            ]),
        ];
    }

    /**
     * @param  list<string>  $weather
     * @return list<mixed>
     */
    private static function cities(array $weather): array
    {
        return [
            Repeater::make('regions')
                ->helpKey('world.regions')
                ->hiddenLabel()
                ->minItems(1)
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->addActionLabel(__('admin.world.city_add'))
                ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('slug')->helpKey('world.city_slug')->required()->maxLength(64)->regex(self::SLUG)->distinct()->live(onBlur: true),
                        TextInput::make('climate_seed')->helpKey('world.climate_seed')->required()->maxLength(64),
                    ]),
                    DefinitionFields::texts('name', __('admin.definitions.name'), 'world.city_name'),
                    MapPoint::make('map')
                        ->helpKey('world.map')
                        ->context(fn (Get $get): array => self::board($get('../../regions'), $get('../../roads'), $get('slug'))),
                    Repeater::make('weather')
                        ->helpKey('world.weather')
                        ->minItems(1)
                        ->addActionLabel(__('admin.world.weather_add'))
                        ->columns(2)
                        ->schema([
                            Select::make('weather')->helpKey('world.weather_kind')->options(FieldHelp::options('world.weather_kind', $weather))->required()->distinct(),
                            TextInput::make('percent')->helpKey('world.weather_chance')->numeric()->minValue(0.0001)->maxValue(100)->suffix('%')->required(),
                        ]),
                ]),
        ];
    }

    /** @return list<mixed> */
    private static function roads(Measures $measures): array
    {
        $cities = fn (Get $get): array => ContentOptions::cities($get('../../regions'));

        return [
            Repeater::make('roads')
                ->helpKey('world.roads')
                ->hiddenLabel()
                ->minItems(1)
                ->addActionLabel(__('admin.world.road_add'))
                ->columns(3)
                ->schema([
                    Select::make('from')->helpKey('world.from')->options($cities)->required(),
                    Select::make('to')->helpKey('world.to')->options($cities)->required(),
                    // A road is whole kilometres in the document, and is shown and written in the unit of travel of the game (20 nautical miles is 37 km, and shows as 19.978).
                    TextInput::make('km')
                        ->helpKey('world.km')
                        ->numeric()
                        ->minValue(0)
                        ->suffix($measures->travel->shortFor())
                        ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? $measures->travel->inBox($state * 1000) : $state)
                        ->dehydrateStateUsing(fn (mixed $state): mixed => is_numeric($state) ? (int) round($measures->travel->toMetres($state) / 1000) : $state)
                        ->required(),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<mixed>
     */
    private static function tracks(Measures $measures, array $document): array
    {
        $stats = SchemaChoices::stats();
        $loads = TrackNumbers::fuelLoads($document);

        return [
            Repeater::make('tracks')
                ->helpKey('world.tracks')
                ->hiddenLabel()
                ->minItems(1)
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->addActionLabel(__('admin.world.track_add'))
                ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('slug')->helpKey('world.track_slug')->required()->maxLength(64)->regex(self::SLUG)->distinct(),
                        Select::make('region')->helpKey('world.track_region')->options(fn (Get $get): array => ContentOptions::cities($get('../../regions')))->required(),
                        Select::make('surface')->helpKey('world.surface')->options(FieldHelp::options('world.surface', ['asphalt', 'gravel']))->required(),
                        Select::make('kind')->helpKey('world.kind')->options(FieldHelp::options('world.kind', ['sprint', 'standard', 'endurance']))->placeholder(__('admin.world.kinds.standard')),
                    ]),
                    DefinitionFields::texts('name', __('admin.definitions.name'), 'world.track_name'),
                    Grid::make(3)->schema([
                        Select::make('reference_tires')->helpKey('world.reference_tires')->options(fn (): array => ContentOptions::parts(app(ContentDrafts::class)->workingModel()->slotOf('tires') ?? 'tires'))->placeholder('-'),
                        TextInput::make('min_player_level')->helpKey('world.min_player_level')->numeric()->integer()->minValue(1)->required(),
                        Toggle::make('lit')->helpKey('world.lit')->inline(false),
                    ]),
                    // The length and the speed are in the units of the game; the numbers of the document are metres and km/h, and the form turns them.
                    Grid::make(3)->schema([
                        TextInput::make('length')->helpKey('world.length_m')->numeric()->minValue(0)->suffix($measures->lap->shortFor())->required()->live(onBlur: true),
                        TextInput::make('speed')
                            ->helpKey('world.speed_kmh')
                            ->numeric()
                            ->minValue(0)
                            ->suffix($measures->speed->shortFor())
                            ->required(fn (Get $get): bool => ! filled($get('base_lap_ms')))
                            ->live(onBlur: true)
                            ->hintAction(self::adviseAction($measures, $document)),
                        TextInput::make('pay_per_hour')->helpKey('world.pay_per_hour')->numeric()->prefix('$')->minValue(0.01)->required(fn (Get $get): bool => ! filled($get('reward')))->live(onBlur: true),
                        TextInput::make('xp_per_hour')->helpKey('world.xp_per_hour')->numeric()->minValue(0)->maxValue(3600)->required(fn (Get $get): bool => ! filled($get('xp_per_lap')))->live(onBlur: true),
                    ]),
                    // What the model makes of the numbers above, for the reference car.
                    Text::make(fn (Get $get): string => self::previewText($get, $measures, $loads))->color('gray'),
                    // The numbers that were said by hand: the form has them only for a track that says them, and they are believed over the model.
                    Grid::make(4)
                        ->visible(fn (Get $get): bool => self::saidByHand($get))
                        ->schema([
                            TextInput::make('base_lap_ms')->helpKey('world.base_lap_ms')->numeric()->integer()->minValue(1)->suffix('ms')->visible(fn (Get $get): bool => filled($get('base_lap_ms')))->live(onBlur: true),
                            TextInput::make('fuel_per_lap_ml')->helpKey('world.fuel_per_lap_ml')->numeric()->integer()->minValue(1)->suffix('ml')->visible(fn (Get $get): bool => filled($get('fuel_per_lap_ml')))->live(onBlur: true),
                            TextInput::make('reward')->helpKey('world.reward')->numeric()->prefix('$')->minValue(0.01)->visible(fn (Get $get): bool => filled($get('reward')))->live(onBlur: true),
                            TextInput::make('xp_per_lap')->helpKey('world.xp_per_lap')->numeric()->integer()->minValue(0)->visible(fn (Get $get): bool => filled($get('xp_per_lap')))->live(onBlur: true),
                        ]),
                    Actions::make([self::toModelAction($measures, $loads)])->visible(fn (Get $get): bool => self::saidByHand($get)),
                    self::stats('demand', $stats),
                    self::stats('reference', $stats),
                ]),
        ];
    }

    /** Whether the track says one of its four numbers by hand (the lap, the fuel, what a lap pays or what it gives). */
    private static function saidByHand(Get $get): bool
    {
        foreach (['base_lap_ms', 'fuel_per_lap_ml', 'reward', 'xp_per_lap'] as $field) {
            if (filled($get($field))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The track as the document would have it, from what the boxes of the form hold: the length and the speed in the units of the game turned into metres and km/h, the
     * pay in dollars into cents, and a box that holds no number left out.
     *
     * @param  array<string, mixed>  $state  the boxes of a track by their names
     * @return array<string, mixed>
     */
    public static function trackFrom(array $state, Measures $measures): array
    {
        $number = static fn (string $key): int|float|null => is_numeric($state[$key] ?? null) ? $state[$key] + 0 : null;
        $track = ['kind' => filled($state['kind'] ?? null) ? (string) $state['kind'] : TrackNumbers::DEFAULT_KIND];

        if (($length = $number('length')) !== null) {
            $track['length_m'] = $measures->lap->toMetres($length);
        }

        if (($speed = $number('speed')) !== null && $speed > 0) {
            $track['speed_kmh'] = $measures->speed->toKmh($speed);
        }

        if (($pay = $number('pay_per_hour')) !== null) {
            $track['pay_cents_per_hour'] = (int) round($pay * 100);
        }

        if (($xp = $number('xp_per_hour')) !== null) {
            $track['xp_per_hour'] = $xp;
        }

        foreach (['base_lap_ms', 'fuel_per_lap_ml', 'xp_per_lap'] as $key) {
            if (($value = $number($key)) !== null) {
                $track[$key] = (int) round($value);
            }
        }

        if (($reward = $number('reward')) !== null) {
            $track['reward_cents_per_lap'] = (int) round($reward * 100);
        }

        return $track;
    }

    /**
     * What the model makes of the track in the form, said for the person who writes it (and the wait for the numbers that it needs when it cannot).
     *
     * @param  array<string, int>  $loads
     */
    public static function previewText(Get $get, Measures $measures, array $loads): string
    {
        $state = [];

        foreach (['length', 'speed', 'kind', 'pay_per_hour', 'xp_per_hour', 'base_lap_ms', 'fuel_per_lap_ml', 'reward', 'xp_per_lap'] as $box) {
            $state[$box] = $get($box);
        }

        return self::say($state, $measures, $loads);
    }

    /**
     * The numbers of a track that the boxes hold, as the reference car has them: the lap, the speed, the fuel (and how much for the distance of the economy), what a lap pays
     * and gives, in the units of the game and the language of the panel.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $loads
     */
    public static function say(array $state, Measures $measures, array $loads): string
    {
        $numbers = TrackPreview::of(self::trackFrom($state, $measures), $loads);

        if ($numbers === null) {
            return __('admin.world.preview_wait');
        }

        $litres = $numbers['fuel_ml'] / 1000 * $measures->travel->metres * $measures->fuelEconomyPer / $numbers['length_m'];
        $per = $measures->fuelEconomyPer === 1 ? '' : $measures->fuelEconomyPer.' ';

        return __('admin.world.preview', [
            'lap' => self::duration($numbers['lap_ms']),
            'speed' => self::decimal($numbers['speed_kmh'] / $measures->speed->kmh, 1).' '.$measures->speed->shortFor(),
            'fuel' => self::decimal($numbers['fuel_ml'] / 1000, 2),
            'economy' => self::decimal($litres, 1).' l/'.$per.$measures->travel->shortFor(),
            'laps' => self::decimal($numbers['laps_per_hour'], 1),
            'cash' => self::decimal($numbers['cash_cents'] / 100, 2),
            'xp' => (string) $numbers['xp'],
        ]);
    }

    /** A time as a person reads it: 75 000 ms is 1 min 15 s, 12 000 ms is 12 s. */
    private static function duration(int $ms): string
    {
        $seconds = round($ms / 1000, 1);

        if ($seconds < 60) {
            return self::decimal($seconds, $seconds == (int) $seconds ? 0 : 1).' s';
        }

        $minutes = intdiv((int) $seconds, 60);
        $rest = round($seconds - $minutes * 60, 1);

        return $minutes.' min'.($rest > 0 ? ' '.self::decimal($rest, $rest == (int) $rest ? 0 : 1).' s' : '');
    }

    /** A number with its decimals, with the comma of the languages that write it so. */
    private static function decimal(int|float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, '.', '');

        return app()->getLocale() === 'en' ? $text : str_replace('.', ',', $text);
    }

    /**
     * The button of the speed: it asks about the corners, the climbs and the traffic of the track and puts the speed that the calculator makes of them in the box.
     *
     * @param  array<string, mixed>  $document
     */
    private static function adviseAction(Measures $measures, array $document): Action
    {
        $advisor = TrackAdvisor::constants($document);
        $unit = $measures->travel->shortFor();

        return Action::make('advise')
            ->label(__('admin.world.advise'))
            ->icon('heroicon-m-calculator')
            ->iconButton()
            ->tooltip(__('admin.world.advise'))
            ->modalHeading(__('admin.world.advise'))
            ->modalDescription(__('admin.world.advise_hint'))
            ->modalSubmitActionLabel(__('admin.world.advise_use'))
            ->schema([
                TextInput::make('corners')->helpKey('world.advise_corners', ['unit' => $unit])->numeric()->minValue(0)->default(4)->suffix(__('admin.world.per_unit', ['unit' => $unit]))->required(),
                TextInput::make('climb')->helpKey('world.advise_climb', ['unit' => $unit])->numeric()->minValue(0)->default(0)->suffix(__('admin.world.per_unit', ['unit' => $unit]))->required(),
                Select::make('traffic')->helpKey('world.advise_traffic')->options(FieldHelp::options('world.advise_traffic', [0, 1, 2, 3]))->default(0)->required(),
            ])
            ->action(function (array $data, Get $get, Set $set) use ($measures, $advisor): void {
                $kmh = self::advisedSpeed((string) ($get('surface') ?: 'asphalt'), (float) $data['corners'], (float) $data['climb'], (int) $data['traffic'], $measures, $advisor);

                $set('speed', $measures->speed->inBox($kmh));
            });
    }

    /**
     * The speed, in km/h, that the calculator makes of the corners and the climbs said per unit of travel of the game.
     *
     * @param  array{free_speed_kmh: array{asphalt: int|float, gravel: int|float}, corner_loss_kmh: int|float, climb_loss_kmh: int|float, traffic_loss_kmh: int|float}  $advisor
     */
    public static function advisedSpeed(string $surface, float $corners, float $climb, int $traffic, Measures $measures, array $advisor): int|float
    {
        $perKilometre = 1000 / $measures->travel->metres;

        return TrackAdvisor::speedKmh($surface, $corners * $perKilometre, $climb * $perKilometre, $traffic, $advisor);
    }

    /**
     * The move of a track that says its numbers by hand to the model: what the converter makes of them replaces them in the boxes, and what it cannot make exactly stays.
     *
     * @param  array<string, int>  $loads
     */
    private static function toModelAction(Measures $measures, array $loads): Action
    {
        return Action::make('toModel')
            ->label(__('admin.world.to_model'))
            ->icon('heroicon-m-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('admin.world.to_model'))
            ->modalDescription(__('admin.world.to_model_hint'))
            ->action(function (Get $get, Set $set) use ($measures, $loads): void {
                $state = [];

                foreach (['length', 'speed', 'kind', 'pay_per_hour', 'xp_per_hour', 'base_lap_ms', 'fuel_per_lap_ml', 'reward', 'xp_per_lap'] as $box) {
                    $state[$box] = $get($box);
                }

                $boxes = self::moved($state, $measures, $loads);

                foreach ($boxes as $box => $value) {
                    $set($box, $value);
                }
            });
    }

    /**
     * The boxes of a track after it moves to the model: the speed, the pay and the XP of an hour that make the numbers that it said by hand, and the boxes of those numbers
     * emptied, except for the ones that cannot be made exactly.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $loads
     * @return array<string, mixed>
     */
    public static function moved(array $state, Measures $measures, array $loads): array
    {
        $converted = TrackConverter::convert(self::trackFrom($state, $measures), $loads)['track'];
        $boxes = [];

        if (isset($converted['speed_kmh'])) {
            $boxes['speed'] = $measures->speed->inBox($converted['speed_kmh']);
        }

        if (isset($converted['pay_cents_per_hour'])) {
            $boxes['pay_per_hour'] = $converted['pay_cents_per_hour'] / 100;
        }

        if (isset($converted['xp_per_hour'])) {
            $boxes['xp_per_hour'] = $converted['xp_per_hour'];
        }

        foreach (['base_lap_ms' => 'base_lap_ms', 'fuel_per_lap_ml' => 'fuel_per_lap_ml', 'reward_cents_per_lap' => 'reward', 'xp_per_lap' => 'xp_per_lap'] as $field => $box) {
            if (! array_key_exists($field, $converted)) {
                $boxes[$box] = null;
            }
        }

        return $boxes;
    }

    /**
     * Lines of a statistic and a number.
     *
     * @param  list<string>  $stats
     */
    private static function stats(string $field, array $stats): Repeater
    {
        return Repeater::make($field)
            ->helpKey("world.{$field}")
            ->minItems(1)
            ->addActionLabel(__('admin.definitions.stat_add'))
            ->columns(2)
            ->schema([
                Select::make('stat')->helpKey('world.stat')->options(FieldHelp::options('world.stat', $stats))->required()->distinct(),
                TextInput::make('value')->helpKey("world.{$field}_value")->numeric()->required(),
            ]);
    }

    /**
     * What the board of the map shows besides the city that is being placed: the other cities, and the roads between them.
     *
     * @param  array<int|string, mixed>|null  $regions
     * @param  array<int|string, mixed>|null  $roads
     * @return array{cities: array<string, array{x: int, y: int, name: string}>, roads: list<array{from: string, to: string}>, own: string|null}
     */
    private static function board(?array $regions, ?array $roads, mixed $own): array
    {
        $cities = [];

        foreach ($regions ?? [] as $region) {
            $slug = is_string($region['slug'] ?? null) ? trim($region['slug']) : '';
            $x = $region['map']['x'] ?? null;
            $y = $region['map']['y'] ?? null;

            if ($slug !== '' && $slug !== $own && is_numeric($x) && is_numeric($y)) {
                $cities[$slug] = ['x' => (int) $x, 'y' => (int) $y, 'name' => ContentOptions::name($region['name'] ?? [], $slug)];
            }
        }

        $lines = [];

        foreach ($roads ?? [] as $road) {
            if (is_string($road['from'] ?? null) && is_string($road['to'] ?? null)) {
                $lines[] = ['from' => $road['from'], 'to' => $road['to']];
            }
        }

        return ['cities' => $cities, 'roads' => $lines, 'own' => is_string($own) && $own !== '' ? $own : null];
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): ?string
    {
        $slug = is_string($state['slug'] ?? null) ? trim($state['slug']) : '';

        return $slug === '' ? null : ContentOptions::name($state['name'] ?? [], $slug).' ('.$slug.')';
    }
}
