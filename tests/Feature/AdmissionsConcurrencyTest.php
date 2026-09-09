<?php

namespace Tests\Feature;

use App\Services\ParticipantService;
use App\Services\PlacementService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdmissionsConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL subprocess locking test.');
        }
    }

    public function test_concurrent_submissions_cannot_reserve_overlapping_periods(): void
    {
        $actor = $this->createUserWithRole('admin-kordik');
        $institution = DB::table('institutions')->insertGetId(['code' => 'C', 'name' => 'Concurrency Test']);
        $level = DB::table('education_levels')->insertGetId(['code' => 'C', 'name' => 'Test']);
        $program = DB::table('study_programs')->insertGetId(['institution_id' => $institution, 'education_level_id' => $level, 'code' => 'C', 'name' => 'Test']);
        $department = DB::table('departments')->insertGetId(['code' => 'C', 'name' => 'Test']);
        $letterUlid = (string) Str::ulid();
        DB::table('incoming_letters')->insert(['ulid' => $letterUlid, 'institution_id' => $institution, 'number' => 'C', 'normalized_number' => 'C', 'letter_date' => '2026-10-01', 'year' => 2026, 'subject' => 'Test', 'created_by' => $actor->id]);
        $p = app(ParticipantService::class)->create($actor, ['name' => 'Concurrent participant', 'institution_id' => $institution]);
        $payload = ['participant_ulid' => $p->ulid, 'letter_ulid' => $letterUlid, 'study_program_id' => $program, 'participant_type_id' => DB::table('participant_types')->value('id'), 'department_id' => $department, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10'];
        $a = app(PlacementService::class)->create($actor, $payload);
        $b = app(PlacementService::class)->create($actor, $payload);
        DB::beginTransaction();
        DB::table('participants')->where('id', $p->id)->lockForUpdate()->first();
        $results = $this->race([
            ['action' => 'submit', 'actor' => $actor->id, 'ulid' => $a->ulid],
            ['action' => 'submit', 'actor' => $actor->id, 'ulid' => $b->ulid],
        ]);
        $this->assertSame(1, substr_count($results, 'ACCEPTED'));
        $this->assertSame(1, substr_count($results, 'REJECTED'));
        $this->assertSame(1, DB::table('placements')->where('status', 'menunggu_konfirmasi_ksm')->count());
        $this->assertSame(1, DB::table('placement_histories')->where('action', 'submit')->count());

        DB::beginTransaction();
        DB::table('participant_sequences')->where('year', now()->year)->lockForUpdate()->first();
        $results = $this->race([
            ['action' => 'participant', 'actor' => $actor->id, 'payload' => ['name' => 'Concurrent identity A', 'nik' => '0123456789012345', 'institution_id' => $institution]],
            ['action' => 'participant', 'actor' => $actor->id, 'payload' => ['name' => 'Concurrent identity B', 'nik' => '0123456789012345', 'institution_id' => $institution]],
        ]);
        $this->assertSame(1, substr_count($results, 'ACCEPTED'));
        $this->assertSame(1, substr_count($results, 'REJECTED'));
        $this->assertSame(2, DB::table('participants')->count());
    }

    private function race(array $inputs): string
    {
        $processes = [];
        try {
            foreach ($inputs as $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-admissions.php')], base_path());
                $process->setInput(json_encode($input + ['connection' => config('database.connections.mysql')], JSON_THROW_ON_ERROR));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                if (count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'READY'))) === count($processes)) {
                    break;
                }
                usleep(20000);
            }
            foreach ($processes as $process) {
                $this->assertStringContainsString('READY', $process->getOutput(), 'Worker did not start.');
            }
            DB::commit();
            $output = '';
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Worker failed; inspect local test logs.');
                $output .= $process->getOutput();
            }

            return $output;
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
