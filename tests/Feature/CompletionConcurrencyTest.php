<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CompletionFixtures;
use Tests\TestCase;

class CompletionConcurrencyTest extends TestCase
{
    use CompletionFixtures, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL completion locking test.');
        }
    }

    public function test_survey_completion_reopening_and_archive_serialize_duplicate_requests(): void
    {
        $f = $this->completionFixture();
        DB::table('survey_responses')->where('kind', 'patient')->update(['status' => 'issued', 'revision' => 1, 'verified_by' => null]);
        $this->race($f, 'owner', 'survey', ['kind' => 'patient', 'action' => 'submit', 'revision' => 1, 'confirm' => 1]);
        $this->race($f, 'admin', 'survey', ['kind' => 'patient', 'action' => 'verify', 'revision' => 2, 'confirm' => 1]);
        foreach (['submit' => 'admin', 'approve' => 'kordik', 'reopen_request' => 'admin', 'reopen_execute' => 'admin'] as $action => $role) {
            if ($action === 'reopen_execute') {
                $this->race($f, 'kordik', 'completion', $this->payload($f, 'approve'));
            }
            $this->race($f, $role, 'completion', $this->payload($f, $action));
        }
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'status' => 'menunggu_penyelesaian']);
        $this->assertSame(1, DB::table('completion_requests')->where('status', 'completed')->count());
        $this->assertSame(1, DB::table('completion_requests')->where('status', 'executed')->count());
        $this->race($f, 'admin', 'completion', $this->payload($f, 'submit'));
        $this->race($f, 'kordik', 'completion', $this->payload($f, 'approve'));
        $this->race($f, 'admin', 'completion', $this->payload($f, 'archive'));
        $this->assertSame(1, DB::table('placement_histories')->where('action', 'completion_archive')->count());
        $this->assertDatabaseCount('completion_requests', 3);
    }

    private function payload(array $f, string $action): array
    {
        $r = DB::table('completion_requests')->latest('id')->first();

        return ['action' => $action, 'revision' => DB::table('placements')->where('id', $f['p']->id)->value('revision'), 'request_id' => $r?->id, 'request_revision' => $r?->revision, 'reason' => 'Pemeriksaan penyelesaian concurrency', 'confirm' => 1];
    }

    private function race(array $f, string $actor, string $service, array $payload): void
    {
        $processes = [];
        DB::beginTransaction();
        DB::table('participants')->where('id', $f['p']->participant_id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-admissions.php')], base_path());
                $process->setInput(json_encode(['connection' => config('database.connections.mysql'), 'actor' => $f[$actor]->id, 'action' => $service, 'ulid' => $f['p']->ulid, 'payload' => $payload], JSON_THROW_ON_ERROR));
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
            $this->assertSame(1, substr_count($output, 'ACCEPTED'), $service.':'.$payload['action']);
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
