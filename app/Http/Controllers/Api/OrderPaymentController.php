<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPaymentSubmission;
use App\Models\PaymentProblem;
use App\Services\OrderNotificationService;
use App\Services\PaymentFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentFlowService $payments,
        private readonly OrderNotificationService $notifications
    ) {}

    public function banks(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب بنوك الدفع المتاحة بنجاح.',
            'data' => $this->payments->activeBanks()
                ->map(fn ($bank) => $this->payments->bankPayload($bank))
                ->values(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        return $this->respond(
            $this->payments->patientPayment($order, $request->user())
        );
    }

    public function submitReceipt(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        $data = $request->validate([
            'bank_id' => ['required', 'integer', 'exists:payment_banks,id'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'transfer_reference' => ['nullable', 'string', 'max:100'],
            'transferred_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $result = $this->payments->submitReceipt(
            $order,
            $request->user(),
            $data,
            $request->file('receipt')
        );

        if ($result['ok']) {
            $this->notifications->receiptSubmitted(
                $order->fresh(),
                (int) ($result['data']['submission']['id'] ?? 0)
            );
        }

        return $this->respond($result);
    }

    public function reportProblem(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->role === 'patient', 403);

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(PaymentProblem::CATEGORIES)],
            'message' => ['required', 'string', 'max:2000'],
            'submission_id' => [
                'nullable',
                'integer',
                Rule::exists('order_payment_submissions', 'id')
                    ->where('order_id', $order->id),
            ],
        ]);

        $result = $this->payments->reportProblem($order, $request->user(), $data);

        if ($result['ok']) {
            $this->notifications->paymentProblemReported(
                $order->fresh(),
                (int) ($result['data']['problem']['id'] ?? 0)
            );
        }

        return $this->respond($result);
    }

    public function downloadReceipt(
        Request $request,
        Order $order,
        OrderPaymentSubmission $submission
    ): StreamedResponse|JsonResponse {
        abort_unless($request->user()->role === 'patient', 403);

        if ((int) $order->user_id !== (int) $request->user()->id
            || (int) $submission->order_id !== (int) $order->id) {
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
