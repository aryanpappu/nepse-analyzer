<?php

declare(strict_types=1);

use App\Exceptions\ClaudeApiException;
use App\Exceptions\ScrapeException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Compare the password hash on every operator request, including Redis sessions.
        $middleware->web(append: [AuthenticateSession::class]);
        $middleware->redirectGuestsTo('/admin/login');

        // The React dev server runs on a different origin, so the API is
        // consumed cross-origin with a bearer token.
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * The API must always answer with JSON, never an HTML error page — the
         * React client parses every response as JSON.
         */

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ScrapeException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::channel('scraper')->error('Scrape failure surfaced to the API', [
                'error' => $e->getMessage(),
                'context' => $e->context,
            ]);

            return response()->json([
                'message' => 'Market data could not be retrieved from any configured source.',
                // The underlying reason is operational detail; only expose it
                // when debugging is explicitly enabled.
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 503);
        });

        $exceptions->render(function (ClaudeApiException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::channel('analysis')->error('Claude API failure surfaced to the API', [
                'error' => $e->getMessage(),
                'context' => $e->context,
            ]);

            return response()->json([
                'message' => 'The AI analysis service is unavailable right now.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 503);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Not found.'], 404);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Authentication required.'], 401);
        });
    })->create();
