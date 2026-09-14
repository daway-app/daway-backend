<?php

namespace App\Http\Controllers\Api;

use App\Exports\InventoryErrorsExport;
use App\Exports\InventoryTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryImport;
use App\Services\InventoryImport\InventoryImportService;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * واجهة الـ API للاستيراد الجماعي — للموبايل (Flutter).
 *
 * نفس محرّك الويب بالضبط (InventoryImportService): لا منطق مكرّر، ولا
 * اختلاف في القواعد بين القناتين. الفرق في شكل الرد فقط.
 *
 * ملاحظة: لم نعدّل أي نقطة API قائمة — كل ما هنا إضافي بحت.
 */
class PharmacyInventoryImportController extends Controller
{
    public function __construct(private readonly InventoryImportService $service)
    {
    }

    /** تنزيل القالب: فارغ أو مبني على المخزون الحالي. */
    public function template(Request $request): BinaryFileResponse|JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $mode = (string) $request->query('mode', InventoryTemplateExport::MODE_EMPTY);

        $export = $mode === InventoryTemplateExport::MODE_CURRENT
            ? InventoryTemplateExport::forPharmacy($pharmacy)
            : new InventoryTemplateExport(InventoryTemplateExport::MODE_EMPTY);

        $suffix = $mode === InventoryTemplateExport::MODE_CURRENT ? 'current' : 'template';

        return Excel::download($export, "inventory-{$suffix}-".now()->format('Ymd').'.xlsx');
    }

    /** رفع الملف + معاينة (dry-run) — لا كتابة على المخزون. */
    public function preview(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        try {
            $import = $this->service->createFromUpload($request->file('file'), $request->user(), $pharmacy);
            $import = $this->service->preview($import, $pharmacy);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('pharmacy_import.summary_title'),
            'data' => $this->payload($import),
        ]);
    }

    /** حالة جلسة الاستيراد — للمزامنة مع الموبايل. */
    public function show(Request $request, string $import): JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $model = $this->findImport($request, $import, $pharmacy);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'جلسة الاستيراد غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $this->payload($model, includeRows: true),
        ]);
    }

    /** حفظ قرارات الصيدلي — كل قرار يُعاد التحقق منه على الخادم. */
    public function decide(Request $request, string $import): JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $model = $this->findImport($request, $import, $pharmacy);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'جلسة الاستيراد غير موجودة'], 404);
        }

        $validated = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*.row' => ['required', 'integer', 'min:1'],
            'rows.*.action' => ['required', 'string'],
            'rows.*.medicine_id' => ['nullable', 'integer'],
            'rows.*.moh_id' => ['nullable', 'integer'],
            'merges' => ['nullable', 'array'],
            'merges.*' => ['required', 'string'],
        ]);

        try {
            $model = $this->service->applyDecisions(
                $model,
                $pharmacy,
                (array) ($validated['rows'] ?? []),
                (array) ($validated['merges'] ?? []),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $this->payload($model),
        ]);
    }

    /** تنفيذ الاستيراد. */
    public function commit(Request $request, string $import): JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $model = $this->findImport($request, $import, $pharmacy);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'جلسة الاستيراد غير موجودة'], 404);
        }

        try {
            $summary = $this->service->commit($model, $pharmacy);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('pharmacy_import.done_title'),
            'data' => [
                'summary' => $summary,
                'import' => $this->payload($model->refresh()),
            ],
        ]);
    }

    /** تنزيل تقرير الأخطاء. */
    public function errors(Request $request, string $import): BinaryFileResponse|JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $model = $this->findImport($request, $import, $pharmacy);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'جلسة الاستيراد غير موجودة'], 404);
        }

        return Excel::download(
            InventoryErrorsExport::fromImport($model),
            'inventory-errors-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /** إلغاء الجلسة. */
    public function cancel(Request $request, string $import): JsonResponse
    {
        $pharmacy = $this->pharmacyOrFail($request);

        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $model = $this->findImport($request, $import, $pharmacy);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'جلسة الاستيراد غير موجودة'], 404);
        }

        $this->service->cancel($model);

        return response()->json(['success' => true, 'message' => __('pharmacy_import.cancel_import')]);
    }

    /*
    |--------------------------------------------------------------------------
    | مساعدات
    |--------------------------------------------------------------------------
    */

    /**
     * صيدلية المستخدم الحالي — أو رد 404 جاهز.
     * نُعيد JsonResponse بدل abort() للحفاظ على شكل الردود الحالي في هذا المتحكم.
     *
     * @return \App\Models\Pharmacy|JsonResponse
     */
    private function pharmacyOrFail(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);

        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        return $pharmacy;
    }

    /** جلب الجلسة بقيود الملكية الكاملة — لا وصول عابر بين الصيدليات. */
    private function findImport(Request $request, string $uuid, $pharmacy): ?InventoryImport
    {
        return InventoryImport::where('uuid', $uuid)
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /**
     * شكل بيانات الجلسة في الـ API.
     *
     * @return array<string, mixed>
     */
    private function payload(InventoryImport $import, bool $includeRows = false): array
    {
        $data = [
            'uuid' => $import->uuid,
            'status' => $import->status,
            'can_commit' => $import->canCommit(),
            'expires_at' => $import->expires_at?->toIso8601String(),
            'summary' => [
                'total' => (int) $import->total_rows,
                'matched' => (int) $import->matched_rows,
                'review' => (int) $import->review_rows,
                'unmatched' => (int) $import->unmatched_rows,
                'duplicate' => (int) $import->duplicate_rows,
                'error' => (int) $import->error_rows,
            ],
            'decisions' => $this->service->decisionSummary($import),
            'groups' => $import->duplicateGroups(),
            'merges' => $import->mergeDecisions(),
            'unknown_columns' => (array) ($import->rows_payload['unknown_columns'] ?? []),
        ];

        if ($import->isCommitted()) {
            $data['commit_summary'] = (array) ($import->commit_summary ?? []);
        }

        if ($includeRows) {
            $data['rows'] = $import->rows();
        }

        return $data;
    }
}
