<?php

namespace App\Services\Ai;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Support\MedicineNameMapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * يحوّل اسم الدواء (من الـ AI أو الـ OCR) إلى نتائج حقيقية من قاعدة البيانات:
 * مرشحين من الكتالوجين + الصيدليات القريبة المتوفرة + البدائل.
 *
 * يدعم البحث بالعربي: أسماء الكتالوج إنجليزية، لكن ملف chatbot_medicines.json
 * يحتوي اسم عربي (تحويل صوتي) وaliases لكل دواء — نستخدمه لترجمة الاستعلام العربي
 * إلى معرفات moh_product_id ثم نجلبها من قاعدة البيانات.
 */
final class MedicineResolver
{
    private ?string $mappingPath = null;

    /** مسار ملف الـ mapping (قابل للتبديل في الاختبارات) */
    public function setMappingPath(?string $path): static
    {
        $this->mappingPath = $path;

        return $this;
    }

    /**
     * يبحث عن الدواء في الكتالوج المحلي وكتالوج وزارة الصحة،
     * ويضيف نتائج الـ mapping العربي إن وُجدت.
     *
     * @return array{local: Collection, moh: Collection}
     */
    public function resolveCandidates(?string $drugName): array
    {
        $name = trim((string) $drugName);

        if (mb_strlen($name) < 2) {
            return ['local' => collect(), 'moh' => collect()];
        }

        $local = Medicine::query()
            ->where(function ($q) use ($name) {
                $q->where('trade_name', 'like', "%{$name}%")
                    ->orWhere('active_ingredient', 'like', "%{$name}%");
            })
            ->orderBy('trade_name')
            ->limit(10)
            ->get(['id', 'trade_name', 'active_ingredient', 'is_available']);

        $moh = MohMedicine::query()
            ->where(function ($q) use ($name) {
                $q->where('trade_name', 'like', "%{$name}%")
                    ->orWhere('generic_name', 'like', "%{$name}%")
                    ->orWhere('manufacturer', 'like', "%{$name}%");
            })
            ->limit(20)
            ->get(['id', 'trade_name', 'generic_name', 'manufacturer', 'official_price', 'availability']);

        // دمج نتائج الـ mapping العربي (استعلام عربي ↔ اسم إنجليزي بالكتالوج)
        $moh = $this->mergeMappingHits($moh, $name);

        return ['local' => $local, 'moh' => $moh];
    }

    /**
     * يبحث في ملف chatbot_medicines.json عن سجلات تطابق الاستعلام (عربي أو إنجليزي)
     * عبر aliases كل دواء. قراءة تدفقية سطراً بسطر لتوفير الذاكرة.
     *
     * ترتيب المطابقة: aliases → name_ar → name_en → generic_name.
     * (name_ar وname_en lowercase وgeneric_name كلهم أعضاء ضمن aliases،
     * لذا مسح الـ aliases يغطي الترتيب كاملاً — أول alias مطابق يفوز بالسجل).
     *
     * تطبيع الاستعلام: أ/إ/آ → ا، ؤ → و، ئ/ى → ي، إزالة التشكيل، توحيد المسافات، lowercase.
     * الأسماء المطبّقة بنفس القواعد حتى يطابق "بنآدول" alias "بانادول".
     *
     * مفتاح الربط مع كتالوج الوزارة: moh_drug_id أساسياً،
     * وmoh_product_id احتياطاً (أغلب سجلات المنتجات بالكتالوج الفعلي لا تحمل moh_drug_id).
     *
     * @return array<int, array{moh_product_id:?int, moh_drug_id:?int, name_en:string, name_ar:?string}>
     */
    public function lookupMapping(string $query, int $limit = 10): array
    {
        $needle = self::normalizeArabic($query);
        $needleSkel = self::skeletonOf($needle);

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $path = $this->mappingPath ?? base_path('database/data/chatbot_medicines.json');
        $cacheKey = 'ai_mapping_lookup|'.md5($needle.'|'.$limit.'|'.$path);

        return Cache::remember($cacheKey, 600, function () use ($needle, $needleSkel, $limit, $path) {
            if (! is_file($path)) {
                return [];
            }

            $hits = [];

            try {
                $handle = fopen($path, 'r');

                if ($handle === false) {
                    return [];
                }

                while (($line = fgets($handle)) !== false && count($hits) < $limit) {
                    $line = trim($line, " \t\r\n,");

                    if ($line === '' || $line === '[' || $line === ']') {
                        continue;
                    }

                    $record = json_decode($line, true);

                    if (! is_array($record)) {
                        continue;
                    }

                    // الملف عادة سطر لكل سجل (JSONL)، لكن نتقبّل أيضاً مصفوفة كاملة في سطر واحد
                    if (array_is_list($record)) {
                        foreach ($record as $subRecord) {
                            if (is_array($subRecord)) {
                                $this->collectMappingHit($subRecord, $needle, $needleSkel, $hits, $limit);
                            }

                            if (count($hits) >= $limit) {
                                break 2;
                            }
                        }

                        continue;
                    }

                    $this->collectMappingHit($record, $needle, $needleSkel, $hits, $limit);
                }

                fclose($handle);
            } catch (\Throwable $e) {
                Log::warning('mapping lookup failed', ['error' => $e->getMessage()]);

                return [];
            }

            return $hits;
        });
    }

    /**
     * يفحص سجل mapping واحداً ويضيفه للنتائج إن طابق الاستعلام عبر aliases.
     * مرحلتان:
     *  1) مطابقة مباشرة بعد التطبيع (همزات/تشكيل/مسافات).
     *  2) مطابقة هيكلية احتياطية تحذف الألف غير الأولى من كل كلمة —
     *     توحّد الكتابات مثل "بنادول" ↔ "بانادول" و"اسبرين" ↔ "اسبيرين".
     * الـ alias يُطبَّع بنفس قواعد الـ needle، مع مسار سريع للأسماء الخالية
     * من الحروف القابلة للتطبيع.
     */
    private function collectMappingHit(array $record, string $needle, string $needleSkel, array &$hits, int $limit): void
    {
        if (count($hits) >= $limit) {
            return;
        }

        $normalize = static fn (string $a): string => strpbrk($a, 'أإآؤئى') === false
            ? mb_strtolower($a)
            : self::normalizeArabic($a);

        $aliases = array_values(array_filter($record['aliases'] ?? [], 'is_string'));

        // المرحلة 1: مطابقة مباشرة
        $matched = false;
        foreach ($aliases as $alias) {
            if (str_contains($normalize($alias), $needle)) {
                $hits[] = $this->mappingHitPayload($record);
                $matched = true;
                break;
            }
        }

        if ($matched || count($hits) >= $limit) {
            return;
        }

        // المرحلة 2: الهيكل الصوتي (يفيد فقط إذا حذف الهيكل ألفاً من الـ needle)
        if ($needleSkel !== $needle) {
            foreach ($aliases as $alias) {
                $normalized = $normalize($alias);

                if (! str_contains($normalized, 'ا')) {
                    continue;
                }

                if (str_contains(self::skeletonOf($normalized), $needleSkel)) {
                    $hits[] = $this->mappingHitPayload($record);
                    break;
                }
            }
        }
    }

    private function mappingHitPayload(array $record): array
    {
        return [
            'moh_product_id' => isset($record['moh_product_id']) ? (int) $record['moh_product_id'] : null,
            'moh_drug_id' => isset($record['moh_drug_id']) ? (int) $record['moh_drug_id'] : null,
            'name_en' => (string) ($record['name_en'] ?? ''),
            'name_ar' => isset($record['name_ar']) ? (string) $record['name_ar'] : null,
        ];
    }

    /**
     * تطبيع الاستعلام العربي للمطابقة:
     * أ/إ/آ → ا، ؤ → و، ئ/ى → ي + إزالة التشكيل + توحيد المسافات + lowercase.
     */
    private static function normalizeArabic(string $s): string
    {
        $s = MedicineNameMapper::clean($s);
        $s = strtr($s, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
        ]);

        return mb_strtolower($s);
    }

    /**
     * الهيكل الصوتي: يحذف كل الألف من النص.
     * "بانادول" و"بنادول" و"أبنآدول" كلاهما → "بندول" — يوحّد كتابات المستخدم.
     * يُطبق على الطرفين (needle والـ alias) كمطابقة احتياطية بعد فشل المطابقة المباشرة.
     */
    private static function skeletonOf(string $s): string
    {
        return str_replace('ا', '', $s);
    }

    /** يدمج سجلات كتالوج وزارة الصحة المطابقة عبر الـ mapping مع نتائج LIKE العادية */
    private function mergeMappingHits(Collection $moh, string $name): Collection
    {
        $hits = $this->lookupMapping($name);

        if ($hits === []) {
            return $moh;
        }

        $productIds = array_values(array_filter(array_column($hits, 'moh_product_id')));
        $drugIds = array_values(array_filter(array_column($hits, 'moh_drug_id')));

        if ($productIds === [] && $drugIds === []) {
            return $moh;
        }

        $extra = MohMedicine::query()
            ->where(function ($q) use ($productIds, $drugIds) {
                if ($productIds !== []) {
                    $q->orWhereIn('moh_product_id', $productIds);
                }
                if ($drugIds !== []) {
                    $q->orWhereIn('moh_drug_id', $drugIds);
                }
            })
            ->limit(20)
            ->get(['id', 'trade_name', 'generic_name', 'manufacturer', 'official_price', 'availability']);

        // fallback إضافي: الاستعلام العربي طابق mapping لكن المعرفات ما وجدت بالكتالوج —
        // نستخدم الاسم الإنجليزي من الـ mapping (أول كلمة أساسية) لمطابقة trade_name
        foreach ($hits as $hit) {
            $base = MedicineNameMapper::stripDosage((string) ($hit['name_en'] ?? ''));
            $first = $base === '' ? '' : explode(' ', $base)[0];

            if (mb_strlen($first) < 3) {
                continue;
            }

            $nameRows = MohMedicine::query()
                ->where('trade_name', 'like', "%{$first}%")
                ->limit(10)
                ->get(['id', 'trade_name', 'generic_name', 'manufacturer', 'official_price', 'availability']);

            $extra = $extra->merge($nameRows);
        }

        return $moh->merge($extra)->unique('id')->values();
    }

    /**
     * الصيدليات التي توفّر الدواء (بالاسم أو المعرّف)، مرتبة بالأقرب ثم الأرخص.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pharmaciesFor(
        ?string $drugName = null,
        ?int $medicineId = null,
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusKm = 15,
        int $limit = 20,
    ): array {
        $query = PharmacyMedicine::query()
            ->with('pharmacy:id,pharmacy_name,address,region,latitude,longitude,phone_number,is_active')
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->whereHas('pharmacy', fn ($p) => $p->where('is_active', true));

        if ($medicineId !== null) {
            $query->where('medicine_id', $medicineId);
        } else {
            $name = trim((string) $drugName);
            if (mb_strlen($name) < 2) {
                return [];
            }

            $query->whereHas('medicine', function ($m) use ($name) {
                $m->where('trade_name', 'like', "%{$name}%")
                    ->orWhere('active_ingredient', 'like', "%{$name}%");
            });
        }

        $rows = $query->limit(max($limit * 4, 40))->get();

        $results = [];
        foreach ($rows as $row) {
            $pharmacy = $row->pharmacy;

            if (! $pharmacy) {
                continue;
            }

            $distance = null;
            if ($latitude !== null && $longitude !== null
                && $pharmacy->latitude !== null && $pharmacy->longitude !== null) {
                $distance = $this->haversineKm(
                    $latitude,
                    $longitude,
                    (float) $pharmacy->latitude,
                    (float) $pharmacy->longitude,
                );

                if ($distance > $radiusKm) {
                    continue;
                }
            }

            $results[] = [
                'pharmacy_id' => $pharmacy->id,
                'pharmacy_name' => $pharmacy->pharmacy_name,
                'address' => $pharmacy->address,
                'region' => $pharmacy->region,
                'phone_number' => $pharmacy->phone_number,
                'latitude' => $pharmacy->latitude !== null ? (float) $pharmacy->latitude : null,
                'longitude' => $pharmacy->longitude !== null ? (float) $pharmacy->longitude : null,
                'medicine_id' => $row->medicine_id,
                'price' => (float) $row->price,
                'quantity' => (int) $row->quantity,
                'distance_km' => $distance !== null ? round($distance, 2) : null,
            ];

            if (count($results) >= $limit * 2) {
                break;
            }
        }

        usort($results, function ($a, $b) {
            $da = $a['distance_km'] ?? PHP_FLOAT_MAX;
            $db = $b['distance_km'] ?? PHP_FLOAT_MAX;

            // مسافات متعادلة ضمن متر واحد → السعر يحسم
            if (abs($da - $db) < 0.001) {
                return $a['price'] <=> $b['price'];
            }

            return $da <=> $db;
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * بدائل بنفس المادة الفعّالة لدواء محلي معيّن.
     */
    public function alternatives(int $medicineId, int $limit = 5): array
    {
        $medicine = Medicine::find($medicineId);

        if (! $medicine || ! $medicine->active_ingredient) {
            return [];
        }

        return Medicine::alternativesByActiveIngredient($medicine->active_ingredient, $medicineId)
            ->take($limit)
            ->map(fn (Medicine $m) => [
                'id' => $m->id,
                'trade_name' => $m->trade_name,
                'active_ingredient' => $m->active_ingredient,
                'is_available' => (bool) $m->is_available,
            ])
            ->values()
            ->all();
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }
}
