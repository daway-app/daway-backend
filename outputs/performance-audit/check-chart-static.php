<?php

/**
 * تحقق ساكن: كل صفحة تستخدم الرسوم لازم تشير للمكتبة المحلية فقط،
 * والمكتبة المحلية لازم تكون مكتبة حقيقية لا ستَب.
 */

$root = dirname(__DIR__, 2);
$views = [
    'dashboard/index'          => 'resources/views/dashboard/index.blade.php',
    'pharmacies/index'         => 'resources/views/pharmacies/index.blade.php',
    'pharmacy/dashboard/index' => 'resources/views/pharmacy/dashboard/index.blade.php',
    'pharmacy/inventory/index' => 'resources/views/pharmacy/inventory/index.blade.php',
    'pharmacy/ratings/index'   => 'resources/views/pharmacy/ratings/index.blade.php',
];

$fail = 0;

echo "=== مراجع Chart.js في الصفحات ===\n";
foreach ($views as $name => $rel) {
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false) {
        printf("%-28s !! الملف غير موجود\n", $name);
        $fail++;
        continue;
    }
    $hasLocal = str_contains($src, "asset('vendor/chart.umd.js')");
    $hasCdn = (bool) preg_match('#cdn\.jsdelivr\.net/npm/chart#', $src);
    $ok = $hasLocal && !$hasCdn;
    if (!$ok) {
        $fail++;
    }
    printf("%-28s محلي=%-4s CDN=%-4s %s\n", $name, $hasLocal ? 'نعم' : 'لا', $hasCdn ? 'نعم' : 'لا', $ok ? 'OK' : '!! مشكلة');
}

echo "\n=== المكتبة المحلية ===\n";
$lib = $root . '/public/vendor/chart.umd.js';
if (!is_file($lib)) {
    echo "!! public/vendor/chart.umd.js غير موجود\n";
    $fail++;
} else {
    $size = filesize($lib);
    $head = file_get_contents($lib, false, null, 0, 200);

    $isHeader = str_contains($head, 'Chart.js v4');
    $looksStub = str_contains($head, 'offline stub');

    printf("الحجم          %s بايت\n", number_format($size));
    printf("ترويسة v4      %s\n", $isHeader ? 'نعم' : 'لا');
    printf("ستَب وهمي؟     %s\n", $looksStub ? 'نعم (سئ)' : 'لا (جيد)');

    $realOk = $size > 50000 && $isHeader && !$looksStub;
    printf("النتيجة        %s\n", $realOk ? 'مكتبة حقيقية OK' : '!! ليست مكتبة حقيقية');
    if (!$realOk) {
        $fail++;
    }
}

echo "\n=== الحصيلة ===\n";
echo $fail === 0 ? "كل الفحوص نجحت\n" : "فشل {$fail} فحص\n";

exit($fail === 0 ? 0 : 1);
