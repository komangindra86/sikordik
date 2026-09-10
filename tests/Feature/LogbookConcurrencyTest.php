<?php

namespace Tests\Feature;

use App\Services\EducatorAssignmentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class LogbookConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL logbook lock test.');
        }
    }

    public function test_duplicate_creation_submission_and_approval_are_serialized(): void
    {
        $f = $this->schedulingFixture();
        $supervisor = $this->createUserWithRole('supervisor');
        $educator = DB::table('educators')->insertGetId(['user_id' => $supervisor->id, 'department_id' => $f['department'], 'name' => 'Supervisor concurrency', 'can_supervise' => true]);
        DB::table('educator_licenses')->insert(['educator_id' => $educator, 'license_type' => 'Otorisasi pendidikan', 'license_number' => 'CONCURRENT-SUP', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $assignments = [];
        foreach (['mentor' => $f['educator'], 'supervisor' => $educator] as $role => $id) {
            $a = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $id, 'role' => $role, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan concurrency logbook']);
            app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan concurrency logbook']);
            $assignments[$role] = $a->id;
        }
        DB::table('placements')->where('id', $f['p']->id)->update(['status' => 'sedang_stase']);
        $this->race($f, $f['mentor']->id, 'logbook-save', $f['p']->ulid, ['kind' => 'educator', 'type' => 'Bimbingan concurrency', 'revision' => 0, 'deidentified' => 1,
            'author_assignment_id' => $assignments['mentor'], 'reviewer_assignment_id' => $assignments['supervisor'], 'date' => '2026-10-02', 'start_time' => '09:00', 'end_time' => '10:00', 'material' => 'Materi pendidikan', 'clinical_location_id' => $f['location']]);
        $this->assertDatabaseCount('logbooks', 1);
        $this->assertDatabaseCount('logbook_versions', 1);
        $r = DB::table('logbooks')->first();
        $this->race($f, $f['mentor']->id, 'logbook-transition', $r->ulid, ['action' => 'submit', 'revision' => 1, 'confirm' => 1]);
        $this->race($f, $supervisor->id, 'logbook-transition', $r->ulid, ['action' => 'approve', 'revision' => 2, 'confirm' => 1, 'note' => 'Kegiatan sesuai pemeriksaan']);
        $this->assertDatabaseCount('logbook_reviews', 2);
        $this->assertSame(1, DB::table('logbook_reviews')->where('action', 'approve')->count());
        $this->assertSame(1, DB::table('scheduling_histories')->where('event', 'logbook_approved')->count());
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
