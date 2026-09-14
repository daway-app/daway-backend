<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\MohMedicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CatalogImportController extends Controller
{
    /**
     * تشغيل استيراد كتالوج أدوية وزارة الصحة من الملف الثابت عبر المتصفح
     * (بديل عن أمر moh:import في حال عدم توفر الـ shell).
     */
    public function import(Request $request): RedirectResponse
    {
        set_time_limit(0);

        $count = MohMedicine::count();

        if ($count > 0) {
            return $this->backToPharmaciesTab()->with('success', __('settings.catalog_already_loaded', ['count' => number_format($count)]));
        }

        Log::info('CatalogImportController: بدء استيراد الكتالوج من المتصفح');

        $exitCode = Artisan::call('moh:import');

        $output = trim(Artisan::output());
        $newCount = MohMedicine::count();

        Log::info('CatalogImportController: انتهى moh:import', [
            'exit_code' => $exitCode,
            'new_count' => $newCount,
            'output' => $output,
        ]);

        if ($exitCode === 0 && $newCount > 0) {
            return $this->backToPharmaciesTab()->with('success', __('settings.catalog_import_success', ['count' => number_format($newCount)]));
        }

        return $this->backToPharmaciesTab()->with('error', __('settings.catalog_import_failed').($output ? ' ('.$output.')' : ''));
    }

    /**
     * مزامنة أقسام الكتالوج (تشغيل moh:sync-categories من زرّ الأدمن
     * لأن خطة Render المجانية لا توفر shell).
     * الأمر idempotent ويحافظ على روابط admin.
     */
    public function syncCategories(Request $request): RedirectResponse
    {
        set_time_limit(0);

        // قفل بسيط: منع تشغيل مزدوج بنفس الوقت (المزامنة دامغة لكن ثقيلة)
        $lockKey = 'category-sync-running';
        if (\Illuminate\Support\Facades\Cache::has($lockKey)) {
            return $this->backToCategoriesTab()->with('error', 'المزامنة قيد التنفيذ حالياً — انتظر قليلاً ثم حاول مجدداً.');
        }
        \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addMinutes(15));

        try {
            Log::info('CatalogImportController: بدء مزامنة أقسام الكتالوج من زر الأدمن');

            $exitCode = Artisan::call('moh:sync-categories');

            $output = trim(Artisan::output());
            $linkCount = \App\Models\CategoryMedicineLink::count();

            Log::info('CatalogImportController: انتهاء moh:sync-categories', [
                'exit_code' => $exitCode,
                'link_count' => $linkCount,
                'output' => $output,
            ]);

            if ($exitCode === 0 && $linkCount > 0) {
                return $this->backToCategoriesTab()->with(
                    'success',
                    'تمت مزامنة الأقسام بنجاح ('.number_format($linkCount).' رابطاً).'
                );
            }

            return $this->backToCategoriesTab()->with(
                'error',
                'فشلت مزامنة الأقسام'.($output ? ' ('.$output.')' : '')
            );
        } finally {
            \Illuminate\Support\Facades\Cache::forget($lockKey);
        }
    }

    private function backToCategoriesTab(): RedirectResponse
    {
        return redirect()->route('categories.index');
    }

    private function backToPharmaciesTab(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'pharmacies']);
    }
}