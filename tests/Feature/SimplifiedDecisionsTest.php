<?php

namespace Tests\Feature;

use App\Services\AssessmentService;
use App\Services\AttendanceService;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompletionFixtures;
use Tests\TestCase;

/**
 * Approving is one click and is still attributed and audited; refusing must always be explained.
 */
class SimplifiedDecisionsTest extends TestCase
{
    use CompletionFixtures, RefreshDatabase;

    public function test_assignment_and_attendance_are_approved_without_typing_a_reason_but_rejection_needs_one(): void
    {
        $f = $this->schedulingFixture();
        $p = $f['p'];
        $base = ['educator_id' => $f['educator'], 'start_date' => $p->start_date, 'end_date' => $p->end_date];

        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$p->ulid.'/penugasan', $base + ['role' => 'mentor'])->assertRedirect()->assertSessionHasNoErrors();
        $mentor = DB::table('educator_assignments')->where('role', 'mentor')->first();
        $this->assertSame('Penugasan pembimbing', $mentor->reason);
        $this->post('/penjadwalan/penempatan/'.$p->ulid.'/penugasan', $base + ['role' => 'examiner', 'replaces_id' => $mentor->id])->assertSessionHasErrors('reason');

        $this->actingAs($f['chief'])->post('/penjadwalan/penugasan/'.$mentor->ulid.'/keputusan', ['action' => 'reject', 'revision' => 1])->assertSessionHasErrors('reason');
        $this->post('/penjadwalan/penugasan/'.$mentor->ulid.'/keputusan', ['action' => 'approve', 'revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('educator_assignments', ['id' => $mentor->id, 'status' => 'approved', 'approved_by' => $f['chief']->id]);

        $s = app(ScheduleService::class)->save($f['owner'], $p->ulid, ['date' => '2026-10-02', 'activity' => 'Kegiatan pendidikan', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $mentor->id, 'revision' => 0, 'change_kind' => 'schedule']);
        foreach (['submit' => 'owner', 'approve' => 'mentor', 'publish' => 'owner'] as $action => $actor) {
            app(ScheduleService::class)->transition($f[$actor], $s->ulid, ['action' => $action, 'revision' => DB::table('schedules')->where('id', $s->id)->value('revision')]);
        }
        $this->travelTo(now()->setDate(2026, 10, 3));
        app(AttendanceService::class)->save($f['owner'], $p->ulid, ['date' => '2026-10-02', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $mentor->id, 'attendance_status' => 'hadir', 'activity' => 'Kegiatan pendidikan', 'revision' => 0, 'action' => 'submit']);
        $attendance = DB::table('attendances')->first();

        $this->actingAs($f['mentor'])->post('/presensi/'.$attendance->ulid.'/verifikasi', ['action' => 'reject', 'revision' => 1])->assertSessionHasErrors('reason');
        $this->post('/presensi/'.$attendance->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $verified = DB::table('attendances')->find($attendance->id);
        $this->assertSame('verified', $verified->status);
        $this->assertSame($f['mentor']->id, (int) $verified->verified_by);
        $this->assertNotNull($verified->approval);
        $this->assertDatabaseHas('audit_logs', ['event' => 'scheduling.attendance_verify', 'user_id' => $f['mentor']->id]);
    }

    public function test_grade_is_released_in_one_click_and_completion_is_decided_without_a_typed_reason(): void
    {
        $f = $this->completionFixture();
        $p = DB::table('placements')->find($f['p']->id);

        $grade = app(AssessmentService::class)->save($f['mentor'], $p->ulid, ['template_id' => DB::table('assessment_templates')->value('id'), 'title' => 'Responsi kedua', 'date' => '2026-10-02', 'mode' => 'dynamic', 'revision' => 0, 'deidentified' => 1,
            'author_assignment_id' => $f['a']->id, 'mentor_assignment_id' => $f['a']->id, 'scores' => [DB::table('assessment_components')->value('id') => 90]], null);
        $this->actingAs($f['mentor'])->post('/penilaian/'.$grade->ulid.'/status', ['action' => 'release', 'revision' => 1, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('assessments', ['id' => $grade->id, 'status' => 'published', 'published_version' => 1]);
        $this->assertSame(['approve', 'publish'], DB::table('assessment_events')->where('assessment_id', $grade->id)->whereNotNull('approval')->orderBy('id')->pluck('action')->all());
        $this->actingAs($f['owner'])->get('/penilaian/'.$grade->ulid)->assertOk()->assertSee('90');

        $this->actingAs($f['admin'])->post('/penyelesaian/'.$p->ulid.'/status', ['action' => 'submit', 'revision' => $p->revision, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $request = DB::table('completion_requests')->first();
        $p = DB::table('placements')->find($p->id);
        $this->assertSame('menunggu_penyelesaian', $p->status);
        $decision = ['revision' => $p->revision, 'request_id' => $request->id, 'request_revision' => $request->revision, 'confirm' => 1];

        $this->actingAs($f['kordik'])->post('/penyelesaian/'.$p->ulid.'/status', $decision + ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->post('/penyelesaian/'.$p->ulid.'/status', $decision + ['action' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placements', ['id' => $p->id, 'status' => 'selesai']);
        $this->assertDatabaseHas('completion_requests', ['id' => $request->id, 'status' => 'completed', 'decided_by' => $f['kordik']->id]);
        $this->actingAs($f['owner'])->get('/penyelesaian/'.$p->ulid)->assertOk()->assertSee('Penempatan dikunci')->assertSee('SL-');
    }
}
