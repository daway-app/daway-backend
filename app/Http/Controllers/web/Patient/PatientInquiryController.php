<?php

namespace App\Http\Controllers\web\Patient;

use App\Contracts\FcmSender;
use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PatientInquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id',
            // medicine_id اختياري: مراسلة مباشرة من الخريطة بلا اختيار دواء.
            'medicine_id' => 'nullable|exists:medicines,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);
        $medicineId = $data['medicine_id'] ?? null;

        PatientInquiry::create([
            'user_id' => Auth::id(),
            'pharmacy_id' => $data['pharmacy_id'],
            'medicine_id' => $medicineId,
            'message' => $data['message'] ?? null,
            'status' => 'new',
        ]);

        if ($pharmacy->user) {
            $notification = \App\Models\Notification::create([
                'user_id' => $pharmacy->user->id,
                'medicine_id' => $medicineId,
                'type' => 'new_inquiry',
                'message' => __('layout.notif_new_inquiry', ['name' => $pharmacy->pharmacy_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);

            // FCM push بعد نجاح الإنشاء
            app(FcmSender::class)->fromNotification($notification);
        }

        return redirect()->back()->with('success', 'تم إرسال الاستفسار للصيدلية بنجاح');
    }
}
