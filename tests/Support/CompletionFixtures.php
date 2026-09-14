<?php

namespace Tests\Support;

use App\Services\AssessmentService;
use App\Services\AssessmentTemplateService;
use App\Services\AttendanceService;
use App\Services\EducatorAssignmentService;
use App\Services\FileInspector;
use App\Services\LogbookService;
use App\Services\MalwareScanner;
use App\Services\ScheduleService;
use App\Services\SurveyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CompletionFixtures
{
    use SchedulingFixtures;

    protected function completionFixture(): array
    {
        Storage::fake('local');
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('clean');
        $this->mock(FileInspector::class)->shouldReceive('inspect')->andReturn(true);
        $f = $this->schedulingFixture();
        $p = $f['p'];
        $a = app(EducatorAssignmentService::class)->request($f['admin'], $p->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => $p->start_date, 'end_date' => $p->end_date, 'reason' => 'Penugasan resmi penyelesaian']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan penugasan resmi']);
        $s = app(ScheduleService::class)->save($f['owner'], $p->ulid, ['date' => '2026-10-02', 'activity' => 'Kegiatan pendidikan', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule']);
        foreach (['submit' => 'owner', 'approve' => 'mentor', 'publish' => 'owner'] as $action => $actor) {
            app(ScheduleService::class)->transition($f[$actor], $s->ulid, ['action' => $action, 'revision' => DB::table('schedules')->where('id', $s->id)->value('revision')]);
        }
        DB::table('placements')->where('id', $p->id)->update(['status' => 'sedang_stase']);
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        app(AttendanceService::class)->save($f['owner'], $p->ulid, ['date' => '2026-10-02', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'attendance_status' => 'hadir', 'activity' => 'Kegiatan pendidikan', 'revision' => 0, 'action' => 'submit']);
        $attendance = DB::table('attendances')->first();
        app(AttendanceService::class)->decide($f['mentor'], $attendance->ulid, ['action' => 'verify', 'revision' => 1, 'reason' => 'Kehadiran telah diperiksa']);
        app(AttendanceService::class)->summary($f['admin'], $p->ulid, ['action' => 'generate', 'version' => 0]);
        app(AttendanceService::class)->summary($f['chief'], $p->ulid, ['action' => 'approve', 'version' => 1]);
        $book = app(LogbookService::class)->save($f['owner'], $p->ulid, ['kind' => 'participant', 'type' => 'Logbook institusi', 'revision' => 0, 'deidentified' => 1, 'reviewer_assignment_id' => $a->id], UploadedFile::fake()->createWithContent('logbook.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF"));
        app(LogbookService::class)->transition($f['owner'], $book->ulid, ['action' => 'submit', 'revision' => 1, 'confirm' => 1, 'note' => 'Pengajuan logbook pendidikan']);
        app(LogbookService::class)->transition($f['mentor'], $book->ulid, ['action' => 'approve', 'revision' => 2, 'confirm' => 1, 'note' => 'Pengesahan logbook pendidikan']);
        $template = app(AssessmentTemplateService::class)->create($f['admin'], ['name' => 'Penilaian akhir', 'exam_type' => 'Responsi', 'calculation' => 'none', 'components' => [['name' => 'Skor', 'input_type' => 'number', 'minimum' => 0, 'maximum' => 100, 'required' => 1]]]);
        $grade = app(AssessmentService::class)->save($f['mentor'], $p->ulid, ['template_id' => $template, 'title' => 'Responsi akhir', 'date' => '2026-10-02', 'mode' => 'dynamic', 'revision' => 0, 'deidentified' => 1, 'author_assignment_id' => $a->id, 'mentor_assignment_id' => $a->id, 'scores' => [DB::table('assessment_components')->value('id') => 80]], null);
        app(AssessmentService::class)->transition($f['mentor'], $grade->ulid, ['action' => 'approve', 'revision' => 1, 'confirm' => 1, 'note' => 'Pengesahan nilai pendidikan']);
        app(AssessmentService::class)->transition($f['mentor'], $grade->ulid, ['action' => 'publish', 'revision' => 2, 'confirm' => 1, 'note' => 'Publikasi nilai pendidikan']);
        foreach (SurveyService::KINDS as $kind => $label) {
            app(SurveyService::class)->configure($f['admin'], ['kind' => $kind, 'name' => $label, 'url' => 'https://forms.gle/TestForm123', 'confirm' => 1]);
            foreach (['start' => 'owner', 'submit' => 'owner', 'verify' => 'admin'] as $action => $actor) {
                $revision = DB::table('survey_responses')->where('kind', $kind)->value('revision') ?? 0;
                app(SurveyService::class)->act($f[$actor], $p->ulid, ['action' => $action, 'kind' => $kind, 'revision' => $revision, 'confirm' => 1]);
            }
        }

        return $f + compact('a', 'attendance', 'book', 'grade');
    }
}
