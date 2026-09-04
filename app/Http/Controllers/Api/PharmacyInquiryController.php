<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InquiryStatusRequest;
use App\Http\Resources\PatientInquiryResource;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        abort_unless($pharmacy, 403);

        $inquiries = PatientInquiry::with(['user', 'medicine'])
            ->where('pharmacy_id', $pharmacy->id)
            ->latest()
            ->paginate(20);

        $counts = PatientInquiry::where('pharmacy_id', $pharmacy->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الاستفسارات بنجاح',
            'data' => PatientInquiryResource::collection($inquiries->items()),
            'counts' => [
                'new' => (int) ($counts['new'] ?? 0),
                'answered' => (int) ($counts['answered'] ?? 0),
                'closed' => (int) ($counts['closed'] ?? 0),
            ],
            'pagination' => [
                'total' => $inquiries->total(),
                'per_page' => $inquiries->perPage(),
                'current_page' => $inquiries->currentPage(),
                'last_page' => $inquiries->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, PatientInquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        abort_unless($pharmacy && $inquiry->pharmacy_id === $pharmacy->id, 403);

        $inquiry->load(['user', 'medicine']);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ]);
    }

    public function update(InquiryStatusRequest $request, PatientInquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        abort_unless($pharmacy && $inquiry->pharmacy_id === $pharmacy->id, 403);

        $data = $request->only(['status', 'reply', 'availability_status']);

        $hasReply = array_key_exists('reply', $data) && $data['reply'] !== null;

        if ($hasReply || (($data['status'] ?? null) === 'answered')) {
            $data['replied_at'] = now();
        }

        $shouldNotify = ($data['status'] ?? null) === 'answered'
            || $hasReply
            || array_key_exists('availability_status', $data);

        $inquiry->update($data);
        $inquiry->load(['user', 'medicine']);

        if ($shouldNotify && $inquiry->user_id) {
            $messageKey = 'layout.notif_inquiry_answered';
            $message = trans()->has($messageKey)
                ? __($messageKey, ['pharmacy' => $pharmacy->pharmacy_name])
                : 'تم الرد على استفسارك من صيدلية '.$pharmacy->pharmacy_name;

            Notification::create([
                'user_id' => $inquiry->user_id,
                'medicine_id' => $inquiry->medicine_id,
                'type' => 'inquiry_answered',
                'message' => $message,
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ]);
    }
}
