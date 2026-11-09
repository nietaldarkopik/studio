<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Produksi harian otomatis. Aktif bila PRODUCTION_DAILY_AT diisi (mis. 08:00, zona Asia/Jakarta).
// Jalankan scheduler dengan `php artisan schedule:work` (butuh `queue:work --timeout=0` juga).
if ($dailyAt = config('services.production.daily_at')) {
    Schedule::command('studio:autopilot')->dailyAt($dailyAt)->timezone('Asia/Jakarta')->withoutOverlapping(360);
}
