<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use App\Content\Branding\Branding;

/**
 * What the page of the look of a game holds and what it does to the document: the colours and the fonts that were chosen, the style sheet, the blocks of the front
 * page with their texts in every language of the game, and the preview of a link. Only what was chosen goes into the document, so that a form with nothing in it is
 * the document that changes nothing, and what the schema and the rules say of the rest is said by the draft when it is kept.
 */
final class BrandingForm
{
    /** The kinds of block of the front page, and the fields each one reads. */
    public const BLOCKS = [
        'hero' => ['eyebrow', 'title', 'subtitle', 'image', 'alt'],
        'features' => ['items'],
        'text' => ['title', 'body'],
        'image' => ['image', 'alt', 'caption'],
        'cta' => ['title', 'text'],
    ];

    /** The longest a text may be, by the name of the field (the schema's line, sentence and paragraphs). */
    public const LIMITS = ['eyebrow' => 120, 'title' => 120, 'subtitle' => 400, 'text' => 400, 'body' => 4000, 'alt' => 120, 'caption' => 120, 'kicker' => 120, 'description' => 400];

    /** The most blocks a front page has, and the most cards a block of features has. */
    public const MAX_BLOCKS = 12;

    public const MAX_ITEMS = 6;

    /**
     * @param  array<string, mixed>  $document
     * @return array{colors: array<string, string|null>, fonts: array<string, string|null>, css: string|null, blocks: list<array{type: string, data: array<string, mixed>}>, share: array<string, mixed>}
     */
    public static function toState(array $document): array
    {
        $colors = [];

        foreach (array_keys(Branding::COLORS) as $name) {
            $colors[$name] = DefinitionForm::text($document['colors'][$name] ?? null);
        }

        $fonts = [];

        foreach (array_keys(Branding::FONT_ROLES) as $role) {
            $fonts[$role] = DefinitionForm::text($document['fonts'][$role] ?? null);
        }

        $blocks = [];

        foreach ((array) ($document['landing']['blocks'] ?? []) as $block) {
            if (is_array($block) && isset(self::BLOCKS[$block['type'] ?? ''])) {
                $blocks[] = ['type' => $block['type'], 'data' => self::blockState($block)];
            }
        }

        $share = (array) ($document['share'] ?? []);

        return [
            'colors' => $colors,
            'fonts' => $fonts,
            'css' => isset($document['css']) && is_string($document['css']) ? $document['css'] : null,
            'blocks' => $blocks,
            'share' => [
                'title' => DefinitionForm::text($share['title'] ?? null),
                'description' => DefinitionForm::texts((array) ($share['description'] ?? [])),
                'image' => DefinitionForm::text($share['image'] ?? null),
            ],
        ];
    }

    /**
     * The document with what the form holds in it. A picture that was chosen has been kept by the page already, and is in `image`.
     *
     * @param  array<string, mixed>  $document  the document being worked on
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $state, string $version): array
    {
        $result = ['schema' => $document['schema'] ?? 0, 'version' => $document['version'] ?? $version];

        $colors = [];

        foreach (array_keys(Branding::COLORS) as $name) {
            $color = DefinitionForm::text($state['colors'][$name] ?? null);

            if ($color !== null) {
                $colors[$name] = $color;
            }
        }

        if ($colors !== []) {
            $result['colors'] = $colors;
        }

        $fonts = [];

        foreach (array_keys(Branding::FONT_ROLES) as $role) {
            $font = DefinitionForm::text($state['fonts'][$role] ?? null);

            if ($font !== null) {
                $fonts[$role] = $font;
            }
        }

        if ($fonts !== []) {
            $result['fonts'] = $fonts;
        }

        $css = self::sheet($state['css'] ?? null);

        if ($css !== null) {
            $result['css'] = $css;
        }

        $blocks = [];

        foreach ((array) ($state['blocks'] ?? []) as $item) {
            $block = is_array($item) ? self::block((string) ($item['type'] ?? ''), (array) ($item['data'] ?? [])) : null;

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        if ($blocks !== []) {
            $result['landing'] = ['blocks' => $blocks];
        }

        $share = [];
        $title = DefinitionForm::text($state['share']['title'] ?? null);
        $description = DefinitionForm::i18n((array) ($state['share']['description'] ?? []));
        $image = DefinitionForm::text($state['share']['image'] ?? null);

        foreach (['title' => $title, 'description' => $description === [] ? null : $description, 'image' => $image] as $field => $value) {
            if ($value !== null) {
                $share[$field] = $value;
            }
        }

        if ($share !== []) {
            $result['share'] = $share;
        }

        return $result;
    }

    /** The sheet as it was written, with the line ends of one kind and none of the white space round it; null when there is nothing in it. */
    private static function sheet(mixed $css): ?string
    {
        $sheet = DefinitionForm::text(is_string($css) ? str_replace(["\r\n", "\r"], "\n", $css) : null);

        return $sheet;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function blockState(array $block): array
    {
        $data = [];

        foreach (self::BLOCKS[$block['type']] as $field) {
            $data[$field] = match ($field) {
                'image' => DefinitionForm::text($block['image'] ?? null),
                'items' => array_map(
                    static fn (mixed $item): array => [
                        'kicker' => DefinitionForm::texts((array) ($item['kicker'] ?? [])),
                        'title' => DefinitionForm::texts((array) ($item['title'] ?? [])),
                        'text' => DefinitionForm::texts((array) ($item['text'] ?? [])),
                    ],
                    array_values((array) ($block['items'] ?? [])),
                ),
                default => DefinitionForm::texts((array) ($block[$field] ?? [])),
            };
        }

        return $data;
    }

    /**
     * A block of the document from what the form holds of it, or null for a kind that there is none of. A field that has nothing in it is left out, and so is a
     * card with nothing on it; what a kind needs and does not have is for the schema to say.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function block(string $type, array $data): ?array
    {
        if (! isset(self::BLOCKS[$type])) {
            return null;
        }

        $block = ['type' => $type];

        foreach (self::BLOCKS[$type] as $field) {
            if ($field === 'image') {
                $image = DefinitionForm::text($data['image'] ?? null);

                if ($image !== null) {
                    $block['image'] = $image;
                }

                continue;
            }

            if ($field === 'items') {
                $items = [];

                foreach ((array) ($data['items'] ?? []) as $item) {
                    $card = [];

                    foreach (['kicker', 'title', 'text'] as $part) {
                        $texts = DefinitionForm::i18n((array) ($item[$part] ?? []));

                        if ($texts !== []) {
                            $card[$part] = $texts;
                        }
                    }

                    if ($card !== []) {
                        $items[] = $card;
                    }
                }

                if ($items !== []) {
                    $block['items'] = $items;
                }

                continue;
            }

            $texts = DefinitionForm::i18n((array) ($data[$field] ?? []));

            if ($texts !== []) {
                $block[$field] = $texts;
            }
        }

        return $block;
    }
}
