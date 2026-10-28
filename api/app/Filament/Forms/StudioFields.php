<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Studio\Parameter;
use Filament\Actions\Action;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The numbers of a section of the rules as a form: a slider for a number the schema bounds, a field for the others, each with its unit, what the schema says of it, what
 * the live pack has there when it is not what is here, and a way back to it. The numbers are in the unit a person thinks in (a percent, seconds, dollars, liters); the
 * catalog turns them back into the whole numbers the document keeps.
 */
final class StudioFields
{
    /** A slider with more steps than this is of no use: it cannot be set with the hand. */
    private const MOST_STEPS = 2_000;

    /**
     * @param  list<Parameter>  $parameters  the numbers of the section, in the order of the document
     * @return list<mixed>
     */
    public static function schema(array $parameters, bool $sliders): array
    {
        $groups = [];

        foreach ($parameters as $position => $parameter) {
            $groups[$parameter->group][] = [$position, $parameter];
        }

        $sections = [];

        foreach ($groups as $group => $members) {
            $sections[] = Section::make($group)
                ->description($members[0][1]->groupDescription)
                ->collapsible()
                ->collapsed(count($groups) > 1 && count($sections) > 0)
                ->columns(['default' => 1, 'md' => 2])
                ->schema(array_map(static fn (array $member): mixed => self::field($member[0], $member[1], $sliders), $members));
        }

        return $sections;
    }

    private static function field(int $position, Parameter $parameter, bool $sliders): mixed
    {
        $name = "values.p{$position}";
        $unit = $parameter->unit;
        $step = $unit->step($parameter->integer);
        $min = $parameter->minDisplay();
        $max = $parameter->maxDisplay();
        $live = $parameter->liveDisplay();
        $suffix = $unit->suffix();

        $bounded = $min !== null && $max !== null && $max > $min && ($max - $min) / $step <= self::MOST_STEPS;

        $field = $sliders && $bounded
            ? Slider::make($name)->range($min, $max)->step($step)->tooltips()->live(debounce: 400)
            : TextInput::make($name)->numeric()->step($step)->minValue($min)->maxValue($max)->suffix($suffix === '' ? null : $suffix)->live(onBlur: true);

        return $field
            ->label($parameter->label)
            ->helperText($parameter->description)
            ->hint(fn (Get $get): string => self::say($get($name), $suffix).($live !== null && $live != $get($name) ? ' · '.__('admin.studio.live', ['value' => self::say($live, $suffix)]) : ''))
            ->hintColor(fn (Get $get): string => $live !== null && $live != $get($name) ? 'warning' : 'gray')
            ->hintAction(
                Action::make("reset{$position}")
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->iconButton()
                    ->tooltip(__('admin.studio.reset'))
                    ->visible(fn (Get $get): bool => $live !== null && $live != $get($name))
                    ->action(fn (Set $set) => $set($name, $live)),
            );
    }

    /** A number with its unit, as the hint says it. */
    private static function say(mixed $value, string $suffix): string
    {
        $number = is_numeric($value) ? (string) (0 + $value) : '-';

        return $suffix === '' ? $number : "{$number} {$suffix}";
    }
}
