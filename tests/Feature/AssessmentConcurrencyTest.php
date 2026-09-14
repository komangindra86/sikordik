<?php

namespace Tests\Feature;

use App\Services\AssessmentTemplateService;
use App\Services\EducatorAssignmentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AssessmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL assessment locking test.');
        }
    }

    public function test_duplicate_creation_approval_publication_and_appeal_are_serialized(): void
    {
        $f = $this->schedulingFixture();
        $a = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan concurrency penilaian']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan concurrency penilaian']);
        $template = app(AssessmentTemplateService::class)->create($f['admin'], ['name' => 'Penilaian concurrency', 'exam_type' => 'Responsi', 'calculation' => 'none',
            'components' => [['name' => 'Skor', 'input_type' => 'number', 'minimum' => 0, 'maximum' => 100, 'required' => 1]]]);
        $component = DB::table('assessment_components')->where('assessment_template_id', $template)->value('id');
        DB::table('placements')->where('id', $f['p']->id)->update(['status' => 'sedang_stase']);
        $this->race($f, $f['mentor']->id, 'assessment-save', $f['p']->ulid, ['template_id' => $template, 'title' => 'Responsi pertama', 'date' => '2026-10-02', 'mode' => 'dynamic',
            'revision' => 0, 'deidentified' => 1, 'author_assignment_id' => $a->id, 'mentor_assignment_id' => $a->id, 'scores' => [$component => 80]]);
        $r = DB::table('assessments')->first();
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('assessment_versions', 1);
        foreach (['approve', 'publish', 'appeal', 'review', 'accept'] as $i => $action) {
            $this->race($f, $f[$action === 'appeal' ? 'owner' : 'mentor']->id, 'assessment-transition', $r->ulid,
                ['action' => $action, 'revision' => $i + 1, 'confirm' => 1, 'deidentified' => 1, 'note' => 'Keputusan concurrency penilaian']);
        }
        $this->assertSame(1, DB::table('assessment_events')->where('action', 'publish')->count());
        $this->assertDatabaseCount('grade_appeals', 1);
        $this->assertDatabaseHas('grade_appeals', ['status' => 'accepted']);
        $this->assertDatabaseHas('assessments', ['id' => $r->id, 'published_version' => 1, 'revision' => 6]);
        $this->race($f, $f['mentor']->id, 'assessment-save', $f['p']->ulid, ['assessment_ulid' => $r->ulid, 'template_id' => $template, 'title' => 'Responsi pertama', 'date' => '2026-10-02', 'mode' => 'dynamic',
            'revision' => 6, 'deidentified' => 1, 'author_assignment_id' => $a->id, 'mentor_assignment_id' => $a->id, 'scores' => [$component => 90]]);
        foreach (['approve', 'publish'] as $i => $action) {
            $this->race($f, $f['mentor']->id, 'assessment-transition', $r->ulid, ['action' => $action, 'revision' => $i + 7, 'confirm' => 1, 'note' => 'Pengesahan koreksi concurrency']);
        }
        $this->assertDatabaseCount('assessment_versions', 2);
        $this->assertDatabaseHas('grade_appeals', ['status' => 'completed', 'version' => 1, 'corrected_version' => 2]);
        $this->assertDatabaseHas('assessments', ['id' => $r->id, 'published_version' => 2, 'revision' => 9]);
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
