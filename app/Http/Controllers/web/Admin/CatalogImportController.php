<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\MohMedicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

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
     * مزامنة أقسام الكتالوج — تشغيل moh:sync-categories من زرّ الأدمن.
     * Render المجاني يقطع request بعد ~100 ثانية فالعمل يعتمد وضع «القطع»:
     * كل طلب يعالج شريحة (--offset/--limit) ثم يُعاد تلقائياً لرصد تقدم حي،
     * إلى أن تكتمل (offset >= total) فيُستدعى Cache lock ويُعرض النجاح.
     */
    public function syncCategories(Request $request): RedirectResponse|View
    {
        set_time_limit(0);

        $stateKey = 'category-sync-state';
        $state = Cache::get($stateKey);
        $chunkLimit = 1500;

        // حالة تامة؟ ابدأ جولة جديدة إنها منتهية أو منهكة بعد ساعة
        if ($state !== null && ($state['offset'] ?? 0) >= ($state['total'] ?? PHP_INT_MAX)) {
            $state = null;
        }

        try {
            Artisan::call('moh:sync-categories', [
                '--offset' => (int) ($state['offset'] ?? 0),
                '--limit' => $chunkLimit,
                '--state' => $stateKey,
            ]);
        } catch (Throwable $e) {
            Log::error('CatalogImportController: فشلت شريحة مزامنة الأقسام', ['e' => $e]);
            Cache::forget($stateKey);

            return redirect()->route('categories.index')
                ->with('error', 'فشلت مزامنة الأقسام — أعد المحاولة: '.$e->getMessage());
        }

        $state = Cache::get($stateKey) ?? [];

        if (($state['offset'] ?? 0) >= ($state['total'] ?? 0) && $state['total'] > 0) {
            Cache::forget($stateKey);

            return redirect()->route('categories.index')->with(
                'success',
                'تمت مزامنة الأقسام بنجاح: '.$state['processed'].' سجلاً، '.
                    ($state['created'] ?? 0).' رابطاً منشأً و'.($state['updated'] ?? 0).' محدثاً.'
            );
        }

        // شريحة وسيطة: صفحة تقدم تفعل من نفسها عبر طلب POST جديد تلقائياً
        return view('categories.sync-progress', ['state' => $state]);
    }

    /**
     * تصنيف الأقسام الفرعية (فلاتر الموبايل) — وضع «القطع» نفسه:
     * كل طلب يعالج شريحة (classify:moh-catalog --offset/--limit --subcategories-only)
     * ثم تُعاد صفحة التقدم ذاتياً حتى اكتمال الكتالوج. روابط admin محفوظة.
     */
    public function classifySubcategories(Request $request): RedirectResponse|View
    {
        set_time_limit(0);

        $stateKey = 'catalog-classify-state';
        $state = Cache::get($stateKey);
        $chunkLimit = 1500;

        // جولة انتهت؟ ابدأ جولة جديدة
        if ($state !== null && ($state['offset'] ?? 0) >= ($state['total'] ?? PHP_INT_MAX)) {
            $state = null;
        }

        try {
            Artisan::call('classify:moh-catalog', [
                '--offset' => (int) ($state['offset'] ?? 0),
                '--limit' => $chunkLimit,
                '--subcategories-only' => true,
                '--state' => $stateKey,
            ]);
        } catch (Throwable $e) {
            Log::error('classifySubcategories: فشلت شريحة', ['e' => $e]);
            Cache::forget($stateKey);

            return redirect()->route('categories.index')
                ->with('error', 'فشل التصنيف الفرعي — أعد المحاولة: '.$e->getMessage());
        }

        $state = Cache::get($stateKey) ?? [];

        if (($state['offset'] ?? 0) >= ($state['total'] ?? 0) && (($state['total'] ?? 0) > 0)) {
            Cache::forget($stateKey);

            return redirect()->route('categories.index')->with(
                'success',
                'تم تصنيف الأقسام الفرعية بنجاح: '.$state['processed'].' سجلاً، '.$state['sub_linked'].' رابطاً فرعياً.'
            );
        }

        return view('categories.classify-progress', ['state' => $state]);
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