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
     * @return array<int, array{moh_product_id:?int, moh_drug_id:?int, name_en:string, name_ar:?string}>
     */
    public function lookupMapping(string $query, int $limit = 10): array
    {
        $needle = mb_strtolower(MedicineNameMapper::clean($query));

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $path = $this->mappingPath ?? base_path('database/data/chatbot_medicines.json');
        $cacheKey = 'ai_mapping_lookup|'.md5($needle.'|'.$limit.'|'.$path);

        return Cache::remember($cacheKey, 600, function () use ($needle, $limit, $path) {
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

                    foreach (($record['aliases'] ?? []) as $alias) {
                        if (! is_string($alias)) {
                            continue;
                        }

                        if (str_contains(mb_strtolower($alias), $needle)) {
                            $hits[] = [
                                'moh_product_id' => isset($record['moh_product_id']) ? (int) $record['moh_product_id'] : null,
                                'moh_drug_id' => isset($record['moh_drug_id']) ? (int) $record['moh_drug_id'] : null,
                                'name_en' => (string) ($record['name_en'] ?? ''),
                                'name_ar' => isset($record['name_ar']) ? (string) $record['name_ar'] : null,
                            ];
                            break;
                        }
                    }
                }

                fclose($handle);
            } catch (\Throwable $e) {
                Log::warning('mapping lookup failed', ['error' => $e->getMessage()]);

                return [];
            }

            return $hits;
        });
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
