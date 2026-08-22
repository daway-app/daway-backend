<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientInquiryRequest;
use App\Http\Resources\PatientInquiryResource;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $inquiries = PatientInquiry::with(['pharmacy', 'medicine'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الاستفسارات بنجاح',
            'data' => PatientInquiryResource::collection($inquiries->items()),
            'pagination' => [
                'total' => $inquiries->total(),
                'per_page' => $inquiries->perPage(),
                'current_page' => $inquiries->currentPage(),
                'last_page' => $inquiries->lastPage(),
            ],
        ]);
    }

    public function store(PatientInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);

        $inquiry = PatientInquiry::create([
            'user_id' => $request->user()->id,
            'pharmacy_id' => $data['pharmacy_id'],
            'medicine_id' => $data['medicine_id'],
            'message' => $data['message'] ?? null,
            'status' => 'new',
        ]);

        if ($pharmacy->user) {
            Notification::create([
                'user_id' => $pharmacy->user->id,
                'medicine_id' => $data['medicine_id'],
                'type' => 'new_inquiry',
                'message' => __('layout.notif_new_inquiry', ['name' => $pharmacy->pharmacy_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        $inquiry->load(['user', 'pharmacy', 'medicine']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ], 201);
    }
}
