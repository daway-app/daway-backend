<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// مزامنة بيانات وزارة الصحة يومياً — ملاحظة: Render المجاني بلا cron؛
// تنفيذ الجدولة يتطلب إبقاء العملية حية (schedule:work) أو GitHub Actions
Schedule::command('moh:sync')->dailyAt('02:00');
Schedule::command('reminders:send-due')->everyMinute();
// تنظيف إيصالات التحويل اليتيمة (فشل معاملة بعد رفع الملف) — فترة سماح 24 ساعة.
Schedule::command('settlements:prune-orphan-receipts --hours=24')->dailyAt('03:20');

Artisan::command('settlements:check-receipt-storage', function (\App\Services\Settlement\ReceiptStorage $storage) {
    try {
        $driver = $storage->driver();
        if ($driver === 'disk') {
            app(\App\Services\Settlement\Receipts\DiskReceipts::class)->disk();
        }
    } catch (\Throwable $e) {
        $this->error('Receipt storage configuration is invalid; inspect the driver and required settings.');
        return 1;
    }

    $this->info("Receipt storage configuration is valid ($driver); connectivity was not tested.");
    return 0;
})->purpose('Validate settlement receipt configuration without contacting external services');
