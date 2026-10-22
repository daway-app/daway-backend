<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentBank;
use App\Services\PaymentFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentBankController extends Controller
{
    public function __construct(private readonly PaymentFlowService $payments) {}

    public function index(): JsonResponse
    {
        $banks = $this->payments->allBanks();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب بنوك الدفع بنجاح.',
            'data' => [
                'banks' => $banks->map(fn (PaymentBank $bank) => $this->payments->bankPayload($bank))->values(),
                'active_count' => $banks->where('is_active', true)->count(),
                'max_active' => PaymentFlowService::MAX_ACTIVE_BANKS,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->has('is_active')
            ? $request->boolean('is_active')
            : false;

        return $this->respond($this->payments->createBank($data));
    }

    public function update(Request $request, PaymentBank $paymentBank): JsonResponse
    {
        $data = $this->validated($request, partial: true);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $this->respond($this->payments->updateBank($paymentBank, $data));
    }

    public function toggleStatus(PaymentBank $paymentBank): JsonResponse
    {
        return $this->respond($this->payments->toggleBank($paymentBank));
    }

    public function destroy(PaymentBank $paymentBank): JsonResponse
    {
        return $this->respond($this->payments->deleteBank($paymentBank));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'bank_name' => [$required, 'string', 'max:100'],
            'account_holder' => [$required, 'string', 'max:100'],
            'account_number' => [$required, 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:34'],
            'branch' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
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
