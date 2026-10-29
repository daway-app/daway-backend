<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPaymentSubmission;
use App\Models\PaymentBank;
use App\Models\PaymentProblem;
use App\Models\SubOrder;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Payment phase — manual bank transfer, admin review, problem resolution.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * Invariants owned here (contract: docs/payments/PAYMENT-PHASE-CONTRACT.md)
 * ════════════════════════════════════════════════════════════════════════════
 *  · At most 3 active banks; the last active bank cannot be removed.
 *  · At most one submission in `under_review` per order.
 *  · At most one `open` problem per order.
 *  · Approval/rejection only applies to the latest `under_review` submission.
 *  · Payment is parent-order-level: `orders.total + orders.delivery_fee`.
 *  · Receipt upload NEVER confirms payment; only an explicit admin approval.
 *  · The order payment status is mirrored to non-cancelled sub-orders for
 *    display only (stock/coupon/order-status logic is untouched).
 *
 * Mutating flows run inside a DB transaction with row locks; bank active-count
 * writes additionally take a shared cache lock so the read-check-write cycle
 * stays atomic even on engines where `lockForUpdate()` is a no-op (SQLite).
 */
class PaymentFlowService
{
    public const MAX_ACTIVE_BANKS = 3;

    public const BANK_WRITE_LOCK = 'daway:payment-banks:write';

    private const RECEIPT_DIRECTORY = 'payment_receipts';

    // ── Banks ────────────────────────────────────────────────────────────────

    /** @return Collection<int, PaymentBank> */
    public function activeBanks(): Collection
    {
        return PaymentBank::query()->active()->ordered()->get();
    }

    /** @return Collection<int, PaymentBank> */
    public function allBanks(): Collection
    {
        return PaymentBank::query()->ordered()->get();
    }

    public function activeBankCount(): int
    {
        return PaymentBank::query()->active()->count();
    }

    /** @param array<string, mixed> $data */
    public function createBank(array $data): array
    {
        return $this->withBankWriteLock(function () use ($data) {
            $isActive = (bool) ($data['is_active'] ?? false);

            if ($isActive && $this->activeBankCountLocked() >= self::MAX_ACTIVE_BANKS) {
                return $this->error(422, 'لا يمكن تفعيل أكثر من 3 بنوك نشطة.');
            }

            $bank = PaymentBank::create($data);

            return $this->ok('تمت إضافة البنك بنجاح.', [
                'bank' => $this->bankPayload($bank),
            ], 201);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBank(PaymentBank $bank, array $data): array
    {
        return $this->withBankWriteLock(function () use ($bank, $data) {
            $locked = PaymentBank::whereKey($bank->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'البنك غير موجود.');
            }

            $wasActive = (bool) $locked->is_active;
            $willBeActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $wasActive;

            if ($willBeActive && ! $wasActive && $this->activeBankCountLocked() >= self::MAX_ACTIVE_BANKS) {
                return $this->error(422, 'لا يمكن تفعيل أكثر من 3 بنوك نشطة.');
            }

            if (! $willBeActive && $wasActive && $this->activeBankCountLocked() <= 1) {
                return $this->error(422, 'لا يمكن تعطيل آخر بنك نشط.');
            }

            $locked->fill($data);
            $locked->save();

            return $this->ok('تم تحديث بيانات البنك بنجاح.', [
                'bank' => $this->bankPayload($locked->fresh()),
            ]);
        });
    }

    public function toggleBank(PaymentBank $bank): array
    {
        return $this->withBankWriteLock(function () use ($bank) {
            $locked = PaymentBank::whereKey($bank->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'البنك غير موجود.');
            }

            $newState = ! $locked->is_active;

            if ($newState && $this->activeBankCountLocked() >= self::MAX_ACTIVE_BANKS) {
                return $this->error(422, 'لا يمكن تفعيل أكثر من 3 بنوك نشطة.');
            }

            if (! $newState && $this->activeBankCountLocked() <= 1) {
                return $this->error(422, 'لا يمكن تعطيل آخر بنك نشط.');
            }

            $locked->is_active = $newState;
            $locked->save();

            return $this->ok(
                $newState ? 'تم تفعيل البنك.' : 'تم تعطيل البنك.',
                ['bank' => $this->bankPayload($locked->fresh())]
            );
        });
    }

    public function deleteBank(PaymentBank $bank): array
    {
        return $this->withBankWriteLock(function () use ($bank) {
            $locked = PaymentBank::whereKey($bank->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'البنك غير موجود.');
            }

            if ($locked->is_active && $this->activeBankCountLocked() <= 1) {
                return $this->error(422, 'لا يمكن حذف آخر بنك نشط.');
            }

            $locked->delete();

            return $this->ok('تم حذف البنك بنجاح.');
        });
    }

    // ── Patient flows ────────────────────────────────────────────────────────

    public function patientPayment(Order $order, User $patient): array
    {
        if ((int) $order->user_id !== (int) $patient->id) {
            return $this->error(404, 'الطلب غير موجود.');
        }

        $order->load(['latestPaymentSubmission', 'openPaymentProblem']);

        $latest = $order->latestPaymentSubmission;
        $openProblem = $order->openPaymentProblem;

        $cancelled = $order->status === Order::STATUS_CANCELLED;
        $paid = $order->payment_status === Order::PAYMENT_PAID;
        $underReview = $latest !== null
            && $latest->status === OrderPaymentSubmission::STATUS_UNDER_REVIEW;

        $canSubmitReceipt = ! $cancelled && ! $paid && ! $underReview && $openProblem === null;

        $canReportProblem = ! $cancelled
            && ! $paid
            && $openProblem === null
            && in_array($order->payment_status, [
                Order::PAYMENT_PENDING,
                Order::PAYMENT_UNDER_REVIEW,
                Order::PAYMENT_REJECTED,
            ], true);

        return $this->ok('تم جلب بيانات الدفع بنجاح.', [
            'order_id' => $order->id,
            'grand_total' => $this->grandTotal($order),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'can_submit_receipt' => $canSubmitReceipt,
            'can_report_problem' => $canReportProblem,
            'latest_submission' => $latest ? $this->submissionPayload($latest) : null,
            'problem' => $openProblem ? $this->problemPayload($openProblem) : null,
            'banks' => $this->activeBanks()->map(fn (PaymentBank $bank) => $this->bankPayload($bank))->values(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function submitReceipt(Order $order, User $patient, array $data, UploadedFile $receipt): array
    {
        if ((int) $order->user_id !== (int) $patient->id) {
            return $this->error(404, 'الطلب غير موجود.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            return $this->error(422, 'لا يمكن إرسال إيصال لطلب ملغى.');
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $this->error(422, 'الدفع معتمد بالفعل — لا يمكن إرسال إيصال جديد.');
        }

        if ($this->hasUnderReviewSubmission($order)) {
            return $this->error(409, 'يوجد إيصال قيد المراجعة لهذا الطلب.');
        }

        if ($this->hasOpenProblem($order)) {
            return $this->error(422, 'يوجد بلاغ دفع مفتوح لهذا الطلب. انتظر حله قبل إرسال إيصال.');
        }

        $path = $receipt->store(self::RECEIPT_DIRECTORY, 'local');

        if (! is_string($path) || $path === '') {
            return $this->error(500, 'تعذّر حفظ الإيصال. أعد المحاولة.');
        }

        try {
            $result = DB::transaction(function () use ($order, $data, $receipt, $path) {
                $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

                if (! $locked) {
                    return $this->error(404, 'الطلب غير موجود.');
                }

                if ($locked->status === Order::STATUS_CANCELLED) {
                    return $this->error(422, 'لا يمكن إرسال إيصال لطلب ملغى.');
                }

                if ($locked->payment_status === Order::PAYMENT_PAID) {
                    return $this->error(422, 'الدفع معتمد بالفعل — لا يمكن إرسال إيصال جديد.');
                }

                $underReview = OrderPaymentSubmission::query()
                    ->where('order_id', $locked->id)
                    ->where('status', OrderPaymentSubmission::STATUS_UNDER_REVIEW)
                    ->lockForUpdate()
                    ->first();

                if ($underReview) {
                    return $this->error(409, 'يوجد إيصال قيد المراجعة لهذا الطلب.');
                }

                $openProblem = PaymentProblem::query()
                    ->where('order_id', $locked->id)
                    ->where('status', PaymentProblem::STATUS_OPEN)
                    ->lockForUpdate()
                    ->first();

                if ($openProblem) {
                    return $this->error(422, 'يوجد بلاغ دفع مفتوح لهذا الطلب. انتظر حله قبل إرسال إيصال.');
                }

                $bank = PaymentBank::whereKey((int) $data['bank_id'])->lockForUpdate()->first();

                if (! $bank) {
                    return $this->error(422, 'البنك المحدد غير موجود.');
                }

                if (! $bank->is_active) {
                    return $this->error(422, 'البنك المحدد غير متاح.');
                }

                 $submission = OrderPaymentSubmission::create([
                    'order_id' => $locked->id,
                    'bank_id' => $bank->id,
                    'bank_name' => $bank->bank_name,
                    'account_holder' => $bank->account_holder,
                    'account_number' => $bank->account_number,
                    'iban' => $bank->iban,
                    'amount' => (float) $data['amount'],
                    'order_grand_total' => $this->grandTotal($locked),
                    'amount_status' => $this->compareAmount((float) $data['amount'], $this->grandTotal($locked)),
                    'overage_amount' => $this->overage((float) $data['amount'], $this->grandTotal($locked)),
                    'sender_name' => $data['sender_name'] ?? null,
                    'transfer_reference' => $data['transfer_reference'] ?? null,
                    'transferred_at' => $data['transferred_at'] ?? null,
                    'receipt_path' => $path,
                    'receipt_original_name' => $this->originalFileName($receipt),
                    'receipt_mime' => $this->receiptMime($receipt),
                    'receipt_size' => (int) ($receipt->getSize() ?: 0),
                    'status' => OrderPaymentSubmission::STATUS_UNDER_REVIEW,
                ]);

                $locked->update([
                    'payment_status' => Order::PAYMENT_UNDER_REVIEW,
                    'payment_method' => Order::PAYMENT_METHOD_BANK_TRANSFER,
                    'payment_reviewed_by' => null,
                    'payment_reviewed_at' => null,
                    'payment_rejection_reason' => null,
                    'payment_problem_reported_at' => null,
                ]);

                $this->mirrorPaymentStatus($locked, Order::PAYMENT_UNDER_REVIEW);

                return $this->ok('تم رفع إيصال التحويل بنجاح، وهو قيد المراجعة.', [
                    'submission' => $this->submissionPayload($submission->fresh()),
                    'payment_status' => $locked->fresh()->payment_status,
                ], 201);
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        if (! $result['ok']) {
            Storage::disk('local')->delete($path);
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function reportProblem(Order $order, User $patient, array $data): array
    {
        if ((int) $order->user_id !== (int) $patient->id) {
            return $this->error(404, 'الطلب غير موجود.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            return $this->error(422, 'لا يمكن تسجيل بلاغ على طلب ملغى.');
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $this->error(422, 'الدفع معتمد بالفعل — لا يمكن تسجيل بلاغ.');
        }

        if ($this->hasOpenProblem($order)) {
            return $this->error(409, 'يوجد بلاغ مفتوح لهذا الطلب بالفعل.');
        }

        $submissionId = isset($data['submission_id']) ? (int) $data['submission_id'] : null;

        if ($submissionId !== null && ! $this->submissionBelongsToOrder($order, $submissionId)) {
            return $this->error(404, 'الإيصال غير موجود.');
        }

        return DB::transaction(function () use ($order, $data, $submissionId) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ($locked->status === Order::STATUS_CANCELLED) {
                return $this->error(422, 'لا يمكن تسجيل بلاغ على طلب ملغى.');
            }

            if ($locked->payment_status === Order::PAYMENT_PAID) {
                return $this->error(422, 'الدفع معتمد بالفعل — لا يمكن تسجيل بلاغ.');
            }

            $openProblem = PaymentProblem::query()
                ->where('order_id', $locked->id)
                ->where('status', PaymentProblem::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($openProblem) {
                return $this->error(409, 'يوجد بلاغ مفتوح لهذا الطلب بالفعل.');
            }

            if ($submissionId !== null) {
                $submission = OrderPaymentSubmission::query()
                    ->whereKey($submissionId)
                    ->where('order_id', $locked->id)
                    ->lockForUpdate()
                    ->first();

                if (! $submission) {
                    return $this->error(404, 'الإيصال غير موجود.');
                }
            }

            $problem = PaymentProblem::create([
                'order_id' => $locked->id,
                'submission_id' => $submissionId,
                'category' => $data['category'],
                'message' => $data['message'],
                'status' => PaymentProblem::STATUS_OPEN,
            ]);

            $locked->update([
                'payment_status' => Order::PAYMENT_PROBLEM_REPORTED,
                'payment_problem_reported_at' => now(),
            ]);

            $this->mirrorPaymentStatus($locked, Order::PAYMENT_PROBLEM_REPORTED);

            return $this->ok('تم تسجيل بلاغ الدفع بنجاح.', [
                'problem' => $this->problemPayload($problem->fresh()),
                'payment_status' => $locked->fresh()->payment_status,
            ], 201);
        });
    }

    // ── Admin review ─────────────────────────────────────────────────────────

    public function adminPaymentRow(Order $order): array
    {
        $latest = $order->latestPaymentSubmission;
        $openProblem = $order->openPaymentProblem;
        $patient = $order->user;

        return [
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'grand_total' => $this->grandTotal($order),
            'patient' => $patient ? $this->patientPayload($patient) : null,
            'latest_submission_id' => $latest?->id,
            'latest_submission_status' => $latest?->status,
            'open_problem_id' => $openProblem?->id,
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }

    public function adminPaymentDetail(Order $order): array
    {
        $order->load([
            'user',
            'latestPaymentSubmission',
            'openPaymentProblem',
            'paymentSubmissions' => fn ($query) => $query->latest('id'),
            'paymentProblems' => fn ($query) => $query->latest('id'),
        ]);

        $latest = $order->latestPaymentSubmission;
        $openProblem = $order->openPaymentProblem;
        $paid = $order->payment_status === Order::PAYMENT_PAID;
        $cancelled = $order->status === Order::STATUS_CANCELLED;
        $latestUnderReview = $latest !== null
            && $latest->status === OrderPaymentSubmission::STATUS_UNDER_REVIEW;
        $reviewable = $latestUnderReview && ! $paid && ! $cancelled && $openProblem === null;

        return [
            'order' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
                'delivery_fee' => (float) $order->delivery_fee,
                'grand_total' => $this->grandTotal($order),
                'patient' => $order->user ? $this->patientPayload($order->user) : null,
                'created_at' => $order->created_at?->toDateTimeString(),
            ],
            'payment' => [
                'can_approve' => $reviewable,
                'can_reject' => $reviewable,
                'latest_submission_id' => $latest?->id,
            ],
            'submissions' => $order->paymentSubmissions
                ->map(fn (OrderPaymentSubmission $submission) => $this->submissionPayload($submission))
                ->values(),
            'problems' => $order->paymentProblems
                ->map(fn (PaymentProblem $problem) => $this->problemPayload($problem))
                ->values(),
            'banks' => $this->allBanks()->map(fn (PaymentBank $bank) => $this->bankPayload($bank))->values(),
        ];
    }

    public function approvePayment(Order $order, User $admin, ?string $reviewNote): array
    {
        return DB::transaction(function () use ($order, $admin, $reviewNote) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ($locked->status === Order::STATUS_CANCELLED) {
                return $this->error(422, 'لا يمكن اعتماد دفع طلب ملغى.');
            }

            // Idempotent once paid: the contract requires a repeat approval to
            // succeed without rewriting anything.
            if ($locked->payment_status === Order::PAYMENT_PAID) {
                return $this->ok('الدفع معتمد مسبقًا.', [
                    'order_id' => $locked->id,
                    'payment_status' => Order::PAYMENT_PAID,
                    'already_paid' => true,
                ]);
            }

            if ($this->openProblemExists($locked)) {
                return $this->error(422, 'يجب حل بلاغ الدفع المفتوح قبل اعتماد الإيصال.');
            }

            $submission = $this->latestSubmissionLocked($locked);

            if (! $submission || $submission->status !== OrderPaymentSubmission::STATUS_UNDER_REVIEW) {
                return $this->error(422, 'لا يوجد إيصال قيد المراجعة لهذا الطلب.');
            }

            // Phase 1 (P0-03) — verify the transfer amount against the CURRENT
            // server-computed totals before any approval mutation. The snapshot
            // taken at receipt time is trusted only to detect an order whose
            // total drifted since submission; the live amount-vs-grand_total is
            // recomputed now, so approval always reflects the real order.
            $currentGrandTotal = $this->grandTotal($locked);

            if ($submission->order_grand_total !== null && abs((float) $submission->order_grand_total - $currentGrandTotal) >= 0.005) {
                return $this->amountMismatchError((float) $submission->amount, $currentGrandTotal);
            }

            if ($this->compareAmount((float) $submission->amount, $currentGrandTotal) !== OrderPaymentSubmission::AMOUNT_MATCHES) {
                return $this->amountMismatchError((float) $submission->amount, $currentGrandTotal);
            }

            $submission->update([
                'status' => OrderPaymentSubmission::STATUS_APPROVED,
                'review_note' => $reviewNote,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $locked->update([
                'payment_status' => Order::PAYMENT_PAID,
                'payment_method' => Order::PAYMENT_METHOD_BANK_TRANSFER,
                'payment_reviewed_by' => $admin->id,
                'payment_reviewed_at' => now(),
                'payment_rejection_reason' => null,
                'payment_problem_reported_at' => null,
            ]);

            $this->mirrorPaymentStatus($locked, Order::PAYMENT_PAID);

            return $this->ok('تم اعتماد الدفع بنجاح.', [
                'order_id' => $locked->id,
                'payment_status' => Order::PAYMENT_PAID,
                'already_paid' => false,
                'submission' => $this->submissionPayload($submission->fresh()),
            ]);
        });
    }

    public function rejectPayment(Order $order, User $admin, string $reason): array
    {
        return DB::transaction(function () use ($order, $admin, $reason) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ($locked->status === Order::STATUS_CANCELLED) {
                return $this->error(422, 'لا يمكن رفض دفع طلب ملغى.');
            }

            if ($locked->payment_status === Order::PAYMENT_PAID) {
                return $this->error(422, 'الدفع معتمد بالفعل — لا يمكن رفضه.');
            }

            if ($this->openProblemExists($locked)) {
                return $this->error(422, 'يجب حل بلاغ الدفع المفتوح قبل رفض الإيصال.');
            }

            $submission = $this->latestSubmissionLocked($locked);

            if (! $submission || $submission->status !== OrderPaymentSubmission::STATUS_UNDER_REVIEW) {
                return $this->error(422, 'لا يوجد إيصال قيد المراجعة لهذا الطلب.');
            }

            $submission->update([
                'status' => OrderPaymentSubmission::STATUS_REJECTED,
                'review_note' => null,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $locked->update([
                'payment_status' => Order::PAYMENT_REJECTED,
                'payment_method' => Order::PAYMENT_METHOD_BANK_TRANSFER,
                'payment_reviewed_by' => $admin->id,
                'payment_reviewed_at' => now(),
                'payment_rejection_reason' => $reason,
                'payment_problem_reported_at' => null,
            ]);

            $this->mirrorPaymentStatus($locked, Order::PAYMENT_REJECTED);

            return $this->ok('تم رفض الإيصال.', [
                'order_id' => $locked->id,
                'payment_status' => Order::PAYMENT_REJECTED,
                'submission' => $this->submissionPayload($submission->fresh()),
            ]);
        });
    }

    public function resolveProblem(PaymentProblem $problem, User $admin, string $adminNote): array
    {
        return DB::transaction(function () use ($problem, $admin, $adminNote) {
            // Lock the parent order first, matching report/approve/reject order
            // so concurrent flows cannot deadlock on (order, problem).
            $lockedOrder = Order::whereKey($problem->order_id)->lockForUpdate()->first();

            if (! $lockedOrder) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            $lockedProblem = PaymentProblem::whereKey($problem->getKey())->lockForUpdate()->first();

            if (! $lockedProblem) {
                return $this->error(404, 'البلاغ غير موجود.');
            }

            if ($lockedProblem->status !== PaymentProblem::STATUS_OPEN) {
                return $this->error(422, 'تم حل هذا البلاغ بالفعل.');
            }

            $lockedProblem->update([
                'status' => PaymentProblem::STATUS_RESOLVED,
                'admin_note' => $adminNote,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            if ($lockedOrder->status !== Order::STATUS_CANCELLED) {
                $latest = $this->latestSubmissionLocked($lockedOrder);
                $derived = $this->derivePaymentStatus($latest);

                $lockedOrder->update([
                    'payment_status' => $derived,
                    'payment_problem_reported_at' => null,
                    'payment_rejection_reason' => $derived === Order::PAYMENT_REJECTED
                        ? ($latest?->rejection_reason ?? $lockedOrder->payment_rejection_reason)
                        : null,
                ]);

                $this->mirrorPaymentStatus($lockedOrder, $derived);
            }

            return $this->ok('تم حل بلاغ الدفع بنجاح.', [
                'problem' => $this->problemPayload($lockedProblem->fresh()),
                'payment_status' => $lockedOrder->fresh()->payment_status,
            ]);
        });
    }

    // ── Payload builders ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function bankPayload(PaymentBank $bank): array
    {
        return [
            'id' => $bank->id,
            'bank_name' => $bank->bank_name,
            'account_holder' => $bank->account_holder,
            'account_number' => $bank->account_number,
            'iban' => $bank->iban,
            'branch' => $bank->branch,
            'instructions' => $bank->instructions,
            'is_active' => (bool) $bank->is_active,
            'sort_order' => (int) $bank->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public function submissionPayload(OrderPaymentSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'order_id' => $submission->order_id,
            'bank_id' => $submission->bank_id,
            'bank_name' => $submission->bank_name,
            'account_holder' => $submission->account_holder,
            'account_number' => $submission->account_number,
            'iban' => $submission->iban,
            'amount' => (float) $submission->amount,
            'order_grand_total' => (float) $submission->order_grand_total,
            'amount_status' => $submission->amount_status,
            'overage_amount' => (float) $submission->overage_amount,
            'sender_name' => $submission->sender_name,
            'transfer_reference' => $submission->transfer_reference,
            'transferred_at' => $submission->transferred_at?->toDateString(),
            'receipt_original_name' => $submission->receipt_original_name,
            'receipt_mime' => $submission->receipt_mime,
            'receipt_size' => (int) $submission->receipt_size,
            'status' => $submission->status,
            'review_note' => $submission->review_note,
            'rejection_reason' => $submission->rejection_reason,
            'reviewed_at' => $submission->reviewed_at?->toDateTimeString(),
            'created_at' => $submission->created_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    public function problemPayload(PaymentProblem $problem): array
    {
        return [
            'id' => $problem->id,
            'order_id' => $problem->order_id,
            'submission_id' => $problem->submission_id,
            'category' => $problem->category,
            'message' => $problem->message,
            'status' => $problem->status,
            'admin_note' => $problem->admin_note,
            'resolved_by' => $problem->resolved_by,
            'resolved_at' => $problem->resolved_at?->toDateTimeString(),
            'created_at' => $problem->created_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    public function adminProblemRow(PaymentProblem $problem): array
    {
        $order = $problem->order;
        $patient = $order?->user;

        return array_merge($this->problemPayload($problem), [
            'payment_status' => $order?->payment_status,
            'patient' => $patient ? $this->patientPayload($patient) : null,
        ]);
    }

    /** @return array{id: int, name: string, phone: ?string, email: ?string} */
    private function patientPayload(User $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'phone' => $patient->phone,
            'email' => $patient->email,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function grandTotal(Order $order): float
    {
        return round((float) $order->total + (float) $order->delivery_fee, 2);
    }

    /**
     * Phase 1 (P0-03) — compare the patient-declared transfer amount to the
     * server-computed grand total. Comparison is done on 2-decimal values so a
     * float artifact on either side can never cause a false match.
     */
    private function compareAmount(float $declared, float $expected): string
    {
        $d = round($declared, 2);
        $e = round($expected, 2);

        if (abs($d - $e) < 0.005) {
            return OrderPaymentSubmission::AMOUNT_MATCHES;
        }

        return $d < $e ? OrderPaymentSubmission::AMOUNT_UNDER : OrderPaymentSubmission::AMOUNT_OVER;
    }

    private function overage(float $declared, float $expected): float
    {
        return $declared > $expected ? round($declared - $expected, 2) : 0.0;
    }

    /** @return array{ok: false, status: int, message: string, data: array<string, mixed>} */
    private function amountMismatchError(float $declared, float $expected): array
    {
        $cmp = $this->compareAmount($declared, $expected);
        $diff = $cmp === OrderPaymentSubmission::AMOUNT_UNDER
            ? round($expected - $declared, 2)
            : round($declared - $expected, 2);

        return [
            'ok' => false,
            'status' => 422,
            'message' => 'مبلغ التحويل لا يطابق إجمالي الطلب.',
            'data' => [
                'code' => 'amount_unmatched',
                'amount' => round($declared, 2),
                'grand_total' => round($expected, 2),
                $cmp === OrderPaymentSubmission::AMOUNT_UNDER ? 'deficit' : 'overage' => $diff,
            ],
        ];
    }

    private function hasUnderReviewSubmission(Order $order): bool
    {
        return OrderPaymentSubmission::query()
            ->where('order_id', $order->id)
            ->where('status', OrderPaymentSubmission::STATUS_UNDER_REVIEW)
            ->exists();
    }

    private function hasOpenProblem(Order $order): bool
    {
        return PaymentProblem::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentProblem::STATUS_OPEN)
            ->exists();
    }

    private function openProblemExists(Order $order): bool
    {
        return PaymentProblem::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentProblem::STATUS_OPEN)
            ->lockForUpdate()
            ->first() !== null;
    }

    private function submissionBelongsToOrder(Order $order, int $submissionId): bool
    {
        return OrderPaymentSubmission::query()
            ->whereKey($submissionId)
            ->where('order_id', $order->id)
            ->exists();
    }

    private function latestSubmissionLocked(Order $order): ?OrderPaymentSubmission
    {
        return OrderPaymentSubmission::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function derivePaymentStatus(?OrderPaymentSubmission $latest): string
    {
        return match ($latest?->status) {
            OrderPaymentSubmission::STATUS_APPROVED => Order::PAYMENT_PAID,
            OrderPaymentSubmission::STATUS_UNDER_REVIEW => Order::PAYMENT_UNDER_REVIEW,
            OrderPaymentSubmission::STATUS_REJECTED => Order::PAYMENT_REJECTED,
            default => Order::PAYMENT_PENDING,
        };
    }

    private function mirrorPaymentStatus(Order $order, string $status): void
    {
        // Display-only mirror. Cancelled sub-orders keep their own value, and
        // the parent order remains the single source of truth.
        $order->subOrders()
            ->where('status', '!=', SubOrder::STATUS_CANCELLED)
            ->update(['payment_status' => $status]);
    }

    private function originalFileName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());

        return mb_substr($name !== '' ? $name : 'receipt', 0, 255);
    }

    private function receiptMime(UploadedFile $file): string
    {
        $mime = $file->getMimeType();

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withBankWriteLock(Closure $callback)
    {
        $lock = Cache::lock(self::BANK_WRITE_LOCK, 30);

        if (! $lock->get()) {
            return $this->error(409, 'هناك عملية تعديل أخرى على البنوك قيد التنفيذ. أعد المحاولة بعد لحظات.');
        }

        try {
            return DB::transaction($callback);
        } finally {
            $lock->release();
        }
    }

    private function activeBankCountLocked(): int
    {
        return PaymentBank::query()->active()->lockForUpdate()->count();
    }

    /** @param array<string, mixed> $data */
    private function ok(string $message, array $data = [], int $status = 200): array
    {
        return ['ok' => true, 'status' => $status, 'message' => $message, 'data' => $data];
    }

    private function error(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => []];
    }
}
