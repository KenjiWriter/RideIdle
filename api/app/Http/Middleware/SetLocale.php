<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ustala język odpowiedzi API. Kolejność: nagłówek X-Locale (klient zna
 * wybór gracza), język zapisany na koncie, nagłówek Accept-Language,
 * język domyślny.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::normalize($request->header('X-Locale'))
            ?? Locale::normalize($request->user('sanctum')?->locale)
            ?? Locale::fromAcceptLanguage($request->header('Accept-Language'))
            ?? Locale::default();

        $laravelLocale = Locale::toLaravel($locale);

        app()->setLocale($laravelLocale);
        Carbon::setLocale($laravelLocale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language, X-Locale', false);

        return $response;
    }
}
