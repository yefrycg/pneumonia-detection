<?php

namespace App\Providers;

use App\Contracts\InferenceClient;
use App\Services\Inference\HttpInferenceClient;
use App\Support\ImageInspector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bind the inference boundary to its HTTP implementation. Tests swap this
     * binding, or fake the HTTP layer, without touching production code.
     */
    public function register(): void
    {
        $this->app->bind(InferenceClient::class, HttpInferenceClient::class);

        $this->app->bind(
            ImageInspector::class,
            fn (): ImageInspector => new ImageInspector((int) config('inference.upload.max_dimension')),
        );
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('analysis', fn (Request $request): Limit => Limit::perMinute(
            (int) config('inference.rate_limit', 20)
        )->by('analysis|'.$request->ip()));
    }
}
