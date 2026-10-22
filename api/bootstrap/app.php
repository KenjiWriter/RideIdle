<?php

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RunAlreadyExistsException;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Refusals of the game rules have a stable `code` for the client next to the translated message.
        $exceptions->render(fn (RunAlreadyExistsException $e) => response()->json([
            'message' => __('game.errors.run_exists'),
            'code' => 'run_exists',
            'run_id' => $e->runId,
        ], 409));

        $exceptions->render(fn (InsufficientFundsException $e) => response()->json([
            'message' => __('game.errors.insufficient_funds'),
            'code' => 'insufficient_funds',
            'available_cents' => $e->availableCents,
            'required_cents' => $e->requiredCents,
        ], 422));
    })->create();
