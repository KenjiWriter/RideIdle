<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Definitions\DocumentDiff;
use App\Content\Studio\Parameter;
use App\Content\Studio\ParameterCatalog;
use App\Models\ContentDefinition;
use Illuminate\Support\Str;

/**
 * What the drafts of the game would change in the game: for each definition that has one, how it differs from what was published last. A definition that was never published is
 * new; one whose draft says what the published document says has nothing to show. The numbers of the rules are said the way the studio says them (where they are, in the
 * unit a person thinks in); everything else by its place in the document.
 */
final class PendingChanges
{
    public const NEW = 'new';

    public const CHANGED = 'changed';

    public const SAME = 'same';

    /** The most changes that are listed for one definition: the rest is counted. */
    public const LISTED = 200;

    /**
     * @return list<array{definition: ContentDefinition, state: string, count: int, changes: list<array{type: string, group: string|null, label: string, from: string|null, to: string|null}>}>
     */
    public function all(): array
    {
        $catalog = null;
        $entries = [];

        $definitions = ContentDefinition::query()->whereNotNull('draft')->with('latestVersion')->orderBy('kind')->orderBy('slug')->get();

        foreach ($definitions as $definition) {
            $draft = (array) $definition->draftDocument();
            $published = $definition->publishedDocument();

            if ($published === null) {
                $entries[] = ['definition' => $definition, 'state' => self::NEW, 'count' => 0, 'changes' => []];

                continue;
            }

            $changes = DocumentDiff::between($published, $draft);
            $numbers = $definition->kind === ContentDefinition::KIND_RULES ? $this->numbers($catalog ??= ParameterCatalog::ofTheGame(), $draft, $published) : [];

            $entries[] = [
                'definition' => $definition,
                'state' => $changes === [] ? self::SAME : self::CHANGED,
                'count' => count($changes),
                'changes' => array_map(fn (array $change): array => $this->described($change, $numbers), array_slice($changes, 0, self::LISTED)),
            ];
        }

        return $entries;
    }

    /** How many definitions have a draft. */
    public function waiting(): int
    {
        return ContentDefinition::query()->whereNotNull('draft')->count();
    }

    /**
     * The numbers of a document of the rules by their pointer, with what the published document has for each.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $published
     * @return array<string, Parameter>
     */
    private function numbers(ParameterCatalog $catalog, array $draft, array $published): array
    {
        $numbers = [];

        foreach (array_keys($catalog->sections($draft)) as $section) {
            foreach ($catalog->parameters($draft, $section, $published) as $parameter) {
                $numbers[$parameter->pointer()] = $parameter;
            }
        }

        return $numbers;
    }

    /**
     * @param  array{pointer: string, type: string, from: mixed, to: mixed}  $change
     * @param  array<string, Parameter>  $numbers
     * @return array{type: string, group: string|null, label: string, from: string|null, to: string|null}
     */
    private function described(array $change, array $numbers): array
    {
        $number = $numbers[$change['pointer']] ?? null;

        if ($number !== null && (is_int($change['from']) || is_float($change['from'])) && (is_int($change['to']) || is_float($change['to']))) {
            $suffix = $number->unit->suffix();

            return [
                'type' => $change['type'],
                'group' => $number->group,
                'label' => $number->label,
                'from' => $number->unit->display($change['from']).$suffix,
                'to' => $number->unit->display($change['to']).$suffix,
            ];
        }

        return [
            'type' => $change['type'],
            'group' => null,
            'label' => $change['pointer'],
            'from' => $this->shown($change['from']),
            'to' => $this->shown($change['to']),
        ];
    }

    private function shown(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_object($value) => '{}',
            is_array($value) => $value === [] ? '[]' : Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 120),
            default => Str::limit((string) $value, 120),
        };
    }
}
