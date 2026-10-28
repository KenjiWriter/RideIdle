<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Content\Vocabulary\Vocabulary;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Translation\Translator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the words of the pack in play over the texts of the server (lang/<language>/game.php) for the language of the request: from here on `__('game.errors.no_run')`
 * says what the vocabulary of the pack says. It runs after the language is chosen (SetLocale), and only on the routes of the game, which are the ones that speak
 * about its world.
 */
final class ApplyVocabulary
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = app()->getLocale();
        $lines = app(Vocabulary::class)->serverLines(Locale::normalize($locale) ?? Locale::default());

        if ($lines !== []) {
            $translator = app('translator');

            if ($translator instanceof Translator) {
                // A group that is not loaded yet would be taken as loaded with only these lines in it, and the rest of its texts would be lost.
                foreach (array_unique(array_map(static fn (string $key): string => explode('.', $key, 2)[0], array_keys($lines))) as $group) {
                    $translator->load('*', $group, $locale);
                }

                $translator->addLines($lines, $locale);
            }
        }

        return $next($request);
    }
}
