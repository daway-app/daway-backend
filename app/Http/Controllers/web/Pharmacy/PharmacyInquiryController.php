<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use Illuminate\Support\Facades\Auth;

class PharmacyInquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }
            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $inquiries = $pharmacy->availabilityNotifications()
            ->with(['user', 'medicine'])
            ->latest()
            ->paginate(10);
        $newCount = $pharmacy->availabilityNotifications()->where('is_notified', false)->count();
        $answeredCount = 0;
        $closedCount = $pharmacy->availabilityNotifications()->where('is_notified', true)->count();
        return view('pharmacy.inquiries.index', compact('pharmacy', 'inquiries', 'newCount', 'answeredCount', 'closedCount'));
    }

    public function update(Request $request, \App\Models\AvailabilityNotification $inquiry)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        if ($inquiry->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.inquiries.index')->with('error', 'لا يمكنك تعديل هذا الاستفسار');
        }
        $inquiry->update(['is_notified' => true]);
        return redirect()->route('pharmacy.inquiries.index')->with('success', 'تم تحديث حالة الاستفسار');
    }
}
