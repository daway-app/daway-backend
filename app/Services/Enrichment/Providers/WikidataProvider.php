<?php

namespace App\Services\Enrichment\Providers;

use App\Services\Enrichment\NameNormalizer;

/**
 * (أولوية 6) Wikidata — مجاني بلا مفتاح، عبر wbsearchentities + wbgetentities.
 * صفّ عى ين الحصة: نتائج الشاخص المتعلقة بالأbyname الأ Recently؛ تصحّ
 * التصنن يكون "song / trade name" (مثلاً Pan прод Qgs تجارياً تعimité) —
 * أمنع أي مجاعة غير مرّكزة استدعاء ب غير مساص. اسأل both medal و إد في بآلا يظهر به بصورة leading.
 */
final class WikidataProvider extends FreeHttpProvider
{
    public function getName(): string
    {
        return 'wikidata';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.wikidata.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        return null;
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim($query->nameEn);
        if ($nameEn === '') {
            return null;
        }

        $data = $this->request(
            url: 'https://www.wikidata.org/w/api.php',
            params: [
                'action' => 'wbsearchentities',
                'search' => $nameEn,
                'language' => 'en',
                'format' => 'json',
                'limit' => 5,
            ],
        );

        if ($data === null || empty($data['search'])) {
            return null;
        }

        foreach ($data['search'] as $hit) {
            // تجنّب non-medicine entities (مثل الأغاني/الأعمال). الوصف المرتفع
            // يظهر: medicine / drug / pharmaceutical / ingredient / اسم تجاري
            $desc = mb_strtolower((string) ($hit['description'] ?? ''));
            $allowed = ['medic', 'drug', 'pharma', 'ingredient', 'trade name', 'analgesic'];
            $relevant = false;
            foreach ($allowed as $needle) {
                if (str_contains($desc, $needle)) {
                    $relevant = true;
                    break;
                }
            }
            if ($desc !== '' && ! $relevant) {
                continue;
            }

            // wbgetentities لسحب الlabel العربي + الimage P18
            $entityData = $this->request(
                url: 'https://www.wikidata.org/w/api.php',
                params: [
                    'action' => 'wbgetentities',
                    'ids' => $hit['id'],
                    'format' => 'json',
                    'languages' => 'ar|en',
                    'props' => 'labels|claims',
                ],
            );

            if ($entityData === null || empty($entityData['entities'][$hit['id']])) {
                continue;
            }

            $entity = $entityData['entities'][$hit['id']];
            $labelAr = $entity['labels']['ar']['value'] ?? null;
            $labelEn = $entity['labels']['en']['value'] ?? null;
            $imageFilename = $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;

            // P18 يرجع filename →.commons URL عبر hash-path (نمط Wikimedia الرسمي)
            $imageUrl = is_string($imageFilename) && $imageFilename !== ''
                ? self::commonsImageUrl($imageFilename)
                : null;

            if ($labelEn === null && $labelAr === null) {
                continue;
            }

            return ProviderResult::match(
                nameEn: $labelEn,
                nameAr: $labelAr,
                barcodes: [],
                imageUrl: $imageUrl,
                manufacturer: null,
                strength: null,
                dosageForm: null,
                packSize: null,
                provider: $this->getName(),
                sourceReference: $hit['id'],
                payload: $entity,
            );
        }

        return null;
    }

    public function toResult(array $payload, string $sourceRef): ?ProviderResult
    {
        return null;
    }

    /** تحويل filename من P18 إلى Commons URL القياسي (raw pattern). */
    private static function commonsImageUrl(string $filename): ?string
    {
        $filename = str_replace(' ', '_', $filename);
        $md5 = md5($filename);
        $hashPath = $md5[0].'/'.$md5[0].$md5[1];

        return "https://upload.wikimedia.org/wikipedia/commons/{$hashPath}/".rawurlencode($filename);
    }
}
