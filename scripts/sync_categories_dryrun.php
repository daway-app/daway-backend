<?php

/**
 * سكربت dry-run للـmoh:sync-categories.
 * يفتح sqlite in-memory، يشغّل migrations + CategorySeeder، ثم يطلب
 * MohCategorySync::plan() بدون أي كتابة.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Category;
use App\Support\MohCategorySync;
use Illuminate\Support\Facades\Artisan;

$app = require __DIR__ . '/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env');

// bootstrap the kernel first so config() works
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ثم فُر override الـenv values على runtime (يأخذ الأسبقية على ملف .env)
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
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::purge('mysql');

// مهّد الـDB
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
echo Artisan::output();

$count = Category::count();
echo "Categories in DB: {$count}\n";
echo "Slugs: " . Category::pluck('slug')->join(', ') . "\n\n";

$sync = new MohCategorySync();
$plan = $sync->plan(base_path('database/data/moh_medicines_categorized.json'));

echo "=== خطة المزامنة ===\n";
echo "JSON rows:           {$plan['total_rows']}\n";
echo "Total expected links: {$plan['total_links']}\n";
echo "Multi-category meds:  {$plan['multi_category_medicines']}\n";
echo "needs_review links:   {$plan['needs_review_links']}\n";
echo "needs_review meds:    {$plan['needs_review_medicines']}\n";
echo "\n=== By DB slug ===\n";
foreach ($plan['by_slug'] as $slug => $c) {
    printf("%-40s %d\n", $slug, $c);
}
echo "\n=== By source ===\n";
foreach ($plan['by_source'] as $src => $c) {
    printf("%-30s %d\n", $src, $c);
}

if (! empty($plan['unmatched_json_slugs'])) {
    echo "\n=== JSON slugs بلا مقابل في الـDB (سيتم تجاهلها) ===\n";
    foreach ($plan['unmatched_json_slugs'] as $slug => $name) {
        echo "  - {$slug} ({$name})\n";
    }
} else {
    echo "\nكل JSON slugs لها مقابل في الـDB ✓\n";
}