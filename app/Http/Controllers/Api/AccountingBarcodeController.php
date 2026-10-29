<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineBarcode;
use App\Models\PharmacyMedicine;
use App\Services\Enrichment\BarcodeNormalizer;
use App\Services\PharmacyContext;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * حلّ باركود **داخل مخزون الصيدلية المصادَق عليها** — نقطة الحقيقة الوحيدة.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * لماذا هذا المسار موجود أصلًا؟
 *   الويب (Blade) كان يطابق الباركود محليًا: يجلب صفحة مخزون (حد 100 صف)
 *   ثم يبحث داخل المصفوفة عن `medicine.local_medicine_id`. لصيدلية كبيرة
 *   قد يكون الصنف في صفحة ثانية ⇒ «الباركود غير موجود» كذبًا.
 *   هذا المسار يبحث في **قاعدة البيانات** مباشرة، فيرى كل المخزون.
 *
 * لماذا GET وليس POST؟
 *   العميل لا يُرسل سعرًا ولا كمية. يُرسل الباركود فقط، والخادم يُرجع
 *   السعر والكمية والتوفر **من المخزون** — فلا يمكن للعميل تزويرها.
 *
 * لماذا 200 مع `status` بدل 404؟
 *   لأن هنا خمس نتائج أعمال حقيقية لا خطأ واحد:
 *     unknown        الباركود غير مسجَّل في الكتالوج
 *     not_in_stock   الدواء معروف لكن صيدليتك لا تبيعه
 *     out_of_stock   صيدليتك تبيعه لكن الكمية صفر / غير متاح
 *     ambiguous      أكثر من سطر مخزون يطابق نفس الدواء (خلل بيانات)
 *     found          جاهز للبيع
 *   تحويل `not_in_stock` إلى 404 يُخفي الفرق عن `unknown`، وهو فرق يهم
 *   الكاشير (يطلب من المورد ≠ يبحث عن دواء آخر).
 */
final class AccountingBarcodeController extends Controller
{
    public const STATUS_FOUND = 'found';
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';
    public const STATUS_NOT_IN_STOCK = 'not_in_stock';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    /**
     * GET /api/pharmacy/accounting/barcode/{barcode}
     */
    public function show(Request $request, string $barcode): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        // نُعيد استخدام نفس خدمة `/api/medicines/barcode` (BarcodeNormalizer)
        // بدل تكرار منطق التطبيع. تُرجع null لما لا يمكن نمنمته
        // (فراغ / غير رقمي / طول غير مدعوم 8·12·13·14). لا قواعد تطبيع جديدة.
        $normalized = BarcodeNormalizer::normalize($barcode);
        if ($normalized === null) {
            return response()->json([
                'success' => false,
                'message' => 'صيغة الباركود غير صحيحة',
                'errors' => ['barcode' => ['صيغة الباركود غير صحيحة']],
            ], 422);
        }

        // المطبَّع للبحث في عمود `barcode` (الفريد)، والخام للتعافي من
        // اختلاف التنسيق في `barcode_raw`.
        $code = $normalized['barcode'];
        $rawCode = trim($barcode);

        $record = $this->findBarcodeRecord($code, $rawCode);

        if ($record === null) {
            return $this->respond(self::STATUS_UNKNOWN, $code, null, null, []);
        }

        $rows = $this->stockRows($pharmacy->id, $record);

        if ($rows->isEmpty()) {
            return $this->respond(self::STATUS_NOT_IN_STOCK, $code, $record, null, []);
        }

        // أكثر من سطر مخزون لنفس الدواء = خلل بيانات (سطران لنفس الدواء في
        // نفس الصيدلية). لا نخمّن: نُرجع المرشحين ليتخذ الكاشير/الأدمن قرارًا.
        if ($rows->count() > 1) {
            return $this->respond(self::STATUS_AMBIGUOUS, $code, $record, null, $rows->all());
        }

        /** @var PharmacyMedicine $row */
        $row = $rows->first();
        $inStock = $row->is_available && (int) $row->quantity > 0;

        return $this->respond(
            $inStock ? self::STATUS_FOUND : self::STATUS_OUT_OF_STOCK,
            $code,
            $record,
            $row,
            []
        );
    }

    /**
     * الباركود → سجل الكتالوج.
     *
     * محاولتان مرتّبتان: العمود المطبَّع أولًا (فريد عالميًا)، ثم الخام
     * (`barcode_raw`) كتعافٍ من اختلاف التنسيق. لا نستخدم `orWhere` واحدة
     * لأنها قد تختار صفًا آخر عند تعارض القيمتين.
     */
    private function findBarcodeRecord(string $code, ?string $rawCode = null): ?MedicineBarcode
    {
        $record = MedicineBarcode::with(['mohMedicine', 'localMedicine'])
            ->where('barcode', $code)
            ->first();

        if ($record !== null) {
            return $record;
        }

        $rawCode = ($rawCode !== null && $rawCode !== '') ? $rawCode : $code;

        return MedicineBarcode::with(['mohMedicine', 'localMedicine'])
            ->where('barcode_raw', $rawCode)
            ->orderByDesc('is_verified')
            ->orderByDesc('confidence')
            ->first();
    }

    /**
     * أسطر المخزون المطابقة — **مقيّدة بالصيدلية** (لا تسريب بين الصيدليات).
     *
     * ترتيب المطابقة مقصود:
     *   1. `local_medicine_id` موجود ⇒ المطابقة بـ`medicine_id` **فقط**.
     *      السبب: الباركود يشير إلى منتج محلي بعينه؛ منتج آخر يشترك في نفس
     *      دواء MOH ليس نفس العلبة. السقوط إلى MOH هنا يعرض صنفًا خاطئًا.
     *      وقيود `unique(pharmacy_id, medicine_id)` تضمن صفًا واحدًا كحد أقصى.
     *   2. `local_medicine_id` غائب (باركود MOH فقط) ⇒ المطابقة بـ
     *      `moh_medicine_id`. هنا قد تطابق عدة أصناف محلية — وهذا تعارض
     *      حقيقي يُبلَّغ عنه (`ambiguous`) بدل التخمين.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PharmacyMedicine>
     */
    private function stockRows(int $pharmacyId, MedicineBarcode $record)
    {
        $localId = $record->local_medicine_id;
        $mohId = $record->moh_medicine_id;

        $query = PharmacyMedicine::query()->where('pharmacy_id', $pharmacyId);

        if ($localId !== null) {
            $query->where('medicine_id', $localId);
        } elseif ($mohId !== null) {
            $query->where('moh_medicine_id', $mohId);
        } else {
            return PharmacyMedicine::query()->whereRaw('1 = 0')->get();
        }

        return $query
            ->with(['medicine', 'mohMedicine'])
            ->orderByDesc('is_available')
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->get();
    }

    /**
     * بناء الاستجابة الموحّدة لكل الحالات.
     *
     * @param  array<int, PharmacyMedicine>  $candidates
     */
    private function respond(
        string $status,
        string $code,
        ?MedicineBarcode $record,
        ?PharmacyMedicine $row,
        array $candidates
    ): JsonResponse {
        $medicine = $this->medicinePayload($record, $row);
        $quantity = $row !== null ? (int) $row->quantity : null;
        $available = $row !== null ? (bool) $row->is_available : null;

        return response()->json([
            'success' => true,
            'message' => $this->messageFor($status),
            'data' => [
                'status' => $status,
                'barcode' => $code,
                'medicine' => $medicine,
                // 🔑 المعرّف الذي يحتاجه POST /sales — من الخادم لا من العميل.
                'pharmacy_medicine_id' => $row?->id,
                'local_medicine_id' => $record?->local_medicine_id ?? $row?->medicine_id,
                'moh_medicine_id' => $record?->moh_medicine_id ?? $row?->moh_medicine_id,
                // السعر والكمية **من المخزون** — مصدر الحقيقة الوحيد.
                'price' => $row?->price !== null ? (float) $row->price : null,
                'quantity' => $quantity,
                'is_available' => $available,
                'is_out_of_stock' => $row !== null ? (! $available || (int) $quantity <= 0) : true,
                'is_low_stock' => $row !== null
                    ? ($available && (int) $quantity > 0 && (int) $quantity <= PharmacyMedicine::LOW_STOCK_THRESHOLD)
                    : false,
                'candidates' => array_map(fn (PharmacyMedicine $c) => [
                    'pharmacy_medicine_id' => $c->id,
                    'medicine_id' => $c->medicine_id,
                    'moh_medicine_id' => $c->moh_medicine_id,
                    'name' => $this->nameOf($c),
                    'price' => (float) $c->price,
                    'quantity' => (int) $c->quantity,
                    'is_available' => (bool) $c->is_available,
                ], $candidates),
            ],
        ]);
    }

    /**
     * بيانات الدواء المعروضة — من سطر المخزون أولًا (الأدق)، ثم الكتالوج.
     *
     * @return array<string, mixed>|null
     */
    private function medicinePayload(?MedicineBarcode $record, ?PharmacyMedicine $row): ?array
    {
        $local = $row?->medicine ?? $record?->localMedicine;
        $moh = $row?->mohMedicine ?? $record?->mohMedicine;

        if ($local === null && $moh === null) {
            return null;
        }

        return [
            'id' => $local?->id ?? $row?->medicine_id,
            'trade_name' => $local?->trade_name ?? $moh?->trade_name,
            'trade_name_ar' => $local?->trade_name_ar ?? null,
            'active_ingredient' => $local?->active_ingredient ?? $moh?->generic_name,
            'manufacturer' => $moh?->manufacturer ?? $moh?->company,
            'dosage_form' => $moh?->dosage_form,
            'packaging' => $moh?->packaging,
            'official_price' => $moh?->official_price !== null ? (float) $moh->official_price : null,
            'image_url' => Image::url($local?->image),
        ];
    }

    private function nameOf(PharmacyMedicine $row): ?string
    {
        return $row->medicine?->trade_name
            ?? $row->mohMedicine?->trade_name;
    }

    private function messageFor(string $status): string
    {
        return match ($status) {
            self::STATUS_FOUND => 'تم العثور على الصنف في مخزون الصيدلية',
            self::STATUS_OUT_OF_STOCK => 'الدواء موجود في مخزون الصيدلية لكنه غير متوفر حاليًا',
            self::STATUS_NOT_IN_STOCK => 'الدواء معروف لكنه غير موجود في مخزون هذه الصيدلية',
            self::STATUS_AMBIGUOUS => 'أكثر من صنف في المخزون يطابق هذا الباركود — راجع بيانات المخزون',
            default => 'الباركود غير مسجَّل في كتالوج الأدوية',
        };
    }
}
