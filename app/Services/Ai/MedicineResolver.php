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
            return ['local' => collect(), 'moh' => collect(), 'search_keys' => []];
        }

        $hits = $this->lookupMapping($name);

        // الاستعلام العربي "اكمول" بيترجم عبر الـ mapping لاسم إنجليزي (ACAMOL) —
        // نستخدم الأسماء الإنجليزية كمفاتيح بحث للكتالوج المحلي والصيدليات.
        // الاسم الأصلي يبقى أول مفتاح — أدوية بالكتالوج المحلي قد تكون مسجلة بالعربي أصلاً
        $searchKeys = array_values(array_unique(array_merge(
            [$name],
            $this->searchKeysFromHits($hits)
        )));

        $local = Medicine::query()
            ->where(function ($q) use ($name, $searchKeys) {
                $this->applyNameKeys($q, array_merge([$name], $searchKeys));
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
        $moh = $this->mergeMappingHits($moh, $name, $hits);

        // الأدوية الحقيقية (قائمة الأسعار — لها مادة فعالة) أولاً، ثم قصّ الضجيج
        $moh = $moh
            ->sortByDesc(fn ($row) => $row->generic_name !== null ? 1 : 0)
            ->values()
            ->take(12);

        return ['local' => $local, 'moh' => $moh, 'search_keys' => $searchKeys];
    }

    /**
     * يبني مفاتيح بحث إنجليزية من نتائج الـ mapping:
     * الاسم الكامل + الاسم بدون جرعة + الكلمة الأولى (البراند).
     * مثال: "ACAMOL CAP 500MG" → ["acamol cap 500mg", "acamol cap", "acamol"].
     */
    private function searchKeysFromHits(array $hits): array
    {
        $keys = [];

        foreach ($hits as $hit) {
            $nameEn = trim((string) ($hit['name_en'] ?? ''));
            if ($nameEn === '') {
                continue;
            }

            $keys[] = mb_strtolower($nameEn);

            $base = mb_strtolower(MedicineNameMapper::stripDosage($nameEn));
            if ($base !== '' && ! in_array($base, $keys, true)) {
                $keys[] = $base;
            }

            $words = preg_split('/\s+/u', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words !== [] && mb_strlen($words[0]) >= 3 && ! in_array($words[0], $keys, true)) {
                $keys[] = $words[0];
            }
        }

        return array_slice(array_values(array_unique($keys)), 0, 8);
    }

    /** يطبّق قائمة مفاتيح بحث على trade_name/active_ingredient بـ OR */
    private function applyNameKeys($query, array $keys): void
    {
        $applied = false;
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if (mb_strlen($key) < 2) {
                continue;
            }

            $like = "%{$key}%";
            if ($applied) {
                $query->orWhere(function ($w) use ($like) {
                    $w->where('trade_name', 'like', $like)
                        ->orWhere('active_ingredient', 'like', $like);
                });
            } else {
                $query->where(function ($w) use ($like) {
                    $w->where('trade_name', 'like', $like)
                        ->orWhere('active_ingredient', 'like', $like);
                });
                $applied = true;
            }
        }

        if (! $applied) {
            $query->whereRaw('1 = 0');
        }
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
     * الملف لا يحتوي نسخاً ملزوقة (بدون مسافات) — تُبنى وقت المطابقة من الـ
     * aliases متعددة الكلمات حتى تضمن حدود الكلمات ولا تسبب تطابقات كاذبة.
     *
     * المراحل:
     *  1) مطابقة مباشرة بعد التطبيع (همزات/تشكيل/مسافات).
     *  2) استعلام ملزوق (بدون مسافات، ≥ 8 أحرف): نلصق الـ aliases متعددة الكلمات
     *     ونقارن الهياكل — "بنادولتابليت" ↔ لصق "بانادول تابليت".
     *  3) مطابقة هيكلية كلمة-بكلمة تحذف الألف — "بنادول" ↔ "بانادول".
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
        foreach ($aliases as $alias) {
            if (str_contains($normalize($alias), $needle)) {
                $hits[] = $this->mappingHitPayload($record);

                return;
            }
        }

        // المرحلة 2: استعلام ملزوق طويل — نلصق الـ aliases متعددة الكلمات ونقارن الهياكل
        $needleHasSpace = str_contains($needle, ' ');
        if (! $needleHasSpace && mb_strlen($needle) >= 8) {
            foreach ($aliases as $alias) {
                $normalized = $normalize($alias);

                if (! str_contains($normalized, ' ')) {
                    continue; // كلمة واحدة أصلاً — الالتحاق لا يغيرها
                }

                if (str_contains(skeletonOf(str_replace(' ', '', $normalized)), $needleSkel)) {
                    $hits[] = $this->mappingHitPayload($record);

                    return;
                }
            }
            // بلا return هنا — المرحلة 3 تبقى شبكة أمان للاستعلامات الملزوقة غير المطابقة
        }

        // المرحلة 3: الهيكل الصوتي — كلمة بكلمة، بحدود كلمات
        if ($needleSkel !== $needle) {
            $needleWords = preg_split('/\s+/u', $needleSkel, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($needleWords !== []) {
                foreach ($aliases as $alias) {
                    $normalized = $normalize($alias);

                    if (! str_contains($normalized, 'ا')) {
                        continue;
                    }

                    $aliasWords = preg_split('/\s+/u', self::skeletonOf($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    // كل كلمة من الـ needle لازم تُحتوى داخل كلمة من الـ alias
                    $allWordsMatched = true;
                    foreach ($needleWords as $needleWord) {
                        $wordHit = false;
                        foreach ($aliasWords as $aliasWord) {
                            if (str_contains($aliasWord, $needleWord)) {
                                $wordHit = true;
                                break;
                            }
                        }
                        if (! $wordHit) {
                            $allWordsMatched = false;
                            break;
                        }
                    }

                    if ($allWordsMatched) {
                        $hits[] = $this->mappingHitPayload($record);

                        return;
                    }
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
    private function mergeMappingHits(Collection $moh, string $name, ?array $hits = null): Collection
    {
        $hits ??= $this->lookupMapping($name);

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
     * @param array<int, string>|null $names مفاتيح بحث إنجليزية من الـ mapping —
     *                                       تُستخدم بدل الاسم العربي الخام الذي لا يطابق
     *                                       أسماء الكتالوج الإنجليزية
     * @return array<int, array<string, mixed>>
     */
    public function pharmaciesFor(
        ?string $drugName = null,
        ?int $medicineId = null,
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusKm = 15,
        int $limit = 20,
        ?array $names = null,
    ): array {
        $query = PharmacyMedicine::query()
            ->with('pharmacy:id,pharmacy_name,address,region,latitude,longitude,phone_number,is_active')
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->whereHas('pharmacy', fn ($p) => $p->where('is_active', true));

        if ($medicineId !== null) {
            $query->where('medicine_id', $medicineId);
        } elseif ($names !== null && $names !== []) {
            // استعلام عربي مترجم عبر الـ mapping — نبحث بمفاتيح إنجليزية
            $query->whereHas('medicine', function ($m) use ($names) {
                $applied = false;
                foreach ($names as $key) {
                    $key = trim((string) $key);
                    if (mb_strlen($key) < 2) {
                        continue;
                    }

                    $like = "%{$key}%";
                    if ($applied) {
                        $m->orWhere(function ($w) use ($like) {
                            $w->where('trade_name', 'like', $like)
                                ->orWhere('active_ingredient', 'like', $like);
                        });
                    } else {
                        $m->where(function ($w) use ($like) {
                            $w->where('trade_name', 'like', $like)
                                ->orWhere('active_ingredient', 'like', $like);
                        });
                        $applied = true;
                    }
                }

                if (! $applied) {
                    $m->whereRaw('1 = 0');
                }
            });
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
