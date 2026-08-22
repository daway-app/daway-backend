<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InquiryStatusRequest;
use App\Http\Resources\PatientInquiryResource;
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

        $inquiry->update(['status' => $request->string('status')->toString()]);
        $inquiry->load(['user', 'medicine']);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ]);
    }
}
