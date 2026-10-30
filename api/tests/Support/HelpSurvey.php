<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Filament\Pages\BrandingPage;
use App\Filament\Pages\ModelPage;
use App\Filament\Pages\RulesJsonPage;
use App\Filament\Pages\StudioPage;
use App\Filament\Pages\TutorialPage;
use App\Filament\Pages\VocabularyPage;
use App\Filament\Pages\WorldPage;
use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Models\Game;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * Every field of every form of the panel, as the pages draw them: where it is, what kind it is, the key of its help (or none), and the options of a choice that has a fixed
 * list. The test of the catalog takes its work from here, and so does the script that lists what is still to be written.
 */
final class HelpSurvey
{
    /** The pages that have a form, by the name of the form. @return array<string, string> */
    public static function pages(): array
    {
        return [
            'vehicle' => CreateVehicle::class,
            'part' => CreatePart::class,
            'game' => EditGame::class,
            'wizard' => CreateGame::class,
            'world' => WorldPage::class,
            'tutorial' => TutorialPage::class,
            'model' => ModelPage::class,
            'branding' => BrandingPage::class,
            'vocabulary' => VocabularyPage::class,
            'rules_text' => RulesJsonPage::class,
            'studio' => StudioPage::class,
        ];
    }

    /**
     * @return list<array{form: string, path: string, type: string, key: string|null, label: string|null, options: array<string, string>|null}>
     */
    public static function fields(): array
    {
        $found = [];

        foreach (self::pages() as $form => $class) {
            $parameters = $class === EditGame::class ? ['record' => Game::query()->orderBy('id')->firstOrFail()->getRouteKey()] : [];
            $page = Livewire::test($class, $parameters)->instance();

            foreach ($page->form->getFlatFields(withHidden: true) as $field) {
                if ($field instanceof Hidden) {
                    continue;
                }

                $found[] = self::describe($form, $field);
            }
        }

        return $found;
    }

    /**
     * @return array{form: string, path: string, type: string, key: string|null, label: string|null, options: array<string, string>|null}
     */
    private static function describe(string $form, Field $field): array
    {
        $options = null;

        if ($field instanceof Select || $field instanceof CheckboxList || $field instanceof Radio) {
            $options = [];

            foreach ((array) $field->getOptions() as $value => $label) {
                $options[(string) $value] = (string) $label;
            }
        }

        $label = $field->getLabel();

        return [
            'form' => $form,
            'path' => $field->getStatePath(isAbsolute: false),
            'type' => class_basename($field),
            'key' => $field->getExtraAttributes()['data-help'] ?? null,
            'label' => $label === null ? null : strip_tags((string) $label),
            'options' => $options,
        ];
    }
}
