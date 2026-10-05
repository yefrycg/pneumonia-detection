<?php

use App\Exceptions\InferenceException;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Inference failures are expected operational states, not defects, so
         * they are logged as warnings with a curated context and suppressed
         * from the default reporter. The context never contains image data.
         */
        $exceptions->report(function (InferenceException $exception): bool {
            Log::warning($exception->getMessage(), $exception->context());

            return false;
        });

        $exceptions->render(function (InferenceException $exception, Request $request) {
            if (! $request->is('analyze')) {
                return false;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => __($exception->translationKey()),
                ],
            ], $exception->httpStatus());
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('analyze')) {
                return false;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => __('errors.inference.VALIDATION_ERROR'),
                    'fields' => $exception->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('analyze')) {
                return false;
            }

            $headers = array_intersect_key(
                $exception->getHeaders(),
                array_flip(['Retry-After', 'X-RateLimit-Limit']),
            );

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => __('errors.inference.RATE_LIMITED'),
                ],
            ], 429, $headers);
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! $request->is('analyze')) {
                return false;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => __('errors.inference.SESSION_EXPIRED'),
                ],
            ], 419);
        });
    })->create();
