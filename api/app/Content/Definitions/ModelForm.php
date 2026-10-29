<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use App\Content\GameModel;

/**
 * What the page of the model holds and what it does to the document: the slots (a key, a role or none, hidden or not) and the statistics that the screens show, both
 * as lists that keep their order. A document that does not exist yet is the model of the car game.
 */
final class ModelForm
{
    /**
     * @param  array<string, mixed>  $document
     * @return array{slots: list<array{key: string, role: string|null, hidden: bool}>, stats: list<array{key: string}>}
     */
    public static function toState(array $document): array
    {
        $model = $document === [] ? GameModel::default() : GameModel::fromArray($document);

        return [
            'slots' => $model->slots(),
            'stats' => array_map(static fn (string $key): array => ['key' => $key], $model->stats()),
        ];
    }

    /**
     * The document with what the form holds in it: the slots and the statistics in the order of the lists, a role only where one was chosen and `hidden` only where
     * it is on (so that the model of the car game comes out as it is in the file).
     *
     * @param  array<string, mixed>  $document  the document being worked on
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $state, string $version): array
    {
        $slots = [];

        foreach ((array) ($state['slots'] ?? []) as $line) {
            $slot = ['key' => DefinitionForm::text($line['key'] ?? null)];

            if (DefinitionForm::text($line['role'] ?? null) !== null) {
                $slot['role'] = DefinitionForm::text($line['role']);
            }

            if (! empty($line['hidden'])) {
                $slot['hidden'] = true;
            }

            $slots[] = $slot;
        }

        $stats = [];

        foreach ((array) ($state['stats'] ?? []) as $line) {
            $key = DefinitionForm::text($line['key'] ?? null);

            if ($key !== null) {
                $stats[] = $key;
            }
        }

        return [
            'schema' => $document['schema'] ?? 0,
            'version' => $document['version'] ?? $version,
            'slots' => $slots,
            'stats' => $stats,
        ];
    }
}
