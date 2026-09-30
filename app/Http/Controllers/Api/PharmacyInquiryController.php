<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InquiryStatusRequest;
use App\Http\Requests\Api\PatientInquiryMessageRequest;
use App\Http\Resources\PatientInquiryMessageResource;
use App\Http\Resources\PatientInquiryResource;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Services\InquiryService;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use App\Contracts\FcmSender;

class PharmacyInquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        abort_unless($pharmacy, 403);

        $inquiries = PatientInquiry::with(['user', 'medicine', 'messages'])
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

        $pharmacy = PharmacyContext::forUser($user);
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

        $pharmacy = PharmacyContext::forUser($user);
        abort_unless($pharmacy && $inquiry->pharmacy_id === $pharmacy->id, 403);

        $data = $request->only(['status', 'reply', 'availability_status']);

        // M-10: المنطق الموحد (replied_at + إشعار + FCM) في InquiryService
        $inquiry = $this->inquiries->answer($inquiry, $pharmacy, $data);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ]);
    }

    public function messages(Request $request, PatientInquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        abort_unless($pharmacy && $inquiry->pharmacy_id === $pharmacy->id, 403);

        if ($request->boolean('mark_read')) {
            PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
                ->where('sender_user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $messages = PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
            ->with('sender')
            ->oldest('created_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الرسائل بنجاح',
            'data' => PatientInquiryMessageResource::collection($messages->items()),
            'pagination' => [
                'total' => $messages->total(),
                'per_page' => $messages->perPage(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
            ],
        ]);
    }

    public function sendMessage(PatientInquiryMessageRequest $request, PatientInquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        abort_unless($pharmacy && $inquiry->pharmacy_id === $pharmacy->id, 403);

        $data = $request->validated();
        $mediaPath = null;
        $mediaType = null;

        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $path = $file->store('patient_inquiry_media', 'public');
            $mediaPath = $path;
            $mediaType = 'image';
        }

        $message = PatientInquiryMessage::create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $user->id,
            'message' => $data['message'] ?? '',
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
        ]);

        $notification = Notification::create([
            'user_id' => $inquiry->user_id,
            'medicine_id' => $inquiry->medicine_id,
            'type' => 'chat_message',
            'message' => 'رسالة جديدة من صيدلتك',
            'is_read' => false,
            'created_at' => now(),
        ]);

        app(FcmSender::class)->fromNotification($notification);

        $message->load(['sender']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الرسالة بنجاح',
            'data' => new PatientInquiryMessageResource($message),
        ], 201);
    }
}
