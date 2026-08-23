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

    private function backToPharmaciesTab(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'pharmacies']);
    }
}