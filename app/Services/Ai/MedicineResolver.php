<?php

namespace App\Services\Ai;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use Illuminate\Support\Collection;

/**
 * يحوّل اسم الدواء (من الـ AI أو الـ OCR) إلى نتائج حقيقية من قاعدة البيانات:
 * مرشحين من الكتالوجين + الصيدليات القريبة المتوفرة + البدائل.
 */
final class MedicineResolver
{
    /**
     * يبحث عن الدواء في الكتالوج المحلي وكتالوج وزارة الصحة.
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

        return ['local' => $local, 'moh' => $moh];
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

            if ($da === $db) {
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
