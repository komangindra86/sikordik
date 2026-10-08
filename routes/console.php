<?php

use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PlacementExtensionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('sikordik:remind-attendance', function () {
    $this->info(app(AttendanceService::class)->remind().' pengingat presensi dikirim.');
})->purpose('Kirim pengingat internal presensi, maksimal sekali per penerima per hari');

Schedule::command('sikordik:remind-attendance')->dailyAt('16:00')->withoutOverlapping();

Artisan::command('sikordik:start-placements', function () {
    $started = 0;
    $today = now()->toDateString();
    foreach (DB::table('placements')->where('status', 'dijadwalkan')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->orderBy('id')->get() as $p) {
        // No person is clicking here; the history names the admin who created the placement and states that it was automatic.
        $actor = User::find($p->created_by);
        $started += $actor && app(PlacementExtensionService::class)->startIfDue($actor, $p) ? 1 : 0;
    }
    $this->info($started.' penempatan dimulai otomatis.');
})->purpose('Mulai stase yang tanggal mulainya sudah tiba, jadwalnya terbit, dan dokumennya lengkap');

Schedule::command('sikordik:start-placements')->dailyAt('00:10')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
