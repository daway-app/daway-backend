<?php
// إعادة بذر بيانات العينة — boot framework ثم seed عبر artisan tinker
use App\Models\Medicine;
use App\Models\MohMedicine;

$N = 100; // 100 عيّنة على sqlite — تكافئ التشغيل --limit=100 بالفعل

for ($i = 1; $i <= $N; $i++) {
    MohMedicine::updateOrCreate(
        ['moh_product_id' => 80000 + $i],
        [
            'trade_name' => 'PANADOL EXTRA '.$i,
            'generic_name' => null,
            'manufacturer' => 'GSK',
            'dosage_form' => 'Tablet',
            'packaging' => '24 tablets',
        ]
    );
    Medicine::updateOrCreate(
        ['trade_name' => 'PANADOL EXTRA '.$i],
        [
            'trade_name_ar' => 'بانادول اكسترا '.((int) $i),
            'active_ingredient' => 'Paracetamol',
            'is_available' => true,
            'stock' => 0,
        ]
    );
}

echo 'moh: '.MohMedicine::whereBetween('moh_product_id', [80001, 80000 + $N])->count()."/$N".
     ', local: '.Medicine::where('trade_name', 'like', 'PANADOL EXTRA%')->count()."/$N";
