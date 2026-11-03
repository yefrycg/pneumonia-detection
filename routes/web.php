<?php

use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RecommendationFeedbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', AnalyzerController::class)->name('analyzer.index');

Route::post('/analyze', PredictionController::class)
    ->middleware('throttle:analysis')
    ->name('analyzer.analyze');

Route::post('/recommend', RecommendationController::class)
    ->middleware('throttle:recommendation')
    ->name('analyzer.recommend');

Route::post('/recommendation/feedback', RecommendationFeedbackController::class)
    ->middleware('throttle:feedback')
    ->name('analyzer.feedback');

Route::get('/locale/{locale}', LocaleController::class)
    ->whereIn('locale', config('app.supported_locales', ['en', 'es']))
    ->name('locale.set');
