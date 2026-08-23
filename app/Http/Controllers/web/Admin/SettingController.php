<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * All supported settings keys (whitelist) — any other key is ignored.
     */
    private const ALLOWED_KEYS = [
        'site_name',
        'site_description',
        'support_email',
        'support_phone',
        'default_language',
        'maintenance_mode',
        'auto_approve_pharmacies',
        'email_notifications',
        'notify_low_stock',
        'show_inactive_pharmacies',
        'max_search_radius',
        'search_limit',
        'session_timeout',
    ];

    /**
     * Display the settings page.
     *
     * @return View
     */
    public function index()
    {
        $settings = DB::table('settings')->pluck('value', 'key')->all();
        $catalogCount = \App\Models\MohMedicine::count();

        $catalogPath = base_path('database/data/moh_medicines.json');
        $catalogFileExists = is_file($catalogPath);
        $catalogFileSize = $catalogFileExists ? round(filesize($catalogPath) / 1024 / 1024, 2) : 0;

        return view('settings.index', compact('settings', 'catalogCount', 'catalogFileExists', 'catalogFileSize'));
    }

    /**
     * Update the specified settings in storage.
     *
     * @return RedirectResponse
     */
    public function update(Request $request)
    {
        $data = $request->only(self::ALLOWED_KEYS);

        foreach ($data as $key => $value) {
            // Use updateOrInsert to create or update the setting
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        }

        if (! empty($data)) {
            activity('settings')
                ->withProperties($data)
                ->log('تم تحديث الإعدادات');
        }

        // Apply the selected language immediately for the current user
        if ($request->has('default_language') && in_array($request->default_language, ['ar', 'en'])) {
            App::setLocale($request->default_language);
            Session::put('locale', $request->default_language);
        }

        return redirect()->back()->with('success', 'تم حفظ التغيرات والتعديلات بنجاح! 🎉');
    }
}
