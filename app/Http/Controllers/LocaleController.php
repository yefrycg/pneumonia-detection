<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stores the visitor's language choice for the rest of the session.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('app.supported_locales', ['en', 'es']), true), 404);

        $request->session()->put('locale', $locale);

        return redirect()->route('analyzer.index');
    }
}
