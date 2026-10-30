<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\ParameterCatalog;
use App\Filament\Support\FieldHelp;
use Tests\Support\HelpSurvey;

// The help catalog is complete and the same in the five languages: every field of every form of the panel and every number of the studio has an entry, with a label and a
// line that says what it changes, in every language, so that a field that is added later cannot ship without its description.

const HELP_LOCALES = ['pl', 'en', 'fr', 'de', 'pt_BR'];

/** The fields whose choices are things of the game that are made by the operators (cities, parts, cars, games, messages), not a list that the catalog could explain. */
const HELP_DYNAMIC_CHOICES = [
    'world.from', 'world.to', 'world.track_region', 'world.reference_tires', 'world.stat',
    'tutorial.gift_part', 'tutorial.reward_part', 'tutorial.reward_garage', 'tutorial.listing_vehicle',
    'wizard.copy_from', 'vocabulary.area', 'rules_text.document', 'studio.section', 'model.stat', 'model.role',
];

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    signedInAdmin();
    app()->setLocale('pl');
    FieldHelp::flush();
});

/** @return array<string, array<string, mixed>> every field of every form by its help key (the first one that has it) */
function helpFieldsByKey(): array
{
    $byKey = [];

    foreach (HelpSurvey::fields() as $field) {
        $byKey[$field['key'] ?? "NO KEY: {$field['form']} {$field['path']} ({$field['type']})"] ??= $field;
    }

    return $byKey;
}

function helpFilled(?array $entry, string $what, string $locale): void
{
    expect($entry)->not->toBeNull("{$what} has no entry in {$locale}.")
        ->and(trim((string) ($entry['label'] ?? '')))->not->toBe('', "{$what} has no label in {$locale}.")
        ->and(trim((string) ($entry['help'] ?? '')))->not->toBe('', "{$what} has no help in {$locale}.");
}

describe('the fields of the forms', function () {
    it('are all given a key of the catalog', function () {
        $without = array_filter(array_keys(helpFieldsByKey()), fn (string $key): bool => str_starts_with($key, 'NO KEY'));

        expect(array_values($without))->toBe([]);
    });

    it('all have an entry with a label and a line of help in every language', function () {
        foreach (helpFieldsByKey() as $key => $field) {
            foreach (HELP_LOCALES as $locale) {
                helpFilled(FieldHelp::own($key, $locale), "The field {$key} ({$field['form']} {$field['path']})", $locale);
            }
        }
    });

    it('that are a choice explain each option, in every language', function () {
        foreach (helpFieldsByKey() as $key => $field) {
            $values = array_keys($field['options'] ?? []);

            if ($values === [] || in_array($key, HELP_DYNAMIC_CHOICES, true) || str_starts_with($key, 'rules.')) {
                continue;
            }

            foreach (HELP_LOCALES as $locale) {
                $options = (array) (FieldHelp::own($key, $locale)['options'] ?? []);

                foreach ($values as $value) {
                    expect(trim((string) ($options[$value]['label'] ?? '')))->not->toBe('', "{$key}: option {$value} has no label in {$locale}.")
                        ->and(trim((string) ($options[$value]['help'] ?? '')))->not->toBe('', "{$key}: option {$value} has no line of help in {$locale}.");
                }
            }
        }
    });
});

describe('the numbers of the studio', function () {
    /** @return list<string> the pattern of every number of the rules of the game */
    function helpPatterns(): array
    {
        $document = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
        $catalog = ParameterCatalog::ofTheGame();
        $patterns = [];

        foreach (array_keys($catalog->sections($document)) as $section) {
            foreach ($catalog->parameters($document, $section) as $parameter) {
                $patterns[$parameter->pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    it('all have an entry with a label and a line of help in every language', function () {
        foreach (helpPatterns() as $pattern) {
            foreach (HELP_LOCALES as $locale) {
                helpFilled(FieldHelp::own("rules.{$pattern}", $locale), "The number {$pattern}", $locale);
            }
        }
    });

    it('have no entry that is for a number that does not exist: it is a typo or a number that was renamed', function () {
        $patterns = helpPatterns();

        foreach (HELP_LOCALES as $locale) {
            $stale = array_values(array_diff(FieldHelp::keysOf('rules', $locale), $patterns));

            expect($stale)->toBe([], "Entries of rules in {$locale} for numbers that the rules do not have.");
        }
    });
});

describe('the guides', function () {
    it('are there for every form that has one, with their steps and an example, in every language', function () {
        foreach (FieldHelp::GUIDES as $name) {
            foreach (HELP_LOCALES as $locale) {
                $entry = FieldHelp::own("guide.{$name}", $locale);

                helpFilled($entry, "The guide {$name}", $locale);
                expect(count((array) ($entry['steps'] ?? [])))->toBeGreaterThanOrEqual(3, "The guide {$name} has too few steps in {$locale}.")
                    ->and(trim((string) ($entry['example'] ?? '')))->not->toBe('', "The guide {$name} has no example in {$locale}.");
            }
        }
    });
});

describe('the catalog', function () {
    /** @return list<string> the areas that have a file in any language */
    function helpAreas(): array
    {
        $areas = [];

        foreach (HELP_LOCALES as $locale) {
            foreach (glob(lang_path("{$locale}/help/*.php")) ?: [] as $file) {
                $areas[explode('.', basename($file, '.php'))[0]] = true;
            }
        }

        return array_keys($areas);
    }

    it('has the same entries and the same options in the five languages', function () {
        foreach (helpAreas() as $area) {
            $reference = FieldHelp::entries($area, 'pl');

            foreach (HELP_LOCALES as $locale) {
                $entries = FieldHelp::entries($area, $locale);

                expect(array_keys($entries))->toEqualCanonicalizing(array_keys($reference), "The area {$area} has other entries in {$locale} than in pl.");

                foreach ($reference as $key => $entry) {
                    expect(array_keys((array) ($entries[$key]['options'] ?? [])))->toEqualCanonicalizing(array_keys((array) ($entry['options'] ?? [])), "{$area}.{$key}: other options in {$locale} than in pl.")
                        ->and(count((array) ($entries[$key]['steps'] ?? [])))->toBe(count((array) ($entry['steps'] ?? [])), "{$area}.{$key}: other number of steps in {$locale} than in pl.");
                }
            }
        }
    });

    it('has the same words to be put in place in every language of an entry', function () {
        $words = fn (mixed $value): array => (function () use ($value): array {
            preg_match_all('/:(min|max|default|limit|days)\b/', json_encode($value, JSON_UNESCAPED_UNICODE) ?: '', $found);
            $unique = array_unique($found[1]);
            sort($unique);

            return $unique;
        })();

        foreach (helpAreas() as $area) {
            foreach (FieldHelp::entries($area, 'pl') as $key => $entry) {
                foreach (HELP_LOCALES as $locale) {
                    expect($words(FieldHelp::entries($area, $locale)[$key] ?? []))->toBe($words($entry), "{$area}.{$key}: other words to put in place in {$locale} than in pl.");
                }
            }
        }
    });

    it('writes French the way French is written: a no-break space before : ; ? ! and no straight apostrophe', function () {
        $bad = [];
        $walk = function (mixed $value, string $where) use (&$walk, &$bad): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, "{$where}.{$key}");
                }

                return;
            }

            $text = (string) $value;

            // A colon that begins a word to be put in place (` :min`) is not punctuation.
            if (preg_match('/ [;?!]| :(?!(?:min|max|default|limit|days)\b)/', $text) === 1 || str_contains($text, "'")) {
                $bad[] = $where;
            }
        };

        foreach (helpAreas() as $area) {
            $walk(FieldHelp::entries($area, 'fr'), $area);
        }

        expect($bad)->toBe([]);
    });
});
