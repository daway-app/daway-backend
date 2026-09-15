<?php

namespace App\Console\Commands;

use App\Services\Enrichment\Providers\DailyMedProvider;
use App\Services\Enrichment\Providers\DrugsApiProvider;
use App\Services\Enrichment\Providers\LocalProvider;
use App\Services\Enrichment\Providers\MedicineDataProvider;
use App\Services\Enrichment\Providers\OpenFdaProvider;
use App\Services\Enrichment\Providers\PalestinianProvider;
use App\Services\Enrichment\Providers\RxNormProvider;
use App\Services\Enrichment\Providers\WikidataProvider;
use Illuminate\Console\Command;

/**
 * فحص صحة مزوّدات الإثراء — لا استدعاء شبكة: يعرض الحالة من config
 * فقط، مع عمود Free (لا مدفوعة) + Status (OK/DISABLED/UNVERIFIED).
 *
 *   php artisan medicines:providers
 */
final class EnrichProviders extends Command
{
    protected $signature = 'medicines:providers';

    protected $description = 'جدول المزوّدين المجانيين وحالتهم (Cost Guard: بلا مدفوع)';

    public function handle(): int
    {
        /** @var array<int, MedicineDataProvider> */
        $providers = [
            new LocalProvider(app(\App\Services\Ai\MedicineResolver::class)),
            new PalestinianProvider(),
            new RxNormProvider(),
            new OpenFdaProvider(),
            new DailyMedProvider(),
            new WikidataProvider(),
        ];

        $rows = [];
        foreach ($providers as $p) {
            $configKey = match ($p->getName()) {
                'local' => 'local',
                'palestinian' => 'palestinian',
                'rxnorm' => 'rxnorm',
                'openfda' => 'openfda',
                'dailymed' => 'dailymed',
                'wikidata' => 'wikidata',
                default => null,
            };

            $verified = match ($p->getName()) {
                'local' => 'OK',
                'rxnorm', 'openfda', 'dailymed', 'wikidata' => ($p->isEnabled() ? 'OK' : 'disabled'),
                default => 'UNVERIFIED',   // مصدر الفلسطيني الحلف لم تأثر pen test
            };

            $rows[] = [
                $p->getName(),
                $p->isEnabled() ? 'YES' : 'NO',
                $p->isFree() ? 'YES' : 'NO',
                $verified,
            ];
        }

        $this->table(
            ['Provider', 'Enabled', 'Free', 'Status'],
            $rows
        );

        // وصف إل充分 حابل الأمان paid being off by default
        $deleted = new DrugsApiProvider();
        $paidFlag = (bool) config('enrichment.paid_providers', false);
        $this->line('');
        $this->line('PAID providers friendly note: drugs_api paid='.($deleted->isFree() ? 'NO' : 'YES').', gate='.
            ($paidFlag ? 'ON (paid allowed)' : 'OFF (default)').' → '.$deleted->isEnabled() ? 'enabled=false' : 'disabled');

        return self::SUCCESS;
    }
}
