<?php

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdentityTakenException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\ProviderAlreadyLinkedException;
use App\Exceptions\RuleRefusedException;
use App\Exceptions\RunAlreadyExistsException;
use App\Http\Middleware\ResolveGame;
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
        // The game comes first: the accounts that the guard looks for and the content that is read are those of the game of the host.
        $middleware->api(prepend: [
            ResolveGame::class,
        ], append: [
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

        $exceptions->render(fn (IdentityTakenException $e) => response()->json([
            'message' => __('account.identity.taken'),
            'code' => 'identity_taken',
        ], 409));

        $exceptions->render(fn (ProviderAlreadyLinkedException $e) => response()->json([
            'message' => __('account.identity.provider_linked'),
            'code' => 'provider_already_linked',
        ], 409));

        // A request id used again for something else is a mistake of the client, not a retry (a retry repeats the same thing).
        $exceptions->render(fn (IdempotencyConflictException $e) => response()->json([
            'message' => __('game.errors.request_reused'),
            'code' => 'request_reused',
        ], 409));

        $exceptions->render(fn (RuleRefusedException $e) => response()->json([
            'message' => __("game.errors.{$e->reason}", $e->replace),
            'code' => $e->reason,
            ...($e->replace !== [] ? ['details' => $e->replace] : []),
        ], 422));

        $exceptions->render(fn (InsufficientFundsException $e) => response()->json([
            'message' => __('game.errors.insufficient_funds'),
            'code' => 'insufficient_funds',
            'available_cents' => $e->availableCents,
            'required_cents' => $e->requiredCents,
        ], 422));
    })->create();
