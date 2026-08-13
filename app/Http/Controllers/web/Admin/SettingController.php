<?php

namespace App\Http\Controllers\web\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class SettingController extends Controller
{
    /**
     * Display the settings page.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $settings = DB::table('settings')->pluck('value', 'key')->all();
        return view('settings.index', compact('settings'));
    }

    /**
     * Update the specified settings in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $data = $request->except('_token');

        foreach ($data as $key => $value) {
            // Use updateOrInsert to create or update the setting
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        }

        // Apply the selected language immediately for the current user
        if ($request->has('default_language') && in_array($request->default_language, ['ar', 'en'])) {
            App::setLocale($request->default_language);
            Session::put('locale', $request->default_language);
        }

        return redirect()->back()->with('success', 'تم حفظ التغيرات والتعديلات بنجاح! 🎉');
    }
}
