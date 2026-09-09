<?php

use App\Services\AttendanceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('sikordik:remind-attendance', function () {
    $this->info(app(AttendanceService::class)->remind().' pengingat presensi dikirim.');
})->purpose('Kirim pengingat internal presensi, maksimal sekali per penerima per hari');

Schedule::command('sikordik:remind-attendance')->dailyAt('16:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
