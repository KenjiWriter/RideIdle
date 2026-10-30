<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Branding\Branding;
use App\Content\Units\Measures;
use App\Games\CurrentGame;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\ContentImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GameController extends Controller
{
    /** The icons that the first game's app brings, which a game without icons of its own keeps. */
    private const DEFAULT_ICONS = [
        ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    /** What the first game says of itself where it is described. */
    public const DEFAULT_DESCRIPTION = 'An idle racing game: rebuild a wreck, buy better cars and keep earning while you are away.';

    public function __construct(private readonly CurrentGame $current, private readonly ContentImages $images) {}

    /**
     * The game that the request is for, as the app needs it before it draws anything: its name (the title of the page and the mark of the game), its colour, the
     * language a visitor gets when theirs is not one of the game, its icons (paths of the API, or null where it has none and the app keeps its own), the address of
     * its style sheet (null where it has none, so that the app does not ask for one) and whether it has a front page of its own.
     */
    public function show(Request $request, Branding $branding): JsonResponse
    {
        $game = $this->game();

        return $this->cached($request, response()->json([
            'slug' => $game->slug,
            'name' => $game->name,
            'locale' => $game->locale,
            'theme_color' => $game->theme_color,
            'icon_url' => $game->icon === null ? null : ContentImages::url($game->icon),
            'icon_maskable_url' => $game->icon_maskable === null ? null : ContentImages::url($game->icon_maskable),
            'theme_css_url' => $branding->hasTheme() ? route('theme', [], false).'?v='.rawurlencode($branding->number()) : null,
            'has_landing' => $branding->hasLanding(),
            'is_default' => (bool) $game->is_default,
            'units' => Measures::ofTheGame()->toArray(),
        ]), 60);
    }

    /**
     * The manifest of the web app of the game (what a browser reads to put it on a home screen): the name and the colours of the game, and its icons, or the icons
     * of the first game where it has none. It is the host that says which game, so each domain is an app of its own.
     */
    public function manifest(Request $request): JsonResponse
    {
        $game = $this->game();

        $response = response()->json([
            'id' => '/',
            'name' => $game->name,
            'short_name' => $this->shortName($game->name),
            'description' => $game->is_default ? self::DEFAULT_DESCRIPTION : $game->name,
            'lang' => $game->locale,
            'start_url' => '/garage',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $game->theme_color,
            'theme_color' => $game->theme_color,
            'categories' => ['games'],
            'icons' => $this->icons($game),
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);

        return $this->cached($request, $response, 300);
    }

    /** The name as a home screen can show it, 12 letters at the most: whole words where it can, else the first 12 letters. */
    private function shortName(string $name): string
    {
        if (mb_strlen($name) <= 12) {
            return $name;
        }

        $short = '';

        foreach (explode(' ', $name) as $word) {
            $next = $short === '' ? $word : "{$short} {$word}";

            if (mb_strlen($next) > 12) {
                break;
            }

            $short = $next;
        }

        return $short === '' ? mb_substr($name, 0, 12) : $short;
    }

    private function game(): Game
    {
        return Game::query()->findOrFail($this->current->id());
    }

    /** @return list<array{src: string, sizes: string, type: string, purpose: string}> */
    private function icons(Game $game): array
    {
        $icons = [];

        foreach (['icon' => 'any', 'icon_maskable' => 'maskable'] as $field => $purpose) {
            $picture = $game->{$field} === null ? null : $this->images->describe($game->{$field});

            if ($picture !== null) {
                $icons[] = ['src' => ContentImages::url($game->{$field}), 'sizes' => "{$picture['width']}x{$picture['height']}", 'type' => $picture['mime'], 'purpose' => $purpose];
            }
        }

        return $icons === [] ? self::DEFAULT_ICONS : $icons;
    }

    /** A copy is good for a while, and the `ETag` tells whether it is still the one. */
    private function cached(Request $request, JsonResponse $response, int $seconds): JsonResponse
    {
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge($seconds);
        $response->isNotModified($request);

        return $response;
    }
}
