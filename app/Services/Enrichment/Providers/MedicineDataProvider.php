<?php

namespace App\Services\Enrichment\Providers;

/**
 * عقد مزوّد إثراء — اجعله النيّة الوحيدة المستخدمة عبر المحرك.
 * كل الproviders المجانية تُرجِع ProviderResult أو null — بلا تخمين.
 * لا fake data، لا endpoint مخترع. أسماء المستخدمين (name) موحّدة.
 */
interface MedicineDataProvider
{
    /** البحث بواسطة اسم الدواء / خصائصه — قد يُرجِع null إن لا نتائج مناسبة. */
    public function searchByMedicine(MedicineQuery $query): ?ProviderResult;

    /** ابحث بالباركود (normalization هي مسؤولية engine وليس الprovider). */
    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult;

    /** هل المزوّد مفعل ومصلّب من الconfig/current data. */
    public function isEnabled(): bool;

    /** اسم مضمن (وثائق reporting) — موحّد: local، palestinian، rxnorm، openfda، dailymed، wikidata. */
    public function getName(): string;

    /** مجاني دائماً — Providers المضافة هنا (Paid من المحرموال) */
    public function isFree(): bool;
}
