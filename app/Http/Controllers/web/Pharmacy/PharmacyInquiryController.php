<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\Pharmacy;
use App\Services\InquiryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PharmacyInquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiries)
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }
            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        // القيم المعتمدة هي قيم عمود status الفعلية (PatientInquiry::STATUSES)
        if (! in_array($status, array_merge(['all'], PatientInquiry::STATUSES), true)) {
            $status = 'all';
        }

        $query = $pharmacy->patientInquiries()
            ->with(['user', 'medicine'])
            ->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($sq) use ($q) {
                $sq->where('message', 'like', "%{$q}%")
                    ->orWhere('reply', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('medicine', fn ($mq) => $mq->where('trade_name', 'like', "%{$q}%"));
            });
        }

        $inquiries = $query->paginate(50)->withQueryString();
        $newCount = $pharmacy->patientInquiries()->where('status', 'new')->count();
        $answeredCount = $pharmacy->patientInquiries()->where('status', 'answered')->count();
        $closedCount = $pharmacy->patientInquiries()->where('status', 'closed')->count();

        return view('pharmacy.inquiries.index', compact('pharmacy', 'inquiries', 'newCount', 'answeredCount', 'closedCount', 'q', 'status'));
    }

    public function update(Request $request, PatientInquiry $inquiry)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        if ($inquiry->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.inquiries.index')->with('error', 'لا يمكنك تعديل هذا الاستفسار');
        }
        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', PatientInquiry::STATUSES),
        ]);

        // M-10/M-36: المنطق الموحد عبر InquiryService — الويب أصبح يُشعر المريض
        // عند answered مثل الـ API (كان يحديث الحالة صامتاً)
        $this->inquiries->answer($inquiry, $pharmacy, ['status' => $data['status']]);

        return redirect()->route('pharmacy.inquiries.index')->with('success', 'تم تحديث حالة الاستفسار');
    }

    public function chat(Request $request, PatientInquiry $inquiry)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        if ($inquiry->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.inquiries.index')->with('error', 'لا يمكنك فتح هذه المحادثة');
        }

        $messages = PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
            ->with('sender')
            ->oldest('created_at')
            ->get();

        $inquiry->load(['user', 'medicine', 'pharmacy']);

        return view('pharmacy.inquiries.chat', compact('inquiry', 'messages', 'pharmacy'));
    }

    public function sendMessage(Request $request, PatientInquiry $inquiry)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        if ($inquiry->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.inquiries.index')->with('error', 'لا يمكنك الرد على هذا الاستفسار');
        }

        $validated = $request->validate([
            'message' => 'nullable|string|max:1000',
            'media' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if (! $request->hasFile('media') && ! $validated['message']) {
            return back()->with('error', 'يجب إرسال رسالة نصية أو صورة واحدة على الأقل.');
        }

        $mediaPath = null;
        $mediaType = null;

        if ($request->hasFile('media')) {
            $mediaPath = $request->file('media')->store('patient_inquiry_media', 'public');
            $mediaType = 'image';
        }

        PatientInquiryMessage::create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $user->id,
            'message' => $validated['message'] ?? '',
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
        ]);

        $this->inquiries->answer($inquiry, $pharmacy, ['status' => 'answered', 'reply' => $validated['message'] ?? null]);

        return redirect()->route('pharmacy.inquiries.chat', $inquiry)->with('success', 'تم إرسال الرسالة');
    }

    public function messagesJson(Request $request, PatientInquiry $inquiry)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        if ($inquiry->pharmacy_id !== $pharmacy->id) {
            abort(403);
        }

        if ($request->boolean('mark_read')) {
            PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
                ->where('sender_user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $messages = PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)
            ->with('sender')
            ->oldest('created_at')
            ->get();

        $data = $messages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'inquiry_id' => $msg->patient_inquiry_id,
                'sender_user_id' => $msg->sender_user_id,
                'message' => $msg->message,
                'media_url' => $msg->media_path ? Storage::url($msg->media_path) : null,
                'media_type' => $msg->media_type,
                'is_read' => $msg->read_at !== null,
                'read_at' => $msg->read_at?->toDateTimeString(),
                'created_at' => $msg->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
