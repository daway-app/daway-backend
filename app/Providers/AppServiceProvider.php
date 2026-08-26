<?php

namespace App\Providers;

use App\Models\Rating;
use App\Observers\RatingObserver;
use App\Services\Ai\AiAssistantClient;
use App\Services\Ai\MedicineResolver;
use App\Services\Ai\OcrClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiAssistantClient::class, function () {
            return new AiAssistantClient(
                baseUrl: (string) config('services.daway_ai.base_url'),
                timeout: (int) config('services.daway_ai.timeout', 15),
                key: config('services.daway_ai.key'),
            );
        });

        $this->app->singleton(OcrClient::class, function () {
            return new OcrClient(
                baseUrl: (string) config('services.daway_ocr.base_url'),
                timeout: (int) config('services.daway_ocr.timeout', 20),
                key: config('services.daway_ocr.key'),
            );
        });

        $this->app->singleton(MedicineResolver::class);
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Relation::morphMap([
            'medicine' => \App\Models\Medicine::class,
            'first_aid' => \App\Models\FirstAid::class,
        ]);

        Rating::observe(RatingObserver::class);
    }
}
