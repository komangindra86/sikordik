<?php

namespace Tests\Feature;

use App\Services\EducatorAssignmentService;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class SchedulingConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL scheduling lock test.');
        }
    }

    public function test_concurrent_schedule_submission_and_publication_are_serialized(): void
    {
        $f = $this->schedulingFixture();
        $a = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan untuk concurrency test']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan fixture concurrency']);
        $data = ['date' => '2026-10-02', 'start_time' => '08:00', 'end_time' => '10:00', 'activity' => 'Concurrency test', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule'];
        $one = app(ScheduleService::class)->save($f['owner'], $f['p']->ulid, $data);
        $two = app(ScheduleService::class)->save($f['owner'], $f['p']->ulid, $data);
        $this->race($f, [$one, $two], 'submit', 1);
        $this->assertSame(1, DB::table('schedules')->where('status', 'submitted')->count());
        $winner = DB::table('schedules')->where('status', 'submitted')->first();
        app(ScheduleService::class)->transition($f['mentor'], $winner->ulid, ['action' => 'approve', 'revision' => 2]);
        $this->race($f, [$winner, $winner], 'publish', 3);
        $this->assertSame(1, DB::table('schedules')->where('status', 'published')->count());
        $this->assertSame(1, DB::table('scheduling_histories')->where('event', 'schedule_publish')->count());
    }

    private function race(array $f, array $schedules, string $action, int $revision): void
    {
        $processes = [];
        DB::beginTransaction();
        DB::table('participants')->where('id', $f['p']->participant_id)->lockForUpdate()->first();
        try {
            foreach ($schedules as $s) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-admissions.php')], base_path());
                $process->setInput(json_encode(['connection' => config('database.connections.mysql'), 'actor' => $f['owner']->id, 'action' => 'schedule', 'ulid' => $s->ulid, 'payload' => ['action' => $action, 'revision' => $revision]], JSON_THROW_ON_ERROR));
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
