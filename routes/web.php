<?php

use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PredictionController;
use Illuminate\Support\Facades\Route;

Route::get('/', AnalyzerController::class)->name('analyzer.index');

Route::post('/analyze', PredictionController::class)
    ->middleware('throttle:analysis')
    ->name('analyzer.analyze');

Route::get('/locale/{locale}', LocaleController::class)
    ->whereIn('locale', config('app.supported_locales', ['en', 'es']))
    ->name('locale.set');
