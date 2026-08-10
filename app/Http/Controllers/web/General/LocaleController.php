<?php

namespace App\Http\Controllers\web\General;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class LocaleController extends Controller
{
    public function changeLocale($locale)
    {
        // Validate the locale
        if (! in_array($locale, ['en', 'ar'])) {
            $locale = 'en'; // Default to English if invalid locale is provided
        }

        App::setLocale($locale);
        Session::put('locale', $locale);

        // DEBUG: Check what's in the session immediately after putting it
        dd(Session::get('locale'));

        return redirect()->back();
    }
}
