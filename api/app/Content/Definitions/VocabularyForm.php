<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use App\Content\GameModel;
use App\Content\Vocabulary\MessageCatalog;
use stdClass;

/**
 * What the page of the vocabulary holds and what it does to the document. The messages of the web app are far too many for one form, so the page shows some of
 * them at a time: those of one area (`slot`, `nav`, `workshop`), or those that a search finds, or only those that the pack has changed, and at most `SHOWN` of
 * them. The document keeps only the messages that are changed; a message with nothing written in any language is the text of the app again.
 */
final class VocabularyForm
{
    /** How many messages a page shows at once: every message is a box for each of the five languages. */
    public const SHOWN = 30;

    /** The value of the choice of an area that means all of them. */
    public const ALL = '*';

    /** @param GameModel|null $model the model of the game as it is being worked on: its slots that the app has no name for are messages of the pack too */
    public function __construct(private readonly MessageCatalog $catalog, private readonly ?GameModel $model = null) {}

    /**
     * The messages that name the slots of the model that the app does not know (a game with a sail where the car game has tires): the app has no text for them, so
     * the pack gives it. A hidden slot is not shown and needs none. Without the texts of the web app, nothing is known to be missing from it.
     *
     * @return list<string>
     */
    public function packSlotKeys(): array
    {
        if ($this->model === null || ! $this->catalog->available()) {
            return [];
        }

        $keys = [];

        foreach ($this->model->slots() as ['key' => $slot, 'hidden' => $hidden]) {
            if (! $hidden && ! $this->catalog->has("slot.{$slot}")) {
                $keys[] = "slot.{$slot}";
            }
        }

        return $keys;
    }

    /**
     * The messages that can be changed, in the order of the app, and the ones the document has that the app does not (a text for a message that was taken out of
     * the app, which stays visible so that it can be cleared).
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public function keys(array $document): array
    {
        $keys = array_keys($this->catalog->all());

        foreach ($this->packSlotKeys() as $key) {
            $keys[] = $key;
        }

        foreach (array_keys(self::messagesOf($document)) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * The areas, with the number of messages in each and how many of them the document has changed.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array{total: int, changed: int}>
     */
    public function areas(array $document): array
    {
        $areas = [];
        $changed = self::messagesOf($document);

        foreach ($this->keys($document) as $key) {
            $area = MessageCatalog::area($key);
            $areas[$area] ??= ['total' => 0, 'changed' => 0];
            $areas[$area]['total']++;
            $areas[$area]['changed'] += isset($changed[$key]) ? 1 : 0;
        }

        return $areas;
    }

    /**
     * The messages to show: those of the area (all of them for `ALL`) that have the search in their key or in a text (the one of the app or the one of the pack), and
     * only the changed ones when asked. Only the first `SHOWN` are given; `matching` is how many there are.
     *
     * @param  array<string, mixed>  $document
     * @return array{keys: list<string>, matching: int}
     */
    public function visible(array $document, string $area, string $search, bool $onlyChanged): array
    {
        $needle = mb_strtolower(trim($search));
        $changed = self::messagesOf($document);
        $matching = [];

        foreach ($this->keys($document) as $key) {
            if ($area !== self::ALL && MessageCatalog::area($key) !== $area) {
                continue;
            }

            if ($onlyChanged && ! isset($changed[$key])) {
                continue;
            }

            if ($needle !== '' && ! $this->matches($document, $key, $needle)) {
                continue;
            }

            $matching[] = $key;
        }

        return ['keys' => array_slice($matching, 0, self::SHOWN), 'matching' => count($matching)];
    }

    /**
     * What the form holds for the messages it shows: the text of the pack in each language, or nothing where the pack says nothing.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $keys  the messages that are shown, in order
     * @return array<string, mixed>
     */
    public function toState(array $document, array $keys): array
    {
        $values = [];
        $changed = self::messagesOf($document);

        foreach ($keys as $position => $key) {
            $values["m{$position}"] = DefinitionForm::texts((array) ($changed[$key] ?? []));
        }

        return ['values' => $values];
    }

    /**
     * The document with what the form holds in it: for each message that is shown, the texts that were written. A message with a text in some language but not in
     * English or Polish gets the text of the app there, so that changing one language does not need the others to be filled in. A message with no text at all is
     * not in the document.
     *
     * @param  array<string, mixed>  $document  the document being worked on
     * @param  list<string>  $keys  the messages that are shown, in order
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function apply(array $document, array $keys, array $state, string $version): array
    {
        $messages = self::messagesOf($document);

        foreach ($keys as $position => $key) {
            $texts = DefinitionForm::i18n((array) ($state['values']["m{$position}"] ?? []));

            if ($texts === []) {
                unset($messages[$key]);

                continue;
            }

            foreach (['en', 'pl'] as $required) {
                $texts[$required] ??= $this->catalog->text($key, $required);
            }

            $messages[$key] = DefinitionForm::i18n(array_filter($texts, static fn (?string $text): bool => $text !== null));
        }

        return [
            'schema' => $document['schema'] ?? 0,
            'version' => $document['version'] ?? $version,
            'messages' => $messages === [] ? new stdClass : $messages,
        ];
    }

    /**
     * The messages of the document as an array (an empty set of them is the empty object of the file, which is not one).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function messagesOf(array $document): array
    {
        return (array) ($document['messages'] ?? []);
    }

    /** @param array<string, mixed> $document */
    private function matches(array $document, string $key, string $needle): bool
    {
        $haystack = [$key, $this->catalog->text($key, 'en'), $this->catalog->text($key, 'pl'), ...array_values((array) (self::messagesOf($document)[$key] ?? []))];

        foreach ($haystack as $text) {
            if ($text !== null && str_contains(mb_strtolower($text), $needle)) {
                return true;
            }
        }

        return false;
    }
}
