<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Exports\InventoryErrorsExport;
use App\Exports\InventoryTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryImport;
use App\Models\Pharmacy;
use App\Services\InventoryImport\InventoryImportService;
use App\Support\InventoryImportThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * واجهة الويب للاستيراد الجماعي للمخزون.
 *
 * مبادئ ملزمة مطبَّقة هنا:
 *  - الصيدلية تُستنتج من المستخدم المسجَّل، ولا تُقبل من الطلب أبداً.
 *  - الجلسة تُجلب بـ uuid وتُفلتر بـ pharmacy_id — لا تعداد ولا وصول عابر.
 *  - كل قرار يمرّ عبر InventoryImportService الذي يعيد التحقق منه server-side.
 *  - لا كتابة على المخزون قبل commit.
 */
class PharmacyImportController extends Controller
{
    public function __construct(private readonly InventoryImportService $service)
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    /** صفحة البداية: تعليمات + رفع + آخر الجلسات. */
    public function index()
    {
        $pharmacy = $this->pharmacy();

        $recent = InventoryImport::where('pharmacy_id', $pharmacy->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('pharmacy.import.index', [
            'pharmacy' => $pharmacy,
            'recent' => $recent,
            'maxFileMb' => round(((int) config('inventory_import.max_file_kb', 5120)) / 1024, 1),
            'maxRows' => (int) config('inventory_import.max_rows', 5000),
            'columns' => InventoryTemplateExport::COLUMNS,
            // الحصة المتبقية — تُعرض للصيدلي وقايةً من مفاجأة 429.
            'rateLimit' => InventoryImportThrottle::status(Auth::user()),
        ]);
    }

    /** تنزيل القالب: فارغ أو مبني على المخزون الحالي. */
    public function template(Request $request): BinaryFileResponse
    {
        $pharmacy = $this->pharmacy();

        $mode = (string) $request->query('mode', InventoryTemplateExport::MODE_EMPTY);

        $export = $mode === InventoryTemplateExport::MODE_CURRENT
            ? InventoryTemplateExport::forPharmacy($pharmacy)
            : new InventoryTemplateExport(InventoryTemplateExport::MODE_EMPTY);

        $suffix = $mode === InventoryTemplateExport::MODE_CURRENT ? 'current' : 'template';

        return Excel::download($export, "inventory-{$suffix}-".now()->format('Ymd').'.xlsx');
    }

    /** رفع الملف + تحليل + معاينة، ثم تحويل لصفحة المراجعة. */
    public function preview(Request $request): RedirectResponse
    {
        $pharmacy = $this->pharmacy();

        $request->validate([
            'file' => ['required', 'file'],
        ], [
            'file.required' => __('pharmacy_import.error_upload_failed'),
        ]);

        try {
            $import = $this->service->createFromUpload($request->file('file'), $request->user(), $pharmacy);
            $import = $this->service->preview($import, $pharmacy);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('pharmacy.inventory.import.show', ['import' => $import->uuid]);
    }

    /** صفحة المراجعة: ملخّص + أسطر + مجموعات مكرّرة + تأكيد. */
    public function show(string $import)
    {
        $pharmacy = $this->pharmacy();
        $model = $this->findImport($import, $pharmacy);

        // جلسة نُفِّذت أو انتهت → صفحة النتيجة/الرسالة بدل جدول قرارات بلا معنى
        if (! $model->canCommit()) {
            return view('pharmacy.import.result', [
                'pharmacy' => $pharmacy,
                'import' => $model,
                'summary' => (array) ($model->commit_summary ?? []),
            ]);
        }

        return view('pharmacy.import.review', [
            'pharmacy' => $pharmacy,
            'import' => $model,
            'rows' => $model->rows(),
            'groups' => $model->duplicateGroups(),
            'merges' => $model->mergeDecisions(),
            'decisions' => $this->service->decisionSummary($model),
            'unknownColumns' => (array) ($model->rows_payload['unknown_columns'] ?? []),
            'rateLimit' => InventoryImportThrottle::status(Auth::user()),
        ]);
    }

    /**
     * حفظ قرارات الصيدلي — JSON، ويُعاد التحقق من كل قرار على الخادم.
     */
    public function decide(Request $request, string $import): JsonResponse
    {
        $pharmacy = $this->pharmacy();
        $model = $this->findImport($import, $pharmacy);

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
            ], 422);
        }

        return response()->json([
            'success' => true,
            'status' => $model->status,
            'ready' => $model->status === InventoryImport::STATUS_READY,
            'decisions' => $this->service->decisionSummary($model),
            'summary' => [
                'total' => (int) $model->total_rows,
                'matched' => (int) $model->matched_rows,
                'review' => (int) $model->review_rows,
                'unmatched' => (int) $model->unmatched_rows,
                'duplicate' => (int) $model->duplicate_rows,
                'error' => (int) $model->error_rows,
            ],
        ]);
    }

    /** تنفيذ الاستيراد فعلياً. */
    public function commit(string $import): RedirectResponse
    {
        $pharmacy = $this->pharmacy();
        $model = $this->findImport($import, $pharmacy);

        try {
            $this->service->commit($model, $pharmacy);
        } catch (ValidationException $e) {
            return redirect()
                ->route('pharmacy.inventory.import.show', ['import' => $model->uuid])
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('pharmacy.inventory.import.show', ['import' => $model->uuid])
            ->with('success', __('pharmacy_import.done_title'));
    }

    /** تنزيل تقرير الأخطاء والتحذيرات. */
    public function errors(string $import): BinaryFileResponse
    {
        $pharmacy = $this->pharmacy();
        $model = $this->findImport($import, $pharmacy);

        $name = 'inventory-errors-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(InventoryErrorsExport::fromImport($model), $name);
    }

    /** إلغاء الجلسة — بلا أي كتابة على المخزون. */
    public function cancel(string $import): RedirectResponse
    {
        $pharmacy = $this->pharmacy();
        $model = $this->findImport($import, $pharmacy);

        $this->service->cancel($model);

        return redirect()
            ->route('pharmacy.inventory.import.index')
            ->with('success', __('pharmacy_import.cancel_import'));
    }

    /*
    |--------------------------------------------------------------------------
    | مساعدات
    |--------------------------------------------------------------------------
    */

    /** الصيدلية الحالية — من المستخدم المسجَّل حصراً. */
    private function pharmacy(): Pharmacy
    {
        return Pharmacy::where('user_id', Auth::id())->firstOrFail();
    }

    /**
     * جلب جلسة الاستيراد بأمان: uuid + صاحبها + صيدليتها.
     * لا نستخدم المفتاح الرقمي في الروابط (تفادي التعداد)، ولا نتجاهل الملكية.
     */
    private function findImport(string $uuid, Pharmacy $pharmacy): InventoryImport
    {
        return InventoryImport::where('uuid', $uuid)
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }
}
