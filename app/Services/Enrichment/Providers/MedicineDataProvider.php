<?php

namespace App\Services\Enrichment\Providers;

/**
 * عقد tất Provider إثراء — قابل للتوسعة بلا تغيير الengine.
 * لا وهم biases: المصدر مسؤول عن صحة بياناته ولا يخرج internal من هنا.
 */
interface MedicineDataProvider
{
    /** البحث باسم / خصائص الدواء — قد يرجع null إن لا نتائج مناسبة. */
    public function searchByMedicine(MedicineQuery $query): ?ProviderResult;

    /** ابحث بالّباركود (normalization هي مسؤولية engine وليس الProvider). */
    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult;

    public function isEnabled(): bool;

    public function name(): string;
}
