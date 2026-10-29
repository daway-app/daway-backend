<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineBarcode;
use App\Models\PharmacyMedicine;
use App\Services\PharmacyContext;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * بحث أصناف مخزون الصيدلية لنقطة البيع (POS).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * لماذا مسار جديد بدل `GET /api/pharmacy/medicines/search`؟
 *   ذلك المسار يبحث في **الكتالوج** (medicines + moh_catalog) ويُستخدم
 *   لإضافة دواء إلى المخزون. لا يُرجع سعرًا ولا كمية ولا
 *   `pharmacy_medicine_id` — فلا يصلح للبيع. إعادة استخدامه كانت ستكسر
 *   شاشة «إضافة دواء» أو تُحمّل الاستعلام ما لا يحتاجه.
 *
 * لماذا لا يُحمَّل كتالوج MOH؟
 *   البيع من المخزون فقط. البحث يجري على `pharmacy_medicines` مرتبطًا
 *   بـ`medicines` (و`moh_medicines` عند الحاجة للاسم العلمي) — بلا أي
 *   استعلام على الكتالوج الكامل.
 *
 * العزل: كل استعلام يبدأ بـ`where('pharmacy_id', $pharmacy->id)` — لا
 * يمكن أن يظهر صنف صيدلية أخرى ولو تطابق الاسم.
 */
final class AccountingInventorySearchController extends Controller
{
    /** الحد الأقصى للصفحة — مطابق لبقية مسارات المحاسبة. */
    private const MAX_PER_PAGE = 50;

    /**
     * GET /api/pharmacy/accounting/inventory/search
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if ($pharmacy === null) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:32',
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_PER_PAGE,
            'page' => 'nullable|integer|min:1',
            'only_available' => 'nullable|boolean',
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $barcode = trim((string) ($validated['barcode'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        // بلا مدخل صالح: قائمة فارغة (لا نُفرّغ المخزون كله بلا سبب).
        if ($q === '' && $barcode === '') {
            return $this->emptyResponse();
        }

        // نصّ من حرف واحد لا يُبحث به (نفس سياسة الكتالوج) — إلا لو كان
        // هناك باركود، فيبقى البحث صالحًا عبر الباركود.
        if ($barcode === '' && mb_strlen($q) < 2) {
            return $this->emptyResponse();
        }

        $query = PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->with(['medicine', 'mohMedicine']);

        if ($barcode !== '') {
            $record = $this->findBarcode($barcode);

            if ($record === null) {
                return $this->emptyResponse();
            }

            $query->where(function ($w) use ($record) {
                if ($record->local_medicine_id !== null) {
                    $w->orWhere('medicine_id', $record->local_medicine_id);
                }
                if ($record->moh_medicine_id !== null) {
                    $w->orWhere('moh_medicine_id', $record->moh_medicine_id);
                }
            });
        }

        if ($q !== '') {
            $like = '%'.$q.'%';

            $query->where(function ($w) use ($like) {
                // الاسم التجاري/العربي/المادة الفعالة من الكتالوج المحلي،
                // والاسم العلمي/الشركة من كتالوج MOH عند الربط.
                $w->whereHas('medicine', function ($m) use ($like) {
                    $m->where('trade_name', 'like', $like)
                        ->orWhere('trade_name_ar', 'like', $like)
                        ->orWhere('active_ingredient', 'like', $like);
                })->orWhereHas('mohMedicine', function ($m) use ($like) {
                    $m->where('trade_name', 'like', $like)
                        ->orWhere('generic_name', 'like', $like);
                });
            });
        }

        if (! empty($validated['only_available'])) {
            $query->where('is_available', true)->where('quantity', '>', 0);
        }

        /** @var LengthAwarePaginator $rows */
        $rows = $query
            ->orderByDesc('is_available')
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->paginate($perPage);

        $barcodeMap = $this->barcodeMapFor($rows->pluck('medicine_id')->filter()->all());

        return response()->json([
            'success' => true,
            'message' => 'تم البحث في مخزون الصيدلية',
            'data' => collect($rows->items())
                ->map(fn (PharmacyMedicine $row) => $this->present($row, $barcodeMap))
                ->all(),
            'pagination' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * خريطة `medicine_id => barcode` باستعلام واحد.
     *
     * الغرض منع N+1: عرض الباركود لكل صف عبر استعلام لكل صف كان سيعني
     * 50 استعلامًا لصفحة واحدة. هنا استعلام واحد لكل الصفحة.
     *
     * @param  array<int, int>  $medicineIds
     * @return array<int, string>
     */
    private function barcodeMapFor(array $medicineIds): array
    {
        if ($medicineIds === []) {
            return [];
        }

        return MedicineBarcode::query()
            ->whereIn('local_medicine_id', $medicineIds)
            ->orderByDesc('is_verified')
            ->get(['local_medicine_id', 'barcode'])
            ->groupBy('local_medicine_id')
            ->map(fn ($group) => $group->first()->barcode)
            ->all();
    }

    private function findBarcode(string $barcode): ?MedicineBarcode
    {
        return MedicineBarcode::query()
            ->where('barcode', $barcode)
            ->orWhere('barcode_raw', $barcode)
            ->orderByDesc('is_verified')
            ->orderByDesc('confidence')
            ->first();
    }

    /**
     * @param  array<int, string>  $barcodeMap
     */
    private function present(PharmacyMedicine $row, array $barcodeMap): array
    {
        $quantity = (int) $row->quantity;
        $available = (bool) $row->is_available;

        return [
            'pharmacy_medicine_id' => $row->id,
            'medicine_id' => $row->medicine_id,
            'moh_medicine_id' => $row->moh_medicine_id,
            'name' => $row->medicine?->trade_name ?? $row->mohMedicine?->trade_name,
            'name_ar' => $row->medicine?->trade_name_ar,
            'active_ingredient' => $row->medicine?->active_ingredient ?? $row->mohMedicine?->generic_name,
            'barcode' => $barcodeMap[$row->medicine_id] ?? null,
            'price' => (float) $row->price,
            'quantity' => $quantity,
            'is_available' => $available,
            'is_out_of_stock' => ! $available || $quantity <= 0,
            'is_low_stock' => $available && $quantity > 0 && $quantity <= PharmacyMedicine::LOW_STOCK_THRESHOLD,
            'image_url' => Image::url($row->medicine?->image),
        ];
    }

    private function emptyResponse(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'تم البحث في مخزون الصيدلية',
            'data' => [],
            'pagination' => [
                'total' => 0,
                'per_page' => 20,
                'current_page' => 1,
                'last_page' => 1,
            ],
        ]);
    }
}
