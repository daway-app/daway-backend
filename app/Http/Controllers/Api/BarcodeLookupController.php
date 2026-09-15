<?php

namespace App\Http\Controllers\Api;

use App\Models\MedicineBarcode;
use App\Models\MedicineImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * باركود البحث (GET /api/medicines/barcode/{barcode})
 * read-only — يقرأ من قاعدة Daway فقط (لا يضرب مزوّد خارجي في كل طلب)،
 * ولا يعرض credentials ولا internals من الprovider (المصدر سميّاً فقط).
 */
final class BarcodeLookupController extends Controller
{
    /**
     * The throttle injected via route middleware ('throttle:60,1').
     * عمومياً (بدون auth) — يريد الطرف المحمي والفلتر أخرى.
     */
    public function show(Request $request, string $barcode): JsonResponse
    {
        $normalized = \App\Services\Enrichment\BarcodeNormalizer::normalize($barcode);
        if ($normalized === null) {
            return response()->json([
                'success' => false,
                'message' => 'تنسيق باركود غير معروف (الأنواع المدعومة: EAN13/EAN8/UPCA/GTIN14)',
            ], 422);
        }

        $record = \App\Models\MedicineBarcode::query()
            ->with(['mohMedicine', 'localMedicine'])
            ->where('barcode', $normalized['barcode'])
            ->first();

        if ($record === null) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم العثور على دواء بهذا الباركود',
            ], 404);
        }

        $moh = $record->mohMedicine;
        $local = $record->localMedicine;

        $image = \App\Models\MedicineImage::query()
            ->where('moh_medicine_id', $moh->id)
            ->orderByDesc('is_verified')
            ->orderBy('id')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الدواء بالباركود بنجاح',
            'data' => [
                'medicine' => [
                    'id' => $moh->id,
                    'name_en' => $moh->trade_name,
                    'name_ar' => $moh->generic_name,
                    'active_ingredient' => $moh->generic_name,
                    'manufacturer' => $moh->manufacturer ?: $moh->company,
                    'dosage_form' => $moh->dosage_form,
                    'pack_size' => $moh->packaging,
                    'official_price' => $moh->official_price !== null ? (float) $moh->official_price : null,
                    'moh_product_id' => $moh->moh_product_id,
                    'moh_drug_id' => $moh->moh_drug_id,
                    'local_medicine_id' => $local?->id,
                ],
                'barcode' => [
                    'value' => $record->barcode,
                    'raw' => $record->barcode_raw ?? $record->barcode,
                    'type' => $record->barcode_type,
                    'verified' => (bool) $record->is_verified,
                    'source' => $record->source,
                ],
                'image' => $this->image($moh->id),
            ],
        ]);
    }

    /** صورة واحدة (أول verified ثم الأول) — لا source internals، لا base64. */
    private function image(int $mohMedicineId): ?array
    {
        $img = \App\Models\MedicineImage::query()
            ->where('moh_medicine_id', $mohMedicineId)
            ->orderByDesc('is_verified')->orderBy('id')->first();
        if (! $img) {
            return null;
        }

        return [
            'url' => $img->image_url,
            'type' => $img->image_type,
            'source' => $img->source,
            'verified' => (bool) $img->is_verified,
        ];
    }
}
