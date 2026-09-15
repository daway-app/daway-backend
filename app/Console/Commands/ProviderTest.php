<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use App\Services\Enrichment\BarcodeNormalizer;
use App\Services\Enrichment\MatchingEngine;
use App\Services\Enrichment\Providers\DrugsApiProvider;
use App\Services\Enrichment\Providers\DailyMedProvider;
use App\Services\Enrichment\Providers\LocalProvider;
use App\Services\Enrichment\Providers\MedicineDataProvider;
use App\Services\Enrichment\Providers\OpenFdaProvider;
use App\Services\Enrichment\Providers\PalestinianProvider;
use App\Services\Enrichment\Providers\RxNormProvider;
use App\Services\Enrichment\Providers\WikidataProvider;
use App\Services\Enrichment\Providers\MedicineQuery;
use Illuminate\Console\Command;
use Throwable;

/**
 * قياس تغطية المزوّد الخارجي — Probe بلا أية كتابة إلى قاعدة البيانات:
 *
 *   php artisan medicines:provider-test --provider=drugs_api --limit=20
 *   php artisan medicines:provider-test --provider=drugs_api --barcodes=storage/app/barcodes.txt
 *
 * - لا fake success: مزوّد معطّل/credentials ناقصة = رسالة واضحة وخروج FAILURE.
 * - التقرير: database/reports/provider_test_report.{json,csv}.
 */
final class ProviderTest extends Command
{
    protected $signature = 'medicines:provider-test
        {--provider=drugs_api : مزوّد واحد بالاسم (drugs_api افتراضياً)}
        {--limit=20 : عدد دواء من بداية الكتالوج عندما لا يُعطى --barcodes}
        {--barcodes= : ملف نصي بباركود (سطر لكل باركود)}';

    protected $description = 'قياس تغطية مزوّد الإثراء على عينة من الكتالوج أو ملف باركودات — بلا كتابة DB';

    public function handle(): int
    {
        $providerName = (string) $this->option('provider');

        $provider = $this->resolve($providerName);
        if ($provider === null) {
            if ($providerName === 'drugs_api') {
                // Cost Guard: مزوّد مدفوع — معطّل ولا يُشغّل إلا بتفعيل صريح
                $this->error('Provider disabled / credentials missing — drugs_api مقفول بواسطة ENRICHMENT_PAID_PROVIDERS=false');
            } else {
                $this->error("مزوّد مجهول: {$providerName}");
            }

            return self::FAILURE;
        }
        if (! $provider->isEnabled()) {
            $this->error('Provider disabled / credentials missing — لا fake success. اضبط config/enrichment.php و.env قبل القياس.');

            return self::FAILURE;
        }

        $barcodesOption = (string) $this->option('barcodes');
        $samples = $barcodesOption !== ''
            ? $this->fileBarcodes($barcodesOption)
            : $this->catalogSample((int) $this->option('limit'));

        if ($samples === []) {
            $this->warn('لا عينات للقياس (الكتالوج فارغ أو الملف فارغ)');

            return self::FAILURE;
        }

        $stats = $this->probe($provider, $samples);
        $this->display($stats);
        $this->exportReport($providerName, $stats, $barcodesOption);

        return self::SUCCESS;
    }

    private function resolve(string $name): ?MedicineDataProvider
    {
        return match ($name) {
            'local' => new LocalProvider(app(\App\Services\Ai\MedicineResolver::class)),
            'palestinian' => new PalestinianProvider(),
            'rxnorm' => new RxNormProvider(),
            'openfda' => new OpenFdaProvider(),
            'dailymed' => new DailyMedProvider(),
            'wikidata' => new WikidataProvider(),
            // paid drugs_api شُغّل فقط بتفعيل مزدوج (cost guard)
            'drugs_api' => ((bool) config('enrichment.paid_providers', false)) === true ? new DrugsApiProvider() : null,
            default => null,
        };
    }

    /** @return string[] */
    private function fileBarcodes(string $relPath): array
    {
        $path = base_path($relPath);
        if (! is_file($path)) {
            $this->error("ملف الباركود غير موجود: {$path}");

            return [];
        }

        return collect(explode("\n", (string) file_get_contents($path)))
            ->map(fn ($l) => trim($l))
            ->filter(fn ($l) => $l !== '')
            ->unique()
            ->values()->all();
    }

    /** عيّن من الكتالوج الرئيسي (لا تسمية اصطناعية). */
    private function catalogSample(int $limit): array
    {
        return MohMedicine::orderBy('id')->limit($limit)->get()
            ->map(fn (MohMedicine $m) => ['kind' => 'medicine', 'moh' => $m])
            ->all();
    }

    private function totals(): array
    {
        return [
            'total' => 0, 'found' => 0, 'not_found' => 0,
            'english_name' => 0, 'arabic_name' => 0, 'barcode' => 0, 'image' => 0,
            'manufacturer' => 0, 'pack_size' => 0,
            'high_confidence' => 0, 'needs_review' => 0, 'conflicts' => 0, 'provider_errors' => 0,
        ];
    }

    /**
     * تشغيل العينة عبر الprovider — قراءة فقط، بلا مراجعة، بلا DB.
     * لكل عينة: score عبر MatchingEngine (اتساقه يعتمد مصدر provider الحقيقي).
     */
    private function probe(MedicineDataProvider $provider, array $samples): array
    {
        $stats = $this->totals();

        foreach ($samples as $sample) {
            $stats['total']++;

            $result = null;
            $moh = null;

            if (($sample['kind'] ?? '') === 'barcode') {
                try {
                    $result = $provider->searchByBarcode((string) $sample['barcode']);
                } catch (Throwable $e) {
                    $stats['provider_errors']++;
                    $this->line('− استثناء barcode: '.$e->getMessage());

                    continue;
                }
            } else {
                $moh = $sample['moh'];
                $query = new MedicineQuery(
                    $moh,
                    (string) $moh->trade_name,
                    '',
                    $moh->manufacturer ?: $moh->company,
                    $moh->dosage_form,
                    $moh->packaging
                );
                $result = null;
                try {
                    $result = $provider->searchByMedicine($query);
                } catch (Throwable $e) {
                    $stats['provider_errors']++;
                    $this->line('− استثناء medicine: '.$e->getMessage());

                    continue;
                }
            }

            if ($result === null) {
                $stats['not_found']++;

                continue;
            }

            $stats['found']++;
            if ($result->nameEn !== null && $result->nameEn !== '') $stats['english_name']++;
            if ($result->nameAr !== null && $result->nameAr !== '') $stats['arabic_name']++;
            if ($result->hasBarcode()) $stats['barcode']++;
            if (! empty($result->imageUrl)) $stats['image']++;
            if (! empty($result->manufacturer)) $stats['manufacturer']++;
            if (! empty($result->packSize)) $stats['pack_size']++;

            if ($moh !== null) {
                $score = MatchingEngine::score($moh, $result);
                if ($score['decision'] === 'auto_accept') {
                    $stats['high_confidence']++;
                } elseif (in_array($score['decision'], ['apply_with_review', 'review'], true)) {
                    $stats['needs_review']++;
                } elseif ($score['decision'] === 'rejected') {
                    //isNew barcodes هم «النزل تحت review» — لا نعدّها أفضل مخّ
                    continue;
                }
            }
        }

        return $stats;
    }

    private function display(array $stats): void
    {
        $this->table(['Total:', 'Found:', 'Not found:', 'Provider errors:'], [
            [$stats['total'], $stats['found'], $stats['not_found'], $stats['provider_errors']],
        ]);

        $this->table(
            ['English', 'Arabic', 'Barcode', 'Image', 'Manufacturer', 'Pack size'],
            [[
                $stats['english_name'], $stats['arabic_name'], $stats['barcode'],
                $stats['image'], $stats['manufacturer'], $stats['pack_size'],
            ]]
        );

        $this->table(
            ['High confidence:', 'Needs review:', 'Conflicts:'],
            [[$stats['high_confidence'], $stats['needs_review'], $stats['conflicts']]]
        );
    }

    private function exportReport(string $provider, array $stats, string $barcodesPath): void
    {
        $payload = [
            'provider' => $provider,
            'generated_at' => now()->toDateTimeString(),
            'barcodes_file' => $barcodesPath !== '' ? $barcodesPath : null,
            'stats' => $stats,
        ];
        $dir = database_path('reports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/provider_test_report.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('✓ report: database/reports/provider_test_report.json');

        $csv = fopen($dir.'/provider_test_report.csv', 'w');
        fputcsv($csv, ['metric', 'value']);
        foreach ($stats as $k => $v) {
            fputcsv($csv, [$k, $v]);
        }
        fclose($csv);
        $this->line('✓ report: database/reports/provider_test_report.csv');
    }
}
