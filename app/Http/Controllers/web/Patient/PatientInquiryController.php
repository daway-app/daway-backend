<?php

namespace App\Http\Controllers\web\Patient;

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
            'medicine_id' => 'required|exists:medicines,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);

        PatientInquiry::create([
            'user_id' => Auth::id(),
            'pharmacy_id' => $data['pharmacy_id'],
            'medicine_id' => $data['medicine_id'],
            'message' => $data['message'] ?? null,
            'status' => 'new',
        ]);

        if ($pharmacy->user) {
            \App\Models\Notification::create([
                'user_id' => $pharmacy->user->id,
                'medicine_id' => $data['medicine_id'],
                'type' => 'new_inquiry',
                'message' => __('layout.notif_new_inquiry', ['name' => $pharmacy->pharmacy_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'تم إرسال الاستفسار للصيدلية بنجاح');
    }
}
