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
