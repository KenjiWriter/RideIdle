<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Branding\Branding;
use App\Content\Branding\SharePage;
use App\Games\CurrentGame;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\ContentImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BrandingController extends Controller
{
    public function __construct(private readonly CurrentGame $current, private readonly ContentImages $images) {}

    /**
     * The style sheet of the game of the host: its colours and fonts as the variables of the app, and its own rules after them. A game that has none is given a
     * sheet that says so (the app does not ask for one then: `theme_css_url` of the game is null). It changes only when another pack is made live.
     */
    public function theme(Request $request, Branding $branding): Response
    {
        $css = $branding->stylesheet();
        $response = response($css === '' ? "/* This game has no style sheet of its own. */\n" : $css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $this->cached($request, $response, 60);
    }

    /**
     * The blocks of the front page of the game, with the name of each picture made an address; the texts are in every language the document has, and the app picks
     * the one of the visitor (English where the pack has none in that language). A game without a front page of its own has no blocks.
     */
    public function landing(Request $request, Branding $branding): JsonResponse
    {
        $blocks = [];

        foreach ($branding->landing()['blocks'] ?? [] as $block) {
            if (isset($block['image'])) {
                $block['image_url'] = ContentImages::url((string) $block['image']);
                unset($block['image']);
            }

            $blocks[] = $block;
        }

        return $this->cached($request, response()->json(['number' => $branding->number(), 'blocks' => $blocks]), 60);
    }

    /**
     * The page that a robot is given for the address of the game (a chat, a social network, a search engine): the page of the app with the tags of the preview of
     * the game in its head. The web server sends the robots here; a person is given the app itself, which names the page in its own way once it runs.
     */
    public function share(Request $request, Branding $branding): Response
    {
        $game = Game::query()->findOrFail($this->current->id());
        $share = $branding->share();
        $base = $this->current->webUrl();

        $description = $share['description'] === null
            ? ($game->is_default ? GameController::DEFAULT_DESCRIPTION : $game->name)
            : (string) ($share['description'][$game->locale] ?? $share['description']['en'] ?? '');

        $image = match (true) {
            $share['image'] !== null => $base.ContentImages::url($share['image']),
            $game->icon !== null => $base.ContentImages::url($game->icon),
            $game->is_default => $base.'/icons/icon-512.png',
            default => null,
        };

        $page = SharePage::render($this->shell(), [
            'lang' => $game->locale,
            'name' => $game->name,
            'title' => $share['title'] ?? $game->name,
            'description' => $description,
            'image' => $image,
            'url' => $base.'/',
            'color' => $game->theme_color,
        ]);

        return $this->cached($request, response($page, 200, ['Content-Type' => 'text/html; charset=utf-8']), 300);
    }

    /** The page of the app as the last build made it, or null where this API stands alone and there is no build to read. */
    private function shell(): ?string
    {
        $file = rtrim((string) config('ride.web_dist_path'), '/\\').DIRECTORY_SEPARATOR.'index.html';
        $shell = is_file($file) ? file_get_contents($file) : false;

        return $shell === false ? null : $shell;
    }

    /** A copy is good for a while, and the `ETag` tells whether it is still the one. */
    private function cached(Request $request, JsonResponse|Response $response, int $seconds): JsonResponse|Response
    {
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge($seconds);
        $response->isNotModified($request);

        return $response;
    }
}
