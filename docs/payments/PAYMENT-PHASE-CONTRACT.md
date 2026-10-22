# Daway Payment Phase — Frozen Contract (bank transfer, manual review)

This contract is the source of truth for backend, React Admin and Flutter work in
this phase. It is additive: existing order, checkout, delivery and pharmacy APIs
keep their current behavior.

## Scope

- Manual bank transfer only. No payment gateway.
- Admin-managed destination banks (up to 3 active).
- Patient selects an active bank, uploads a receipt, and may report a transfer
  problem.
- Admin reviews the receipt, approves or rejects it, and resolves problems.
- Receipt upload does not mark an order paid. Only an explicit admin approval
  does. A problem report never confirms payment and never cancels an order.
- Payment is parent-order-level. Grand total is `orders.total + orders.delivery_fee`.

## Payment status machine (orders.payment_status)

- `pending` → `under_review` (patient submits receipt)
- `under_review` → `paid` (admin approves)
- `under_review` → `rejected` (admin rejects with a reason)
- `rejected` → `under_review` (patient resubmits)
- `pending | under_review | rejected` → `problem_reported` (patient opens a problem)
- `problem_reported` → previous derived state (admin resolves the problem)
- `paid` is terminal for payment actions.
- A cancelled order cannot receive a receipt, a problem report, approval or rejection.

Submission statuses: `under_review`, `approved`, `rejected`.
Problem statuses: `open`, `resolved`.
Problem categories: `bank_details`, `amount`, `receipt`, `other`.

At most:
- one `under_review` submission per order;
- one `open` problem per order.

## Data

### payment_banks

`id, bank_name, account_holder, account_number, iban, branch, instructions,
is_active, sort_order, timestamps`

- Maximum 3 active banks.
- The last active bank cannot be deactivated or deleted.
- No bank rows are seeded: data is created by an admin, never hardcoded.

### order_payment_submissions

`id, order_id, bank_id, bank snapshots (name/account holder/account number/iban),
amount, sender_name, transfer_reference, transferred_at, receipt_path,
receipt_original_name, receipt_mime, receipt_size, status, review_note,
rejection_reason, reviewed_by, reviewed_at, timestamps`

- Receipts are stored on the private `local` disk under `payment_receipts/`.
- Historical bank details are snapshotted so editing a bank never rewrites a
  past submission.

### payment_problems

`id, order_id, submission_id, category, message, status, admin_note,
resolved_by, resolved_at, timestamps`

### orders additions

`payment_reviewed_by, payment_reviewed_at, payment_rejection_reason,
payment_problem_reported_at` (all nullable; no destructive change).

## Patient API

All routes: `auth:sanctum`, patient role, patient ownership enforced.

### GET `/api/patient/payment-banks`

`{ success, message, data: [bank...] }`

### GET `/api/patient/orders/{order}/payment`

```json
{
  "success": true,
  "message": "...",
  "data": {
    "order_id": 1,
    "grand_total": 100.0,
    "payment_status": "pending",
    "payment_method": null,
    "can_submit_receipt": true,
    "can_report_problem": true,
    "latest_submission": null,
    "problem": null,
    "banks": []
  }
}
```

`latest_submission` and `problem` are null or objects. `can_submit_receipt` is
false while a submission is under review, when the order is paid/cancelled, or
when an open problem exists. `can_report_problem` is true for `pending`,
`under_review` and `rejected` orders while no open problem exists — including a
bare `pending` order with no receipt — and false when paid/cancelled or when an
open problem exists.

### POST `/api/patient/orders/{order}/payment/receipt` (multipart)

Fields:
- `bank_id` required active bank
- `amount` required numeric `>= 0`
- `receipt` required file: jpg/jpeg/png/webp/pdf, max 5 MB
- `sender_name` optional, max 100
- `transfer_reference` optional, max 100
- `transferred_at` optional date, not after today

Returns `201` with `data.submission`, `data.payment_status`. Duplicate while a
submission is under review: `409`.

### POST `/api/patient/orders/{order}/payment/problem`

Fields: `category` required, `message` required max 2000, `submission_id`
optional (must belong to the order).

Returns `201` with `data.problem`, `data.payment_status`. Duplicate open problem:
`409`.

### GET `/api/patient/orders/{order}/payment/submissions/{submission}/receipt`

Streams the private receipt only when the order belongs to the patient and the
submission belongs to the order.

## Admin API

All routes: `auth:sanctum`, `role:admin`.

### Banks

- `GET /api/admin/payment-banks`
  `{ success, data: { banks, active_count, max_active: 3 } }`
- `POST /api/admin/payment-banks`
- `PUT|PATCH /api/admin/payment-banks/{paymentBank}`
- `PATCH /api/admin/payment-banks/{paymentBank}/toggle-status`
- `DELETE /api/admin/payment-banks/{paymentBank}`

Create defaults to `is_active=false` unless explicitly set. Activating a fourth
bank or removing the last active bank returns `422`.

### Payments

- `GET /api/admin/payments?payment_status=&q=&page=&per_page=`
- `GET /api/admin/payments/{order}`
- `POST /api/admin/payments/{order}/approve` body `{ review_note?: string }`
- `POST /api/admin/payments/{order}/reject` body `{ reason: string required }`
- `GET /api/admin/payments/{order}/submissions/{submission}/receipt`

List row:

```json
{
  "order_id": 1,
  "payment_status": "under_review",
  "payment_method": "bank_transfer",
  "grand_total": 100.0,
  "patient": { "id": 2, "name": "...", "phone": "...", "email": "..." },
  "latest_submission_id": 4,
  "latest_submission_status": "under_review",
  "open_problem_id": null,
  "created_at": "..."
}
```

Detail data:

```json
{
  "order": {
    "order_id": 1, "status": "confirmed", "payment_status": "under_review",
    "payment_method": "bank_transfer", "subtotal": 90.0, "discount": 0.0,
    "total": 90.0, "delivery_fee": 10.0, "grand_total": 100.0,
    "patient": { "id": 2, "name": "...", "phone": "...", "email": "..." },
    "created_at": "..."
  },
  "payment": { "can_approve": true, "can_reject": true, "latest_submission_id": 4 },
  "submissions": [],
  "problems": [],
  "banks": []
}
```

- Approve requires no open problem and the latest submission to be
  `under_review`; sets it to `approved`, order `payment_status=paid`, and stamps
  reviewer/time. An open problem makes `can_approve`/`can_reject` false and the
  action returns `422` until the problem is resolved.
- Reject requires no open problem, the latest submission to be `under_review`,
  and a non-empty reason; sets it to `rejected`, order
  `payment_status=rejected`.
- Approve is idempotent once paid; reject on a paid order returns `422`.
- Both actions are transactional and lock the order/submission.

### Problems

- `GET /api/admin/payment-problems?status=&page=&per_page=`
- `POST /api/admin/payment-problems/{paymentProblem}/resolve` body
  `{ admin_note: string required }`

Resolving an open problem restores the order payment status derived from the
latest submission (`under_review`, `rejected`, or `pending`). The derivation
also maps `approved` to `paid` defensively, though the normal flow cannot reach
that state because review is blocked while a problem is open. If the order was
cancelled in the meantime, resolving still closes the problem but leaves the
payment fields untouched.

## Submission payload

```json
{
  "id": 4,
  "order_id": 1,
  "bank_id": 2,
  "bank_name": "...",
  "account_holder": "...",
  "account_number": "...",
  "iban": "...",
  "amount": 100.0,
  "sender_name": "...",
  "transfer_reference": "...",
  "transferred_at": "2026-10-21",
  "receipt_original_name": "receipt.jpg",
  "receipt_mime": "image/jpeg",
  "receipt_size": 12345,
  "status": "under_review",
  "review_note": null,
  "rejection_reason": null,
  "reviewed_at": null,
  "created_at": "..."
}
```

## Security and integrity

- Bank account data is read-only for patients; only admins write banks.
- Receipt files are never public; download requires ownership or admin role.
- Cross-patient order/submission access returns 404.
- Validation is server-side; uploaded MIME/extension/size are enforced.
- Bank active-count writes use a shared cache lock plus a DB transaction.
- All monetary values come from the server; the declared transfer amount is
  informational and never auto-confirms payment.
- Order payment status is the source of truth; sub-order payment statuses are
  mirrored for display only.
