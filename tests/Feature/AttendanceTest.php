<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use App\Services\EducatorAssignmentService;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $f;

    private object $assignment;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->schedulingFixture();
        $f = $this->f;
        $this->assignment = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan resmi untuk presensi']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $this->assignment->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan penugasan presensi']);
        $s = app(ScheduleService::class)->save($f['owner'], $f['p']->ulid, ['date' => '2026-10-02', 'activity' => 'Kegiatan pendidikan', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $this->assignment->id, 'revision' => 0, 'change_kind' => 'schedule']);
        app(ScheduleService::class)->transition($f['owner'], $s->ulid, ['action' => 'submit', 'revision' => 1]);
        app(ScheduleService::class)->transition($f['mentor'], $s->ulid, ['action' => 'approve', 'revision' => 2]);
        app(ScheduleService::class)->transition($f['owner'], $s->ulid, ['action' => 'publish', 'revision' => 3]);
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(12, 0));
        $this->url = '/presensi/penempatan/'.$f['p']->ulid;
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['date' => '2026-10-02', 'clinical_location_id' => $this->f['location'], 'mentor_assignment_id' => $this->assignment->id,
            'attendance_status' => 'hadir', 'activity' => 'Praktik klinis tanpa identitas pasien', 'revision' => 0, 'action' => 'submit'];
    }

    private function submit(): object
    {
        $this->actingAs($this->f['owner'])->post($this->url, $this->payload())->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('attendances')->first();
    }

    private function verify(object $r): void
    {
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => $r->revision, 'reason' => 'Kehadiran dan kegiatan sesuai'])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function seal(): object
    {
        $this->travelTo(now()->setDate(2026, 10, 11));
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('attendance_summaries')->first();
    }

    public function test_daily_attendance_verification_sealing_and_private_report(): void
    {
        $r = $this->submit();
        $this->assertSame('waiting', $r->status);
        $this->assertSame(0, app(AttendanceService::class)->snapshot($this->f['p'])['counts']['tidak_hadir']);
        $this->verify($r);
        $s = $this->seal();
        $this->assertSame('sealed', $s->status);
        foreach (['admin', 'chief', 'mentor', 'owner', 'kordik'] as $role) {
            $this->actingAs($this->f[$role])->get('/presensi')->assertOk()->assertSee('Peserta Pengujian');
            $this->get($this->url)->assertOk()->assertSee('Rekap disahkan dan dikunci');
            $this->get('/presensi/laporan/'.$s->ulid)->assertOk()->assertSee('DISAHKAN KETUA KSM')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        }
        $other = $this->createUserWithRole('peserta');
        $this->actingAs($other)->get('/presensi')->assertOk()->assertDontSee('Peserta Pengujian');
        $this->get($this->url)->assertNotFound();
        $this->get('/presensi/laporan/'.$s->ulid)->assertNotFound();
        $this->post($this->url, $this->payload())->assertForbidden();
        $this->assertDatabaseHas('scheduling_histories', ['event' => 'attendance_summary_sealed', 'resource_id' => $s->id]);
        if (getenv('SIKORDIK_UI_EXPORT') === '1') {
            $dir = storage_path('framework/testing/phase4-ui');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            foreach (['index' => '/presensi', 'show' => $this->url, 'report' => '/presensi/laporan/'.$s->ulid] as $name => $url) {
                $html = $this->actingAs($this->f['admin'])->get($url)->assertOk()->getContent();
                $html = preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $html);
                file_put_contents($dir.'/'.$name.'.html', str_replace('http://localhost/build/', '/build/', $html));
            }
        }
    }

    public function test_duplicates_stale_versions_invalid_dates_locations_and_assignments_are_rejected(): void
    {
        foreach ([['date' => '2026-10-03'], ['date' => '2026-09-30'], ['date' => '2026-10-01'], ['mentor_assignment_id' => 99999], ['clinical_location_id' => 99999], ['revision' => 5]] as $extra) {
            $this->actingAs($this->f['owner'])->post($this->url, $this->payload($extra))->assertUnprocessable();
        }
        $r = $this->submit();
        $this->post($this->url, $this->payload())->assertUnprocessable();
        $this->post($this->url, $this->payload(['revision' => 1]))->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 1);
        $this->verify($r);
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 1, 'reason' => 'Keputusan stale harus ditolak'])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_draft_rejection_and_resubmission_keep_history(): void
    {
        $this->actingAs($this->f['owner'])->post($this->url, $this->payload(['action' => 'draft']))->assertRedirect();
        $this->post($this->url, $this->payload(['revision' => 1]))->assertRedirect();
        $r = DB::table('attendances')->first();
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'reject', 'revision' => 2, 'reason' => 'Ringkasan kegiatan perlu diperbaiki'])->assertRedirect();
        $this->actingAs($this->f['owner'])->post($this->url, $this->payload(['revision' => 3, 'attendance_status' => 'izin']))->assertRedirect();
        $this->verify(DB::table('attendances')->first());
        $this->assertDatabaseHas('attendances', ['status' => 'verified', 'revision' => 5]);
        $this->assertSame(1, app(AttendanceService::class)->snapshot($this->f['p'])['counts']['izin']);
        $this->assertSame(5, DB::table('scheduling_histories')->where('resource_type', 'attendances')->count());
    }

    public function test_only_assigned_mentor_and_scoped_chief_can_decide(): void
    {
        $r = $this->submit();
        $outsider = $this->createUserWithRole('pembimbing');
        foreach ([$outsider, $this->f['owner'], $this->f['admin'], $this->f['chief']] as $user) {
            $this->actingAs($user)->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 1, 'reason' => 'Percobaan akses di luar penugasan'])->assertForbidden();
        }
        $this->verify($r);
        $this->travelTo(now()->setDate(2026, 10, 11));
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 0])->assertRedirect();
        foreach ([$this->f['admin'], $this->f['mentor'], $this->createUserWithRole('ketua-ksm'), $this->createUserWithRole('super-admin')] as $user) {
            $this->actingAs($user)->post($this->url.'/rekap', ['action' => 'approve', 'version' => 1])->assertForbidden();
        }
    }

    public function test_missing_pending_and_stale_summary_cannot_be_sealed(): void
    {
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 0])->assertUnprocessable();
        $this->travelTo(now()->setDate(2026, 10, 11));
        $this->post($this->url.'/rekap', ['action' => 'generate', 'version' => 0])->assertRedirect();
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 1])->assertUnprocessable();
        $r = $this->submit();
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 1])->assertRedirect();
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 2])->assertUnprocessable();
        $this->verify($r);
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 2])->assertUnprocessable();
        $this->assertSame(0, DB::table('attendance_summaries')->where('status', 'sealed')->count());
    }

    public function test_sealed_correction_preserves_snapshot_and_requires_reverification_and_resealing(): void
    {
        $r = $this->submit();
        $this->verify($r);
        $s = $this->seal();
        $this->actingAs($this->f['owner'])->post($this->url, $this->payload(['revision' => 2]))->assertUnprocessable();
        $this->post($this->url, $this->payload(['revision' => 2, 'action' => 'correct', 'reason' => 'Koreksi oleh peserta ditolak']))->assertForbidden();
        $this->actingAs($this->f['admin'])->post($this->url, $this->payload(['revision' => 2, 'action' => 'correct']))->assertUnprocessable();
        $this->post($this->url, $this->payload(['revision' => 2, 'action' => 'correct', 'attendance_status' => 'terlambat', 'reason' => 'Koreksi sesuai catatan kehadiran resmi']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_summaries', ['id' => $s->id, 'status' => 'superseded', 'approved_by' => $this->f['chief']->id]);
        $this->assertSame(json_decode($s->snapshot, true), json_decode(DB::table('attendance_summaries')->where('id', $s->id)->value('snapshot'), true));
        $this->assertDatabaseHas('attendances', ['id' => $r->id, 'status' => 'corrected', 'verified_by' => null]);
        $this->get('/presensi/laporan/'.$s->ulid)->assertOk()->assertSee('TIDAK BERLAKU');
        $this->verify(DB::table('attendances')->first());
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 1])->assertRedirect();
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 2])->assertRedirect();
        $this->assertSame(1, DB::table('attendance_summaries')->where('status', 'sealed')->count());
    }

    public function test_reminders_are_idempotent_and_stop_after_verification(): void
    {
        $r = $this->submit();
        $service = app(AttendanceService::class);
        $this->assertSame(1, $service->remind());
        $this->assertSame(0, $service->remind());
        $this->travel(1)->days();
        $this->assertSame(1, $service->remind());
        $this->assertDatabaseHas('attendances', ['status' => 'waiting', 'attendance_status' => 'hadir']);
        $this->verify($r);
        $this->travel(1)->days();
        $this->assertSame(0, $service->remind());
        $this->artisan('sikordik:remind-attendance')->assertSuccessful();
    }

    public function test_official_substitute_keeps_original_assignment_and_revokes_previous_verifier(): void
    {
        $r = $this->submit();
        $newMentor = $this->createUserWithRole('pembimbing');
        $educator = DB::table('educators')->insertGetId(['user_id' => $newMentor->id, 'department_id' => $this->f['department'], 'name' => 'Pembimbing Pengganti', 'can_mentor' => true]);
        DB::table('educator_licenses')->insert(['educator_id' => $educator, 'license_type' => 'Pendidikan', 'license_number' => 'TEST-002', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $a = app(EducatorAssignmentService::class)->request($this->f['admin'], $this->f['p']->ulid, ['educator_id' => $educator, 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan pembimbing tambahan resmi']);
        app(EducatorAssignmentService::class)->decide($this->f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Penugasan disetujui Ketua KSM']);
        $this->actingAs($this->f['admin'])->post('/presensi/'.$r->ulid.'/pengganti', ['mentor_assignment_id' => $a->id, 'revision' => 1, 'reason' => 'Pembimbing awal berhalangan hadir'])->assertRedirect();
        $this->assertDatabaseHas('attendances', ['original_assignment_id' => $this->assignment->id, 'mentor_assignment_id' => $a->id, 'status' => 'corrected']);
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 2, 'reason' => 'Verifikator lama tidak berwenang'])->assertForbidden();
        $this->actingAs($newMentor)->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 2, 'reason' => 'Kegiatan sudah diperiksa pengganti'])->assertRedirect();
    }

    public function test_schedule_cannot_remove_attended_day_and_sealed_calendar_cannot_be_extended(): void
    {
        $r = $this->submit();
        $s = DB::table('schedules')->first();
        $this->actingAs($this->f['owner'])->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal', ['date' => $s->date, 'activity' => 'Pembatalan kegiatan', 'clinical_location_id' => $this->f['location'], 'mentor_assignment_id' => $this->assignment->id, 'revision' => 0, 'change_kind' => 'cancel', 'replaces_id' => $s->id, 'reason' => 'Pembatalan setelah presensi masuk'])->assertUnprocessable();
        $this->verify($r);
        $this->seal();
        $this->actingAs($this->f['admin'])->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/perpanjangan', ['new_end_date' => '2026-10-20', 'revision' => 1, 'reason' => 'Perpanjangan setelah rekap terkunci'])->assertUnprocessable();
    }

    public function test_rejected_post_seal_correction_stays_admin_only(): void
    {
        $r = $this->submit();
        $this->verify($r);
        $this->seal();
        $this->actingAs($this->f['admin'])->post($this->url, $this->payload(['revision' => 2, 'action' => 'correct', 'reason' => 'Perbaikan presensi setelah pengesahan']))->assertRedirect();
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'reject', 'revision' => 3, 'reason' => 'Koreksi administratif belum sesuai'])->assertRedirect();
        $this->actingAs($this->f['owner'])->post($this->url, $this->payload(['revision' => 4]))->assertUnprocessable();
        $this->assertDatabaseHas('attendances', ['id' => $r->id, 'admin_only' => true, 'status' => 'rejected']);
    }

    public function test_inactive_or_relinked_mentor_cannot_verify_and_reminder_escalates_to_admin(): void
    {
        $r = $this->submit();
        $newUser = $this->createUserWithRole('pembimbing');
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => $newUser->id]);
        foreach ([$this->f['mentor'], $newUser] as $u) {
            $this->actingAs($u)->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 1, 'reason' => 'Akun berubah tidak memindahkan akses'])->assertForbidden();
        }
        $this->assertSame(1, app(AttendanceService::class)->remind());
        $this->assertDatabaseHas('attendance_reminders', ['attendance_id' => $r->id, 'user_id' => $this->f['admin']->id]);
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => $this->f['mentor']->id]);
        $this->f['mentor']->update(['is_active' => false]);
        $this->actingAs($this->f['mentor'])->post('/presensi/'.$r->ulid.'/verifikasi', ['action' => 'verify', 'revision' => 1, 'reason' => 'Akun nonaktif harus ditolak'])->assertRedirect('/login');
    }

    public function test_calendar_change_after_draft_recap_requires_regeneration(): void
    {
        $this->verify($this->submit());
        $this->travelTo(now()->setDate(2026, 10, 11));
        $this->actingAs($this->f['admin'])->post($this->url.'/rekap', ['action' => 'generate', 'version' => 0])->assertRedirect();
        // Simulate a period update from the separately tested extension workflow.
        DB::table('placements')->where('id', $this->f['p']->id)->update(['end_date' => '2026-10-09']);
        $this->actingAs($this->f['chief'])->post($this->url.'/rekap', ['action' => 'approve', 'version' => 1])->assertUnprocessable();
    }
}
