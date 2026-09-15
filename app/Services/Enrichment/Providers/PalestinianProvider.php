<?php

namespace App\Services\Enrichment\Providers;

use Illuminate\Support\Facades\Log;

/**
 * (أولوية 2) PalestinianProvider — مصدر فلسطيني.
 *
 * بالنقطة: مصدر وزارة الصحة الفلسطينية موثّق بالنظام (services.moh،
 * products_url / prices_url) لكنه ImportError (كتالوج مبدئي فقط — لا
 * Arabic name وليس image يتضمنه). أولاً أنمي: يحن، لا بي رأي عرض Arabic.
 * أو بدون نقل بيانات (عيلها أن يُمحا الْnoscope لاختبار access فعلياً).
 *
 * الفقه: نظيف و Profile — لا endpoints مُختلِقة، ولا scrap random.
 * إعداده بالكامل من config (ON بالتشكيل) ثم جاهز مصدر إذا ثَبت.
 */
final class PalestinianProvider extends FreeHttpProvider
{
    public function getName(): string
    {
        return 'palestinian';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        // معطّل بالمبادرة (غير مثبت — محاوله الوصول لم تلـه تحقق).
        return (bool) config('enrichment.palestinian.enabled', false);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        // المعيّان: need endpoints "conflict" — لا دعم.
        return null;
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        // المصدر الفلسطيني الرسمي غير مُثبَت - unchanged فارغ، لا تختِم.
        Log::info('PalestinianProvider is a placeholder; endpoint not verified');

        return null;
    }

    public function toResult(array $payload, string $sourceRef): ?ProviderResult
    {
        return null;
    }
}
