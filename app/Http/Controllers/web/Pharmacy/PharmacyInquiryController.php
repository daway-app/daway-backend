<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Services\InquiryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $inquiries = $query->paginate(7)->withQueryString();
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
}
