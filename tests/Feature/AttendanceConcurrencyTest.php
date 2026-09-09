<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use App\Services\EducatorAssignmentService;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AttendanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL attendance lock test.');
        }
    }

    public function test_duplicate_submission_verification_and_sealing_are_serialized(): void
    {
        $f = $this->schedulingFixture();
        $a = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan concurrency presensi']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Penugasan disetujui untuk pengujian']);
        $s = app(ScheduleService::class)->save($f['owner'], $f['p']->ulid, ['date' => '2026-10-02', 'activity' => 'Kegiatan concurrency', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule']);
        app(ScheduleService::class)->transition($f['owner'], $s->ulid, ['action' => 'submit', 'revision' => 1]);
        app(ScheduleService::class)->transition($f['mentor'], $s->ulid, ['action' => 'approve', 'revision' => 2]);
        app(ScheduleService::class)->transition($f['owner'], $s->ulid, ['action' => 'publish', 'revision' => 3]);
        $this->race($f, $f['owner']->id, 'attendance-save', $f['p']->ulid, ['date' => '2026-10-02', 'activity' => 'Presensi concurrency', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'action' => 'submit', 'attendance_status' => 'hadir']);
        $this->assertDatabaseCount('attendances', 1);
        $r = DB::table('attendances')->first();
        $this->race($f, $f['mentor']->id, 'attendance-decide', $r->ulid, ['action' => 'verify', 'revision' => 1, 'reason' => 'Verifikasi kehadiran concurrency']);
        $this->assertSame(1, DB::table('scheduling_histories')->where('event', 'attendance_verify')->count());
        $this->travelTo(now()->setDate(2026, 10, 11));
        app(AttendanceService::class)->summary($f['admin'], $f['p']->ulid, ['action' => 'generate', 'version' => 0]);
        $this->race($f, $f['chief']->id, 'attendance-summary', $f['p']->ulid, ['action' => 'approve', 'version' => 1]);
        $this->assertSame(1, DB::table('attendance_summaries')->where('status', 'sealed')->count());
        $this->assertSame(1, DB::table('scheduling_histories')->where('event', 'attendance_summary_sealed')->count());
    }

    private function race(array $f, int $actor, string $action, string $ulid, array $payload): void
    {
        $processes = [];
        DB::beginTransaction();
        DB::table('participants')->where('id', $f['p']->participant_id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-admissions.php')], base_path());
                $process->setInput(json_encode(['connection' => config('database.connections.mysql'), 'actor' => $actor, 'action' => $action, 'ulid' => $ulid, 'payload' => $payload], JSON_THROW_ON_ERROR));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'READY'))) !== 2) {
                usleep(20000);
            }
            foreach ($processes as $process) {
                $this->assertStringContainsString('READY', $process->getOutput());
            }
            DB::commit();
            $output = '';
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $output .= $process->getOutput();
            }
            $this->assertSame(1, substr_count($output, 'ACCEPTED'));
            $this->assertSame(1, substr_count($output, 'REJECTED'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
