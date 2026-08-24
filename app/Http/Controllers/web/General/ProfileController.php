<?php

namespace App\Http\Controllers\web\General;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Support\Cloudinary;
use App\Support\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function edit()
    {
        return view('profile.edit', ['user' => Auth::user()]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $user->name = $request->input('name');

        if ($request->has('phone') && $request->input('phone') !== $user->phone) {
            $user->phone = $request->input('phone') ?: null;
        }

        if ($request->hasFile('avatar')) {
            Cloudinary::deleteLocal($user->avatar);
            $user->avatar = Cloudinary::upload($request->file('avatar'), 'avatars');
            $this->syncPharmacyLogo($user);
        }

        $user->save();

        return redirect()->route('profile.edit')->with('success', __('layout.profile_saved'));
    }

    /**
     * مزامنة الصورة: لحسابات الصيدليات صورة الحساب وشعار الصيدلية صورة وحدة —
     * أي تحديث على واحدة ينعكس تلقائياً على التانية.
     */
    private function syncPharmacyLogo($user): void
    {
        if ($user->role !== 'pharmacy') {
            return;
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if ($pharmacy) {
            $pharmacy->logo = $user->avatar;
            $pharmacy->save();
        }
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return back()->withErrors(['current_password' => __('layout.password_current_wrong')]);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return back()->with('password_success', __('layout.password_updated'));
    }

    public function updateAjax(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $user->name = $request->input('name');

        if ($request->hasFile('avatar')) {
            Cloudinary::deleteLocal($user->avatar);
            $user->avatar = Cloudinary::upload($request->file('avatar'), 'avatars');
            $this->syncPharmacyLogo($user);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'name' => $user->name,
            // الرابط إما Cloudinary أو القرص العام المحلي — Image::url يتكفل بالحالتين
            'avatar' => Image::url($user->avatar),
        ]);
    }
}
