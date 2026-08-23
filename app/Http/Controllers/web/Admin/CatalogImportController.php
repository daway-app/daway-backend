<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\MohMedicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CatalogImportController extends Controller
{
    /**
     * تشغيل استيراد كتالوج أدوية وزارة الصحة من الملف الثابت عبر المتصفح
     * (بديل عن أمر moh:import في حال عدم توفر الـ shell).
     */
    public function import(Request $request): RedirectResponse
    {
        $count = MohMedicine::count();

        if ($count > 0) {
            return redirect()->back()->with('success', __('settings.catalog_already_loaded', ['count' => number_format($count)]));
        }

        $exitCode = Artisan::call('moh:import');

        $newCount = MohMedicine::count();

        if ($exitCode === 0 && $newCount > 0) {
            return redirect()->back()->with('success', __('settings.catalog_import_success', ['count' => number_format($newCount)]));
        }

        return redirect()->back()->with('error', __('settings.catalog_import_failed'));
    }
}