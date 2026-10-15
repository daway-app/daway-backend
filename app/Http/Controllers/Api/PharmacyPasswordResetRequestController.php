<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyPasswordResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * طلبات استعادة كلمة مرور الصيدليات.
 *
 * التدفق (الخيار «ب» — موافقة أدمن، لا OTP ولا رمز بالبريد):
 *   1) العام:  POST /api/pharmacy/password-reset-requests  { pharmacy_id, phone }
 *      يُنشئ طلباً بحالة `pending` (أو يتجاهل بصمت إن وُجد طلب معلّق).
 *   2) الأدمن: GET  /api/admin/pharmacy-password-reset-requests
 *   3) الأدمن: POST .../{id}/approve → يولّد كلمة مرور جديدة ويُعطّل
 *      الجلسات القائمة (`must_change_password = true` يجبر التغيير عند
 *      الدخول التالي)، ويعيدها في الرد **مرة واحدة**.
 *   4) الأدمن: POST .../{id}/reject  (مع `admin_notes` اختيارية).
 */
class PharmacyPasswordResetRequestController extends Controller
{
    /**
     * Public request endpoint. The same response is returned whether the ID and
     * phone match or not, so this endpoint cannot be used to enumerate accounts.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pharmacy_id' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($validated): void {
            $pharmacy = Pharmacy::query()
                ->where('pharmacy_custom_id', strtoupper(trim($validated['pharmacy_id'])))
                ->whereHas('user', fn ($query) => $query->where('phone', trim($validated['phone'])))
                ->lockForUpdate()
                ->first();

            if (! $pharmacy) {
                return;
            }

            $hasPending = PharmacyPasswordResetRequest::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->where('status', 'pending')
                ->exists();

            if (! $hasPending) {
                PharmacyPasswordResetRequest::create([
                    'pharmacy_id' => $pharmacy->id,
                    'status' => 'pending',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'إذا كانت بيانات الصيدلية مطابقة، فسيُراجع طلب الاستعادة من الإدارة.',
        ], 202);
    }

    /** GET /api/admin/pharmacy-password-reset-requests */
    public function index(): JsonResponse
    {
        $requests = PharmacyPasswordResetRequest::query()
            ->with(['pharmacy:id,pharmacy_name,pharmacy_custom_id,user_id', 'pharmacy.user:id,phone'])
            ->where('status', 'pending')
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $requests->getCollection()->map(fn (PharmacyPasswordResetRequest $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'created_at' => $item->created_at?->toISOString(),
                'pharmacy' => [
                    'id' => $item->pharmacy->id,
                    'name' => $item->pharmacy->pharmacy_name,
                    'pharmacy_id' => $item->pharmacy->pharmacy_custom_id,
                    'phone' => $item->pharmacy->user?->phone,
                ],
            ])->values(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /** POST /api/admin/pharmacy-password-reset-requests/{request}/approve */
    public function approve(PharmacyPasswordResetRequest $passwordResetRequest): JsonResponse
    {
        if ($passwordResetRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت مراجعة هذا الطلب مسبقًا.',
            ], 409);
        }

        $passwordResetRequest->load('pharmacy.user');
        $pharmacy = $passwordResetRequest->pharmacy;
        $user = $pharmacy?->user;

        if (! $pharmacy || ! $user) {
            return response()->json([
                'success' => false,
                'message' => 'تعذّر العثور على حساب مرتبط بهذا الطلب.',
            ], 422);
        }

        $plainPassword = Str::password(12, symbols: false);

        $admin = request()->user();

        DB::transaction(function () use ($passwordResetRequest, $pharmacy, $user, $plainPassword, $admin) {
            $lockedRequest = PharmacyPasswordResetRequest::query()
                ->lockForUpdate()
                ->findOrFail($passwordResetRequest->id);

            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => 'تمت مراجعة هذا الطلب مسبقًا.',
                ]);
            }

            $user->password = Hash::make($plainPassword);
            $user->must_change_password = true;
            $user->save();

            $pharmacy->delivered_at = null;
            $pharmacy->pending_password = Crypt::encryptString($plainPassword);
            $pharmacy->save();

            $lockedRequest->status = 'approved';
            $lockedRequest->reviewed_by = $admin->id;
            $lockedRequest->reviewed_at = now();
            $lockedRequest->save();
        });

        // Revoke existing sessions after credential rotation.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب وتوليد كلمة مرور جديدة. اعرضها واحفظها الآن؛ لن تظهر مرة أخرى.',
            'data' => [
                'credentials' => [
                    'pharmacy_id' => $pharmacy->pharmacy_custom_id,
                    'password' => $plainPassword,
                ],
            ],
        ]);
    }

    /** POST /api/admin/pharmacy-password-reset-requests/{request}/reject */
    public function reject(Request $request, PharmacyPasswordResetRequest $passwordResetRequest): JsonResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($passwordResetRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت مراجعة هذا الطلب مسبقًا.',
            ], 409);
        }

        $passwordResetRequest->status = 'rejected';
        $passwordResetRequest->reviewed_by = $request->user()->id;
        $passwordResetRequest->admin_notes = $validated['admin_notes'] ?? null;
        $passwordResetRequest->reviewed_at = now();
        $passwordResetRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'تم رفض طلب استعادة كلمة المرور.',
        ]);
    }
}
