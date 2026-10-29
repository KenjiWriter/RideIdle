<?php

declare(strict_types=1);

namespace App\Content\Branding;

use App\Content\ContentSource;
use App\Services\ContentImages;

/**
 * The look of a game (content/rules/branding.json): the colours and fonts of its app, a style sheet of its own, the blocks of its front page and what a link to it
 * shows when it is shared. A pack that has no such document, or an empty one, changes nothing, and the app is the car game's.
 */
final class Branding
{
    /** The colours of the app that a game may change, by name, and the variable of the style sheet that each one is (Tailwind makes one of every colour of the theme). */
    public const COLORS = [
        'bg' => '--color-bg',
        'surface' => '--color-surface',
        'surface_2' => '--color-surface-2',
        'surface_3' => '--color-surface-3',
        'line' => '--color-line',
        'line_2' => '--color-line-2',
        'ink' => '--color-ink',
        'muted' => '--color-muted',
        'faint' => '--color-faint',
        'amber' => '--color-amber',
        'amber_ink' => '--color-amber-ink',
        'amber_bg' => '--color-amber-bg',
        'cyan' => '--color-cyan',
        'cyan_bg' => '--color-cyan-bg',
        'ok' => '--color-ok',
        'danger' => '--color-danger',
        'r1' => '--color-r1',
        'r2' => '--color-r2',
        'r3' => '--color-r3',
        'r4' => '--color-r4',
        'r5' => '--color-r5',
    ];

    /** The fonts that can be chosen, by preset: the stack that the style sheet gets. The first three are the files the app brings; the rest are on every device. */
    public const FONTS = [
        'barlow-condensed' => "'Barlow Condensed', 'Arial Narrow', Arial, sans-serif",
        'barlow' => "'Barlow', system-ui, -apple-system, 'Segoe UI', sans-serif",
        'jetbrains-mono' => "'JetBrains Mono', ui-monospace, Consolas, monospace",
        'system-sans' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'system-serif' => "Georgia, 'Times New Roman', serif",
        'system-mono' => "ui-monospace, Consolas, 'Courier New', monospace",
    ];

    /** The places in the app that take a font, and the variable of each. */
    public const FONT_ROLES = ['display' => '--font-display', 'sans' => '--font-sans', 'mono' => '--font-mono'];

    /** @param array<string, mixed> $document */
    private function __construct(private readonly string $version, private readonly array $document) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        if (! $source->has('rules/branding')) {
            return new self('', []);
        }

        return new self($source->has('pack') ? (string) ($source->manifest()['version'] ?? '') : '', $source->document('rules/branding'));
    }

    /** The number of the pack that the look is from: what a copy of it is called. */
    public function number(): string
    {
        return $this->version;
    }

    /** Whether the game has a style sheet to serve: colours, fonts or a sheet of its own. */
    public function hasTheme(): bool
    {
        return $this->stylesheet() !== '';
    }

    public function hasLanding(): bool
    {
        return $this->landing() !== null;
    }

    /**
     * The style sheet of the game: its colours and fonts as the variables of the app (written in the sheet that has no layer, so they win over the ones of the app
     * wherever the sheet is linked), then the sheet of its own. A sheet of its own that the policy refuses is left out whole, never in part.
     */
    public function stylesheet(): string
    {
        $declarations = [];

        foreach ((array) ($this->document['colors'] ?? []) as $name => $color) {
            if (isset(self::COLORS[$name]) && is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
                $declarations[] = self::COLORS[$name].':'.$color.';';
            }
        }

        foreach ((array) ($this->document['fonts'] ?? []) as $role => $preset) {
            if (isset(self::FONT_ROLES[$role]) && is_string($preset) && isset(self::FONTS[$preset])) {
                $declarations[] = self::FONT_ROLES[$role].':'.self::FONTS[$preset].';';
            }
        }

        $css = (string) ($this->document['css'] ?? '');

        if ($css !== '' && CssPolicy::problems($css) !== []) {
            $css = '';
        }

        return ($declarations === [] ? '' : ':root{'.implode('', $declarations)."}\n").$css;
    }

    /**
     * The blocks of the front page, each with its texts in every language the document has; null when the game has none and the app shows its own.
     *
     * @return array{blocks: list<array<string, mixed>>}|null
     */
    public function landing(): ?array
    {
        $blocks = $this->document['landing']['blocks'] ?? null;

        return is_array($blocks) && $blocks !== [] ? ['blocks' => array_values($blocks)] : null;
    }

    /**
     * What a link to the game shows: the title, the description (in every language the document has) and the name of the picture, each null where the game says
     * nothing and the name, the sentence of the manifest and the icon of the game are used.
     *
     * @return array{title: string|null, description: array<string, string>|null, image: string|null}
     */
    public function share(): array
    {
        $share = (array) ($this->document['share'] ?? []);

        return [
            'title' => isset($share['title']) && is_string($share['title']) ? $share['title'] : null,
            'description' => isset($share['description']) && is_array($share['description']) ? $share['description'] : null,
            'image' => isset($share['image']) && is_string($share['image']) ? $share['image'] : null,
        ];
    }

    /**
     * The pictures that a document of the look names: on the front page, in the preview of a link and in its style sheet. They are what a bundle has to carry.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public static function pictures(array $document): array
    {
        $names = [];

        foreach ((array) ($document['landing']['blocks'] ?? []) as $block) {
            $names[] = is_array($block) ? ($block['image'] ?? null) : null;
        }

        $names[] = $document['share']['image'] ?? null;

        foreach (CssPolicy::pictures((string) ($document['css'] ?? '')) as $name) {
            $names[] = $name;
        }

        return array_values(array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && preg_match(ContentImages::NAME, $name) === 1)));
    }
}
