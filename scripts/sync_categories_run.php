<?php

/**
 * سكربت فعلي لـmoh:sync-categories على sqlite in-memory.
 * يُستخدم للاختبار السريع دون لمس Production.
 *
 * يقرأ: database/data/moh_medicines_categorized.json
 * يكتب في: category_medicine_links (يحذف الروابط غير-الأدمن أولاً بشكل --fresh)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Support\CategoryCatalogCache;
use App\Support\MohCategorySync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env');

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['LOG_CHANNEL'] = 'null';

config([
    'app.env' => 'testing',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.foreign_key_constraints' => true,
    'logging.default' => 'null',
]);
DB::purge('sqlite');
DB::purge('mysql');

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

// للتأكد قبل: لا روابط في البداية
$before = CategoryMedicineLink::count();
echo "Before: {$before} links\n";

$sync = new MohCategorySync();
$stats = DB::transaction(fn () => $sync->execute(
    base_path('database/data/moh_medicines_categorized.json'),
    ['fresh' => true, 'dry' => false, 'chunk' => 500]
));

echo "=== Sync Stats ===\n";
foreach ($stats as $k => $v) {
    printf("%-30s %d\n", $k, $v);
}

$after = CategoryMedicineLink::count();
echo "\nAfter: {$after} links\n";

// تحقق idempotency: شغّل مرة ثانية
echo "\n--- Idempotency check: شغّل مرة ثانية ---\n";
$stats2 = DB::transaction(fn () => $sync->execute(
    base_path('database/data/moh_medicines_categorized.json'),
    ['fresh' => false, 'dry' => false, 'chunk' => 500]
));
foreach ($stats2 as $k => $v) {
    printf("%-30s %d\n", $k, $v);
}
$after2 = CategoryMedicineLink::count();
echo "After 2nd run: {$after2} links (should equal {$after})\n";

// تحقق fresh: شغّل مرة ثالثة مع --fresh لكن مع admin link يدوي
echo "\n--- Fresh + admin protection check ---\n";
DB::table('category_medicine_links')->insert([
    'category_id' => Category::where('slug', 'medicines')->first()->id,
    'moh_product_id' => 999999,  // PID غير موجود في JSON
    'moh_drug_id' => null,
    'medicine_id' => null,
    'source' => 'admin',
    'confidence' => 50,
    'needs_review' => false,
    'created_at' => now(),
    'updated_at' => now(),
]);
$withAdmin = CategoryMedicineLink::count();
echo "After inserting manual admin link: {$withAdmin}\n";

$stats3 = DB::transaction(fn () => $sync->execute(
    base_path('database/data/moh_medicines_categorized.json'),
    ['fresh' => true, 'dry' => false, 'chunk' => 500]
));
foreach ($stats3 as $k => $v) {
    printf("%-30s %d\n", $k, $v);
}
$after3 = CategoryMedicineLink::count();
echo "After 3rd run with --fresh: {$after3} links\n";
echo "Expected: 1 (only the admin link survives)\n";
echo "Actual admin link still there: " . (CategoryMedicineLink::where('source', 'admin')->count() === 1 ? 'YES ✓' : 'NO ✗') . "\n";

// cache bump
CategoryCatalogCache::bump();
echo "\nCache version after sync: " . CategoryCatalogCache::version() . "\n";