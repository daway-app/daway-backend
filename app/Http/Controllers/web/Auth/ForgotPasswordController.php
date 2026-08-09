<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ForgotPasswordController extends Controller
{
    public function showForgotForm()
    {
        return view('forgot-password');
    }

    public function sendRequest(Request $request)
    {
        $request->validate([
            'login_id' => 'required|string',
        ]);

        $user = User::where('pharmacy_id', $request->login_id)
                    ->orWhere('email', $request->login_id)
                    ->first();

        if (!$user) {
            return back()->withErrors(['login_id' => 'لا يوجد حساب بهذا المعرف أو البريد الإلكتروني.']);
        }

        Mail::raw("طلب استعادة كلمة مرور من: {$user->name} ({$user->phone})", function ($message) {
            $message->to('admin@daway.com')
                    ->subject('طلب استعادة كلمة مرور');
        });

        return back()->with('status', 'تم إرسال طلبك، سنتواصل معك خلال 24 ساعة.');
    }
}
