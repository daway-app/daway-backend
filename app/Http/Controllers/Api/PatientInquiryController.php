<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FcmSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientInquiryMessageRequest;
use App\Http\Requests\Api\PatientInquiryRequest;
use App\Http\Resources\PatientInquiryMessageResource;
use App\Http\Resources\PatientInquiryResource;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\Pharmacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PatientInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $inquiries = PatientInquiry::with(['pharmacy', 'medicine', 'messages'])
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
            $notification = Notification::create([
                'user_id' => $pharmacy->user->id,
                'medicine_id' => $data['medicine_id'],
                'type' => 'new_inquiry',
                'message' => __('layout.notif_new_inquiry', ['name' => $pharmacy->pharmacy_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);

            // FCM push بعد نجاح الإنشاء
            app(FcmSender::class)->fromNotification($notification);
        }

        $inquiry->load(['user', 'pharmacy', 'medicine']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الاستفسار بنجاح',
            'data' => new PatientInquiryResource($inquiry),
        ], 201);
    }

    public function messages(Request $request, PatientInquiry $inquiry): JsonResponse
    {
        abort_unless($inquiry->user_id === $request->user()->id, 403);

        if ($request->boolean('mark_read')) {
            PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
                ->where('sender_user_id', '!=', $request->user()->id)
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
        abort_unless($inquiry->user_id === $request->user()->id, 403);

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
            'sender_user_id' => $request->user()->id,
            'message' => $data['message'] ?? '',
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
        ]);

        $pharmacy = Pharmacy::find($inquiry->pharmacy_id);
        if ($pharmacy && $pharmacy->user) {
            $notification = Notification::create([
                'user_id' => $pharmacy->user->id,
                'medicine_id' => $inquiry->medicine_id,
                'type' => 'chat_message',
                'message' => 'رسالة جديدة من مريض',
                'is_read' => false,
                'created_at' => now(),
            ]);

            app(FcmSender::class)->fromNotification($notification);
        }

        $message->load(['sender']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الرسالة بنجاح',
            'data' => new PatientInquiryMessageResource($message),
        ], 201);
    }
}
