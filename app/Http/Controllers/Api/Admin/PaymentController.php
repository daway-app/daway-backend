<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPaymentSubmission;
use App\Models\PaymentProblem;
use App\Services\PaymentFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentFlowService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_status' => ['sometimes', 'string', Rule::in(array_merge(['all'], Order::PAYMENT_STATUSES))],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['payment_status'] ?? 'all';
        $q = trim((string) ($data['q'] ?? ''));
        $perPage = min(100, max(1, (int) ($data['per_page'] ?? 20)));

        $query = Order::query()
            ->with(['user', 'latestPaymentSubmission', 'openPaymentProblem'])
            ->latest('created_at');

        if ($status !== 'all') {
            $query->where('payment_status', $status);
        }

        if ($q !== '') {
            if (ctype_digit($q)) {
                $query->where(function ($search) use ($q) {
                    $search->whereKey((int) $q)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->whereRaw('phone LIKE ?', ['%'.$q.'%']));
                });
            } else {
                $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
                $query->whereHas('user', function ($userQuery) use ($like) {
                    $userQuery->where(function ($search) use ($like) {
                        $search->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(phone) LIKE ?', [$like]);
                    });
                });
            }
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب مدفوعات الطلبات بنجاح.',
            'data' => collect($orders->items())
                ->map(fn (Order $order) => $this->payments->adminPaymentRow($order))
                ->values(),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الدفع بنجاح.',
            'data' => $this->payments->adminPaymentDetail($order),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function approve(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(
            $this->payments->approvePayment(
                $order,
                $request->user(),
                $data['review_note'] ?? null
            )
        );
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->respond(
            $this->payments->rejectPayment($order, $request->user(), $data['reason'])
        );
    }

    public function downloadReceipt(
        Order $order,
        OrderPaymentSubmission $submission
    ): StreamedResponse|JsonResponse {
        if ((int) $submission->order_id !== (int) $order->id) {
            return response()->json(['success' => false, 'message' => 'الإيصال غير موجود.'], 404);
        }

        if (! Storage::disk('local')->exists($submission->receipt_path)) {
            return response()->json(['success' => false, 'message' => 'ملف الإيصال غير موجود.'], 404);
        }

        return Storage::disk('local')->download(
            $submission->receipt_path,
            $submission->receipt_original_name,
            ['Content-Type' => $submission->receipt_mime]
        );
    }

    public function problems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                'all',
                PaymentProblem::STATUS_OPEN,
                PaymentProblem::STATUS_RESOLVED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? 'all';
        $perPage = min(100, max(1, (int) ($data['per_page'] ?? 20)));

        $query = PaymentProblem::query()
            ->with(['order.user'])
            ->latest('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $problems = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب بلاغات الدفع بنجاح.',
            'data' => collect($problems->items())
                ->map(fn (PaymentProblem $problem) => $this->payments->adminProblemRow($problem))
                ->values(),
            'pagination' => [
                'total' => $problems->total(),
                'per_page' => $problems->perPage(),
                'current_page' => $problems->currentPage(),
                'last_page' => $problems->lastPage(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function resolveProblem(Request $request, PaymentProblem $paymentProblem): JsonResponse
    {
        $data = $request->validate([
            'admin_note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->respond(
            $this->payments->resolveProblem(
                $paymentProblem,
                $request->user(),
                $data['admin_note']
            )
        );
    }

    /** @param array{ok: bool, status: int, message: string, data: array<string, mixed>} $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status'], [], JSON_UNESCAPED_UNICODE);
    }
}
