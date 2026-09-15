<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use App\Services\Enrichment\BarcodeNormalizer;
use App\Services\Enrichment\Providers\DailyMedProvider;
use App\Services\Enrichment\Providers\MedicineDataProvider;
use App\Services\Enrichment\Providers\MedicineQuery;
use App\Services\Enrichment\Providers\OpenFdaProvider;
use App\Services\Enrichment\Providers\PalestinianProvider;
use App\Services\Enrichment\Providers\RxNormProvider;
use App\Services\Enrichment\Providers\WikidataProvider;
use App\Services\Enrichment\Providers\LocalProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * اكتشاف الباركود (المجاني فقط) — Probe صرف: بلا كتابة DB، بلا تصنيف.
 *
 *   php artisan medicines:barcode-discover --limit=20 --dry-run
 *   php artisan medicines:barcode-discover --provider=rxnorm --limit=20 --dry-run
 *   php artisan medicines:barcode-discover --limit=100 --dry-run
 *
 * النتيجة: storage/app/reports/barcode_discovery.{json,csv}
 *
 * القاعدة الصارمة: لا اختراع ولا تحويل NDC → GTIN؛ الباركود يُقبل **فقط**
 * إن المصدر صرح صراحةً أنه EAN/GTIN/UPC — ولا اعتبار NDC/RxCUI/Q-ID باركوداً.
 */
final class DiscoverBarcodes extends Command
{
    protected $signature = 'medicines:barcode-discover
        {--provider=all : مزوّد واحد (rxnorm|openfda|dailymed|wikidata|palestinian) أو all}
        {--limit=20 : عدد الأدوية من بداية الكتالوج}
        {--offset=0 : بداية من offset صريح}
        {--dry-run : إلزامي حالياً — بلا أية كتابة DB}';

    protected $description = 'اكتشاف الباركودات المجانية فقط (dry-run): يفحص مزوّدات مجانية ويعمل تقرير لا كتابة DB';

    public function handle(): int
    {
        if (! (bool) $this->option('dry-run')) {
            // أمنياً: هذه المرحلة مسماة dry-run فقط. لا يوجد import.
            $this->warn('بلا --dry-run غير مسموح في هذه المرحلة (لا كتابة DB).');
            $this->warn('نفّذ: php artisan medicines:barcode-discover --limit=20 --dry-run');

            return self::FAILURE;
        }

        $wanted = strtolower((string) $this->option('provider'));
        $providerList = $this->resolveProviders($wanted);
        if ($providerList === []) {
            $this->error('لا مزوّد مفعّل يناسب الطلب');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));

        $medicines = MohMedicine::orderBy('id')->skip($offset)->take($limit)->get();
        if ($medicines->isEmpty()) {
            $this->warn('لا أدوية في الكتالوج أو الـoffset تجاوز العدد');

            return self::SUCCESS;
        }

        $rows = [];
        $typeCounts = ['EAN8' => 0, 'EAN13' => 0, 'UPCA' => 0, 'GTIN14' => 0, 'NDC-only' => 0];
        $stats = ['total' => 0, 'found' => 0, 'not_found' => 0, 'high_confidence' => 0, 'review' => 0, 'conflicts' => 0, 'provider_errors' => 0];
        $contribution = [];
        $existingBarcodes = [];

        foreach ($medicines as $medicine) {
            $stats['total']++;

            $query = new MedicineQuery(
                $medicine,
                (string) $medicine->trade_name,
                '',
                (string) ($medicine->manufacturer ?: $medicine->company),
                $medicine->dosage_form,
                $medicine->packaging
            );

            $medicineEntry = null;

            foreach ($providerList as $provider) {
                $providerFound = false;
                $result = null;
                $reason = 'provider has no barcode';

                try {
                    $result = $provider->searchByMedicine($query);
                } catch (Throwable $e) {
                    $stats['provider_errors']++;
                    $reason = 'provider error: '.$e->getMessage();
                    $providerFound = false;
                    $result = null;
                }

                if ($result !== null && $result->hasBarcode()) {
                    $primary = $result->primaryBarcode();
                    $value = $primary['value'];
                    $norm = BarcodeNormalizer::normalize($value);
                    if ($norm !== null && BarcodeNormalizer::isValidEan13($value) === true) {
                        // valid barcode per spec — مسجّل
                        $providerFound = true;
                        $stats['found']++;
                        $type = $primary['type'] ?? $norm['type'];
                        if (isset($typeCounts[$type])) {
                            $typeCounts[$type]++;
                        }

                        $diffMeds = ($existingBarcodes[$value] ?? null) !== null
                            && ($existingBarcodes[$value] !== (int) $medicine->id);
                        if ($diffMeds) {
                            $stats['conflicts']++;
                        }
                        $existingBarcodes[$value] = (int) $medicine->id;

                        $confidence = (float) ($result->payload['match_confidence'] ?? 0.60);
                        if ($confidence >= 0.80) {
                            $stats['high_confidence']++;
                        } else {
                            $stats['review']++;
                        }

                        $rows[] = [
                            'medicine_id' => $medicine->id,
                            'moh_product_id' => $medicine->moh_product_id,
                            'trade_name' => $medicine->trade_name,
                            'generic_name' => $medicine->generic_name,
                            'manufacturer' => $medicine->manufacturer ?: $medicine->company,
                            'provider' => $provider->getName(),
                            'provider_found' => true,
                            'barcode' => $value,
                            'barcode_type' => $type,
                            'source_reference' => $result->sourceReference,
                            'confidence' => $confidence,
                            'reason' => 'ok',
                        ];

                        $providerName = $provider->getName();
                        $contribution[$providerName] = ($contribution[$providerName] ?? 0) + 1;
                    } else {
                        // لا تخمين: إن لم يكن EAN-13 مع checksum صحيح لا يُقبَل.
                        $rows[] = [
                            'medicine_id' => $medicine->id,
                            'moh_product_id' => $medicine->moh_product_id,
                            'trade_name' => $medicine->trade_name,
                            'generic_name' => $medicine->generic_name,
                            'manufacturer' => $medicine->manufacturer ?: $medicine->company,
                            'provider' => $provider->getName(),
                            'provider_found' => true,
                            'barcode' => null,
                            'barcode_type' => null,
                            'source_reference' => $result->sourceReference,
                            'confidence' => null,
                            'reason' => 'barcode format rejected by our validator',
                        ];
                    }
                } else {
                    // provider أرجع null أو بلا باركود — سجّل بلا اعتداءات.
                    $rows[] = [
                        'medicine_id' => $medicine->id,
                        'moh_product_id' => $medicine->moh_product_id,
                        'trade_name' => $medicine->trade_name,
                        'generic_name' => $medicine->generic_name,
                        'manufacturer' => $medicine->manufacturer ?: $medicine->company,
                        'provider' => $provider->getName(),
                        'provider_found' => false,
                        'barcode' => null,
                        'barcode_type' => null,
                        'source_reference' => null,
                        'confidence' => null,
                        'reason' => $reason,
                    ];
                }
            }

            if ($providerFound) {
                $stats['found']++;
            } else {
                $stats['not_found']++;
            }
        }

        $this->info('==== Barcode Discovery (dry-run, zero-cost) ====');
        $this->table(['Metric', 'Value'], collect($stats)->map(fn ($k, $v) => [$k, $v])->all());
        $this->table(['EAN-8', 'EAN-13', 'UPC-A', 'GTIN-14', 'NDC-only'], [
            [$typeCounts['EAN8'], $typeCounts['EAN13'], $typeCounts['UPCA'], $typeCounts['GTIN14'], $typeCounts['NDC-only']],
        ]);

        // الأوائل 20 نتيجة اداء مع باركود، والأوائل 20 bila barcode — مع أسماء/الأدلة على الاسباب
        $found = collect($rows)->where('barcode', '!=', null)->take(20)->all();
        $this->line('==== أمثلة ناجحة ====');
        foreach ($found as $r) {
            $this->line(($r['trade_name'] ?: '—').' | provider='.$r['provider'].' | barcode='.$r['barcode'].' ('.$r['barcode_type'].') | ref='.$r['source_reference']);
        }

        $failedRows = collect($rows)->filter(fn ($r) => $r['barcode'] === null)->take(20)->all();
        $this->line('==== أمثلة فشل ====');
        foreach ($failedRows as $r) {
            $this->line(($r['trade_name'] ?: '—').' | provider='.$r['provider'].' | reason='.$r['reason']);
        }

        // التقرير الخارجي:
        $this->exportReport($providerList, $rows, $stats, $typeCounts, $contribution);

        return self::SUCCESS;
    }

    private function resolveProviders(string $name): array
    {
        $all = [
            'palestinian' => new PalestinianProvider(),
            'rxnorm' => new RxNormProvider(),
            'openfda' => new OpenFdaProvider(),
            'dailymed' => new DailyMedProvider(),
            'wikidata' => new WikidataProvider(),
        ];

        if ($name === 'all') {
            return collect($all)->filter(fn (MedicineDataProvider $p) => $p->isEnabled())->values()->all();
        }

        return isset($all[$name]) && $all[$name]->isEnabled() ? [$all[$name]] : [];
    }

    private function exportReport(array $providers, array $rows, array $stats, array $typeCounts, array $contribution): void
    {
        $dir = storage_path('app/reports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $coverage = $stats['total'] > 0 ? round($stats['found'] / $stats['total'] * 100, 1) : 0.0;

        $payload = [
            'generated_at' => now()->toDateTimeString(),
            'providers' => collect($providers)->map(fn (MedicineDataProvider $p) => $p->getName())->all(),
            'stats' => $stats,
            'type_counts' => $typeCounts,
            'provider_contribution' => $contribution,
            'coverage_pct' => $coverage,
            'rows' => $rows,
        ];
        file_put_contents($dir.'/barcode_discovery.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('✓ report: storage/app/reports/barcode_discovery.json');

        $csv = fopen($dir.'/barcode_discovery.csv', 'w');
        fputcsv($csv, array_keys($rows[0] ?? ['medicine_id' => '', 'moh_product_id' => '', 'trade_name' => '', 'generic_name' => '', 'manufacturer' => '', 'provider' => '', 'provider_found' => '', 'barcode' => '', 'barcode_type' => '', 'source_reference' => '', 'confidence' => '', 'reason' => '']));
        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }
        fclose($csv);
        $this->line('✓ report: storage/app/reports/barcode_discovery.csv');
    }
}
