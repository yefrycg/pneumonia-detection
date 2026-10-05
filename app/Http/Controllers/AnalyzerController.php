<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Renders the analyzer page. Inference lives behind the analyze endpoint.
 */
final class AnalyzerController extends Controller
{
    public function __invoke(): View
    {
        return view('analyzer.index', [
            'classes' => config('inference.classes'),
            'maxUploadKb' => (int) config('inference.upload.max_kb'),
            'acceptedFormats' => implode(', ', config('inference.upload.allowed_extensions')),
        ]);
    }
}
