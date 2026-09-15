<?php

namespace App\Providers;

use App\Contracts\FcmSender;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use App\Observers\PharmacyMedicineObserver;
use App\Observers\RatingObserver;
use App\Services\Ai\MedicineIntentClient;
use App\Services\Ai\MedicineIntentService;
use App\Services\Ai\MedicineResolver;
use App\Services\Ai\OcrClient;
use App\Services\Fcm\FcmPushService;
use App\Services\PharmacyInventorySearch;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OcrClient::class, function () {
            return new OcrClient(
                baseUrl: (string) config('services.daway_ocr.base_url'),
                timeout: (int) config('services.daway_ocr.timeout', 20),
                key: config('services.daway_ocr.key'),
            );
        });

        $this->app->singleton(MedicineResolver::class);

        // مساعد المريض: عميل تحليل النية (اختياري — بلا base_url يعمل المسار
        // المحلي الحتمي فقط) + خدمة البحث في المخزون الحقيقي.
        $this->app->singleton(MedicineIntentClient::class, function () {
            return new MedicineIntentClient(
                baseUrl: (string) config('services.daway_ai.base_url'),
                timeout: (int) config('services.daway_ai.timeout', 8),
                key: config('services.daway_ai.key'),
            );
        });

        $this->app->singleton(MedicineIntentService::class, function ($app) {
            return new MedicineIntentService($app->make(MedicineIntentClient::class));
        });

        $this->app->singleton(PharmacyInventorySearch::class);

        // FCM: الإرسال عبر واجهة قابلة للاستبدال في الاختبارات (Fake sender).
        $this->app->singleton(FcmSender::class, FcmPushService::class);
    }

    public function boot(): void
    {
        // ترقيم الصفحات الموحّد — نفس الclasses في app.css فتتزهّن
        // أزرار التنقل في كل صفحات الأدمن والصيدلية تلقائياً.
        \Illuminate\Pagination\Paginator::defaultView('partials.pagination');
        \Illuminate\Pagination\Paginator::defaultSimpleView('partials.pagination');

        if (app()->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Relation::morphMap([
            'medicine' => \App\Models\Medicine::class,
            'first_aid' => \App\Models\FirstAid::class,
        ]);

        Rating::observe(RatingObserver::class);
        PharmacyMedicine::observe(PharmacyMedicineObserver::class);
    }
}
