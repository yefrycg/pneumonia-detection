<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the locale stored in the session, falling back to the configured
 * default so a visitor's language choice survives navigation.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && in_array($locale, config('app.supported_locales', ['en', 'es']), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
