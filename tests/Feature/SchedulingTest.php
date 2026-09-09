<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->schedulingFixture();
    }

    private function assignment(array $extra = [], bool $approve = true): object
    {
        $f = $this->f;
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/penugasan', $extra + ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan pendidik untuk pengujian'])->assertRedirect()->assertSessionHasNoErrors();
        $a = DB::table('educator_assignments')->orderByDesc('id')->first();
        if ($approve) {
            $this->actingAs($f['chief'])->post('/penjadwalan/penugasan/'.$a->ulid.'/keputusan', ['action' => 'approve', 'revision' => 1, 'reason' => 'Penugasan telah diperiksa KSM'])->assertRedirect()->assertSessionHasNoErrors();
        }

        return DB::table('educator_assignments')->find($a->id);
    }

    private function payload(int $assignment, array $extra = []): array
    {
        return $extra + ['date' => '2026-10-02', 'start_time' => '08:00', 'end_time' => '10:00', 'activity' => 'Bimbingan klinis', 'clinical_location_id' => $this->f['location'], 'mentor_assignment_id' => $assignment, 'revision' => 0, 'change_kind' => 'schedule'];
    }

    private function schedule(int $assignment, array $extra = []): object
    {
        $this->actingAs($this->f['owner'])->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal', $this->payload($assignment, $extra))->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('schedules')->orderByDesc('id')->first();
    }

    private function action(object $s, string $action, ?User $user = null, ?int $revision = null)
    {
        return $this->actingAs($user ?? $this->f['owner'])->post('/penjadwalan/jadwal/'.$s->ulid.'/status', ['action' => $action, 'revision' => $revision ?? DB::table('schedules')->where('id', $s->id)->value('revision'), 'reason' => 'Keputusan untuk pengujian jadwal']);
    }

    private function publish(object $s): void
    {
        $this->action($s, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->action($s, 'approve', $this->f['mentor'])->assertRedirect()->assertSessionHasNoErrors();
        $this->action($s, 'publish')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_assignment_schedule_publication_and_scoped_pages(): void
    {
        $a = $this->assignment();
        $this->assignment(['role' => 'examiner']);
        $s = $this->schedule($a->id);
        $this->publish($s);
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'dijadwalkan']);
        $this->assertDatabaseHas('scheduling_histories', ['resource_id' => $s->id, 'event' => 'schedule_publish']);
        foreach (['admin', 'chief', 'mentor', 'owner', 'kordik'] as $role) {
            $this->actingAs($this->f[$role])->get('/penjadwalan')->assertOk()->assertSee('Peserta Pengujian');
            $this->get('/penjadwalan/penempatan/'.$this->f['p']->ulid)->assertOk()->assertSee('Bimbingan klinis');
            $this->get('/penjadwalan/notifikasi')->assertOk();
        }
        $this->actingAs($this->f['admin'])->get('/penjadwalan/lisensi')->assertOk();
        $this->actingAs($this->f['owner'])->get('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal/'.$s->ulid)->assertOk();
        if (getenv('SIKORDIK_UI_EXPORT') === '1') {
            $directory = storage_path('framework/testing/phase3-ui');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            foreach (['index' => '/penjadwalan', 'show' => '/penjadwalan/penempatan/'.$this->f['p']->ulid,
                'form' => '/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal/'.$s->ulid, 'licenses' => '/penjadwalan/lisensi', 'notifications' => '/penjadwalan/notifikasi'] as $name => $url) {
                $response = $this->actingAs($this->f['admin'])->get($url)->assertOk();
                $html = preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $response->getContent());
                $html = str_replace('http://localhost/build/', '/build/', $html);
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
        }
    }

    public function test_educator_requires_assignment_scope_role_and_valid_license(): void
    {
        $f = $this->f;
        $this->actingAs($f['mentor'])->get('/penjadwalan/penempatan/'.$f['p']->ulid)->assertNotFound();
        $license = DB::table('educator_licenses')->first();
        $data = ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan pengujian'];
        DB::table('educator_licenses')->where('id', $license->id)->update(['expires_at' => '2026-10-05']);
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/penugasan', $data)->assertSessionHasErrors('educator_id');
        DB::table('educator_licenses')->where('id', $license->id)->update(['expires_at' => '2027-10-05']);
        $this->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/penugasan', ['role' => 'supervisor'] + $data)->assertSessionHasErrors('educator_id');
        $a = $this->assignment([], false);
        $otherChief = $this->createUserWithRole('ketua-ksm');
        $this->actingAs($otherChief)->post('/penjadwalan/penugasan/'.$a->ulid.'/keputusan', ['action' => 'approve', 'revision' => 1, 'reason' => 'Tidak memiliki scope KSM'])->assertForbidden();
        $this->assertDatabaseHas('educator_assignments', ['id' => $a->id, 'status' => 'pending']);
    }

    public function test_replacement_keeps_previous_assignment_and_requires_reason_and_chief(): void
    {
        $a = $this->assignment();
        $newMentor = $this->createUserWithRole('pembimbing');
        $e = DB::table('educators')->insertGetId(['user_id' => $newMentor->id, 'department_id' => $this->f['department'], 'name' => 'Pengganti', 'can_mentor' => true]);
        DB::table('educator_licenses')->insert(['educator_id' => $e, 'license_type' => 'Otorisasi', 'license_number' => 'TEST-002', 'issued_at' => '2026-01-01']);
        $replacement = $this->assignment(['educator_id' => $e, 'replaces_id' => $a->id]);
        $this->assertDatabaseHas('educator_assignments', ['id' => $a->id, 'status' => 'replaced']);
        $this->assertDatabaseHas('educator_assignments', ['id' => $replacement->id, 'approved_by' => $this->f['chief']->id, 'requested_by' => $this->f['admin']->id]);
        $this->assertDatabaseHas('scheduling_histories', ['resource_id' => $a->id, 'event' => 'assignment_replaced']);
        $this->actingAs($this->f['mentor'])->get('/penjadwalan/penempatan/'.$this->f['p']->ulid)->assertNotFound();
    }

    public function test_invalid_dates_location_and_unassigned_mentor_are_rejected(): void
    {
        $a = $this->assignment();
        $url = '/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal';
        $this->actingAs($this->f['owner'])->post($url, $this->payload($a->id, ['date' => '2026-10-11']))->assertUnprocessable();
        $this->post($url, $this->payload($a->id, ['end_time' => '07:00']))->assertSessionHasErrors('end_time');
        $this->post($url, $this->payload($a->id, ['start_time' => null]))->assertSessionHasErrors('start_time');
        $this->post($url, $this->payload(999))->assertUnprocessable();
        $d = DB::table('departments')->insertGetId(['code' => 'OTHER', 'name' => 'KSM Lain']);
        $l = DB::table('clinical_locations')->insertGetId(['department_id' => $d, 'code' => 'OTHER', 'name' => 'Lokasi Lain']);
        $this->post($url, $this->payload($a->id, ['clinical_location_id' => $l]))->assertUnprocessable();
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_schedule_approval_is_not_role_only_and_stale_versions_fail(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->action($s, 'publish')->assertUnprocessable();
        $this->action($s, 'submit')->assertRedirect();
        $outsider = $this->createUserWithRole('pembimbing');
        $this->action($s, 'approve', $outsider)->assertNotFound();
        $this->action($s, 'approve', $this->f['admin'])->assertForbidden();
        $this->action($s, 'approve', $this->f['mentor'], 1)->assertUnprocessable();
        $this->action($s, 'revise', $this->f['mentor'])->assertRedirect();
        $fresh = DB::table('schedules')->find($s->id);
        $this->actingAs($this->f['owner'])->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal/'.$s->ulid, $this->payload($a->id, ['revision' => $fresh->revision, 'activity' => 'Kegiatan revisi']))->assertRedirect()->assertSessionHasNoErrors();
        $this->publish($s);
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'activity' => 'Kegiatan revisi', 'status' => 'published']);
    }

    public function test_published_schedule_changes_are_versioned_and_cancellation_needs_approval(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->publish($s);
        $this->actingAs($this->f['owner'])->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal/'.$s->ulid, $this->payload($a->id, ['revision' => 4]))->assertUnprocessable();
        $replacement = $this->schedule($a->id, ['replaces_id' => $s->id, 'date' => '2026-10-03', 'reason' => 'Perubahan setelah koordinasi pembimbing']);
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'published', 'date' => '2026-10-02']);
        $this->publish($replacement);
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'superseded', 'date' => '2026-10-02']);
        $cancel = $this->schedule($a->id, ['replaces_id' => $replacement->id, 'change_kind' => 'cancel', 'reason' => 'Pembatalan kegiatan telah dibahas']);
        $this->publish($cancel);
        $this->assertDatabaseHas('schedules', ['id' => $replacement->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('schedules', 3);
    }

    public function test_conflicting_hours_full_day_and_adjacent_time_boundary(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->publish($s);
        $overlap = $this->schedule($a->id, ['start_time' => '09:00', 'end_time' => '11:00']);
        $this->action($overlap, 'submit')->assertUnprocessable();
        $full = $this->schedule($a->id, ['start_time' => null, 'end_time' => null]);
        $this->action($full, 'submit')->assertUnprocessable();
        $adjacent = $this->schedule($a->id, ['start_time' => '10:00', 'end_time' => '12:00']);
        $this->publish($adjacent);
        $this->assertSame(2, DB::table('schedules')->where('status', 'published')->count());
    }

    public function test_publication_rechecks_documents_and_educator_license(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->action($s, 'submit')->assertRedirect();
        $this->action($s, 'approve', $this->f['mentor'])->assertRedirect();
        DB::table('placement_documents')->where('placement_id', $this->f['p']->id)->update(['status' => 'pending']);
        $this->action($s, 'publish')->assertSessionHasErrors('placement');
        DB::table('placement_documents')->where('placement_id', $this->f['p']->id)->update(['status' => 'exception']);
        DB::table('educator_licenses')->update(['expires_at' => '2026-09-30']);
        $this->action($s, 'publish')->assertSessionHasErrors('educator_id');
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'approved']);
    }

    public function test_group_membership_transfer_preserves_history_and_rejects_overlap(): void
    {
        $f = $this->f;
        $url = '/penjadwalan/penempatan/'.$f['p']->ulid.'/kelompok';
        foreach (['Kelompok A', 'Kelompok B'] as $name) {
            $this->actingAs($f['admin'])->post('/penjadwalan/kelompok', ['department_id' => $f['department'], 'name' => $name])->assertRedirect();
        }
        $groups = DB::table('clinical_groups')->orderBy('id')->get();
        $data = ['clinical_group_id' => $groups[0]->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penempatan pada kelompok awal'];
        $this->post($url, $data)->assertRedirect();
        $this->post($url, ['clinical_group_id' => $groups[1]->id] + $data)->assertUnprocessable();
        $m = DB::table('group_memberships')->first();
        $this->post('/penjadwalan/keanggotaan/'.$m->id.'/akhir', ['expected_end_date' => '2026-10-10', 'end_date' => '2026-10-05', 'reason' => 'Pindah kelompok sesuai kebutuhan'])->assertRedirect();
        $this->post($url, ['clinical_group_id' => $groups[1]->id, 'start_date' => '2026-10-06'] + $data)->assertRedirect();
        $this->assertDatabaseCount('group_memberships', 2);
        $a = $this->assignment();
        $this->schedule($a->id, ['clinical_group_id' => $groups[0]->id]);
        $this->actingAs($f['owner'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/jadwal', $this->payload($a->id, ['clinical_group_id' => $groups[1]->id]))->assertUnprocessable();
    }

    public function test_extension_two_approvals_and_no_automatic_assignment_extension(): void
    {
        $a = $this->assignment();
        $f = $this->f;
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/perpanjangan', ['new_end_date' => '2026-10-15', 'revision' => 1, 'reason' => 'Perpanjangan untuk kebutuhan pendidikan'])->assertRedirect();
        $e = DB::table('placement_extensions')->first();
        $url = '/penjadwalan/perpanjangan/'.$e->ulid.'/keputusan';
        $this->actingAs($f['kordik'])->post($url, ['action' => 'approve', 'revision' => 1, 'reason' => 'Keputusan Tim Kordik'])->assertForbidden();
        $this->actingAs($f['chief'])->post($url, ['action' => 'approve', 'revision' => 1, 'reason' => 'KSM tersedia untuk perpanjangan'])->assertRedirect();
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'end_date' => '2026-10-10']);
        $this->actingAs($f['kordik'])->post($url, ['action' => 'approve', 'revision' => 2, 'reason' => 'Perpanjangan disetujui Tim Kordik'])->assertRedirect();
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'end_date' => '2026-10-15', 'revision' => 2]);
        $this->assertDatabaseHas('educator_assignments', ['id' => $a->id, 'end_date' => '2026-10-10']);
        $this->actingAs($f['owner'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/jadwal', $this->payload($a->id, ['date' => '2026-10-11']))->assertUnprocessable();
    }

    public function test_extension_rechecks_period_conflicts_and_leaves_original_untouched(): void
    {
        $f = $this->f;
        $other = (array) $f['p'];
        unset($other['id']);
        $other['ulid'] = (string) Str::ulid();
        $other['start_date'] = '2026-10-11';
        $other['end_date'] = '2026-10-20';
        DB::table('placements')->insert($other);
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/perpanjangan', ['new_end_date' => '2026-10-15', 'revision' => 1, 'reason' => 'Permohonan yang berbenturan'])->assertRedirect();
        $e = DB::table('placement_extensions')->first();
        $this->actingAs($f['chief'])->post('/penjadwalan/perpanjangan/'.$e->ulid.'/keputusan', ['action' => 'approve', 'revision' => 1, 'reason' => 'Periksa periode perpanjangan'])->assertUnprocessable();
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'end_date' => '2026-10-10']);
    }

    public function test_participant_ksm_notification_and_license_idor_are_denied(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->publish($s);
        $outsider = $this->createUserWithRole('peserta');
        $this->actingAs($outsider)->get('/penjadwalan')->assertOk()->assertDontSee('Peserta Pengujian');
        $this->get('/penjadwalan/penempatan/'.$this->f['p']->ulid)->assertNotFound();
        $this->get('/penjadwalan/lisensi')->assertForbidden();
        $notification = DB::table('scheduling_notifications')->where('user_id', $this->f['owner']->id)->first();
        $this->post('/penjadwalan/notifikasi/'.$notification->ulid)->assertNotFound();
        $this->actingAs($this->f['owner'])->post('/penjadwalan/notifikasi/'.$notification->ulid)->assertRedirect();
        $this->assertNotNull(DB::table('scheduling_notifications')->find($notification->id)->read_at);
        $this->actingAs($outsider)->post('/penjadwalan/penempatan/'.$this->f['p']->ulid.'/jadwal', $this->payload($a->id))->assertForbidden();
    }

    public function test_license_edit_is_versioned_and_closure_blocks_schedule_mutations(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $l = DB::table('educator_licenses')->first();
        $data = ['id' => $l->id, 'educator_id' => $l->educator_id, 'license_type' => $l->license_type, 'license_number' => $l->license_number, 'issued_at' => '2026-01-01', 'expires_at' => '2028-12-31', 'is_active' => 1, 'revision' => 1, 'reason' => 'Perpanjangan kredensial pendidik'];
        $this->actingAs($this->f['admin'])->post('/penjadwalan/lisensi', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/penjadwalan/lisensi', $data)->assertUnprocessable();
        $this->assertDatabaseHas('scheduling_histories', ['resource_type' => 'educator_licenses', 'event' => 'license_saved']);
        DB::table('placements')->where('id', $this->f['p']->id)->update(['status' => 'selesai']);
        $this->action($s, 'submit')->assertUnprocessable();
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'draft']);
    }

    public function test_account_relink_cannot_inherit_assignment_or_approval(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->action($s, 'submit')->assertRedirect();
        $replacement = $this->createUserWithRole('pembimbing');
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => $replacement->id]);
        $this->actingAs($replacement)->get('/penjadwalan/penempatan/'.$this->f['p']->ulid)->assertNotFound();
        $this->action($s, 'approve', $replacement)->assertNotFound();
        $this->action($s, 'approve', $this->f['mentor'])->assertNotFound();
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'submitted']);
    }

    public function test_multiple_mentors_and_supervisor_are_explicit_assignments(): void
    {
        $this->assignment();
        $mentor = $this->createUserWithRole('pembimbing');
        $supervisor = $this->createUserWithRole('supervisor');
        foreach ([[$mentor, 'mentor'], [$supervisor, 'supervisor']] as [$user, $role]) {
            $id = DB::table('educators')->insertGetId(['user_id' => $user->id, 'department_id' => $this->f['department'], 'name' => 'Pendidik '.$role, 'can_mentor' => $role === 'mentor', 'can_supervise' => $role === 'supervisor']);
            DB::table('educator_licenses')->insert(['educator_id' => $id, 'license_type' => 'Otorisasi', 'license_number' => 'TEST-'.$id, 'issued_at' => '2026-01-01']);
            $this->assignment(['educator_id' => $id, 'role' => $role]);
        }
        $this->assertSame(2, DB::table('educator_assignments')->where('role', 'mentor')->where('status', 'approved')->count());
        $s = $this->schedule(DB::table('educator_assignments')->where('role', 'mentor')->value('id'));
        $this->action($s, 'submit')->assertRedirect();
        $this->action($s, 'approve', $supervisor)->assertForbidden();
        $this->action($s, 'approve', $mentor)->assertForbidden();
    }

    public function test_replacement_schedule_can_complete_without_rechecking_superseded_source(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->publish($s);
        $next = $this->schedule($a->id, ['replaces_id' => $s->id, 'reason' => 'Revisi kegiatan dengan persetujuan']);
        $this->publish($next);
        $this->travelTo(now()->setDate(2026, 10, 3));
        $this->action($next, 'complete', $this->f['mentor'])->assertRedirect();
        $this->assertDatabaseHas('schedules', ['id' => $next->id, 'status' => 'completed']);
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'dijadwalkan']);
    }

    public function test_parallel_extension_requires_new_explicit_exception_and_still_rejects_hour_conflict(): void
    {
        $f = $this->f;
        $a = $this->assignment();
        $department = DB::table('departments')->insertGetId(['code' => 'PARALLEL', 'name' => 'KSM Paralel']);
        $other = (array) $f['p'];
        unset($other['id']);
        $other['ulid'] = (string) Str::ulid();
        $other['department_id'] = $department;
        $other['start_date'] = '2026-10-11';
        $other['end_date'] = '2026-10-20';
        $id = DB::table('placements')->insertGetId($other);
        $file = DB::table('private_files')->insertGetId(['ulid' => (string) Str::ulid(), 'resource_type' => 'placement', 'resource_id' => $f['p']->id,
            'category' => 'pendukung', 'path' => 'test-only', 'original_name' => 'bukti-test.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $f['admin']->id, 'scan_status' => 'clean', 'version' => 1]);
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/perpanjangan', ['new_end_date' => '2026-10-15', 'revision' => 1, 'supporting_file_id' => $file, 'reason' => 'Pendidikan paralel dengan bukti resmi'])->assertRedirect();
        $extension = DB::table('placement_extensions')->first();
        $url = '/penjadwalan/perpanjangan/'.$extension->ulid.'/keputusan';
        $this->actingAs($f['chief'])->post($url, ['action' => 'approve', 'revision' => 1, 'reason' => 'KSM menyetujui periode paralel'])->assertUnprocessable();
        $this->post($url, ['action' => 'approve', 'revision' => 1, 'approve_overlap' => 1, 'reason' => 'KSM menyetujui periode paralel'])->assertRedirect();
        $this->actingAs($f['kordik'])->post($url, ['action' => 'approve', 'revision' => 2, 'approve_overlap' => 1, 'reason' => 'Tim Kordik menyetujui periode paralel'])->assertRedirect();
        $this->assertDatabaseHas('overlap_exceptions', ['placement_id' => $f['p']->id, 'conflicting_placement_id' => $id, 'status' => 'approved', 'decided_by' => $f['kordik']->id]);
        // A test-only competing schedule in the other KSM is still a time reservation for the same participant.
        $this->assignment(['start_date' => '2026-10-11', 'end_date' => '2026-10-15']);
        $extendedAssignment = DB::table('educator_assignments')->orderByDesc('id')->first();
        $s = $this->schedule($extendedAssignment->id, ['date' => '2026-10-12']);
        $copy = (array) $s;
        unset($copy['id']);
        $copy['ulid'] = (string) Str::ulid();
        $copy['placement_id'] = $id;
        $copy['status'] = 'published';
        DB::table('schedules')->insert($copy);
        $this->action($s, 'submit')->assertUnprocessable();
        $this->assertDatabaseHas('schedules', ['id' => $s->id, 'status' => 'draft']);
    }

    public function test_activity_start_requires_dates_and_published_schedule_without_closing_placement(): void
    {
        $a = $this->assignment();
        $s = $this->schedule($a->id);
        $this->publish($s);
        $url = '/penjadwalan/penempatan/'.$this->f['p']->ulid.'/mulai';
        $this->actingAs($this->f['admin'])->post($url, ['revision' => 1])->assertUnprocessable();
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->post($url, ['revision' => 1])->assertRedirect();
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'sedang_stase']);
        $this->assertDatabaseHas('placement_histories', ['placement_id' => $this->f['p']->id, 'action' => 'activity_started']);
    }

    public function test_extension_waits_for_document_renewal_and_review_ui_is_available_after_verification(): void
    {
        $f = $this->f;
        $fileUlid = (string) Str::ulid();
        $file = DB::table('private_files')->insertGetId(['ulid' => $fileUlid, 'resource_type' => 'placement', 'resource_id' => $f['p']->id,
            'category' => 'ijazah', 'path' => 'test-only', 'original_name' => 'dokumen-test.pdf', 'mime' => 'application/pdf', 'size' => 1,
            'sha256' => str_repeat('b', 64), 'uploaded_by' => $f['admin']->id, 'scan_status' => 'clean', 'version' => 1]);
        DB::table('placement_documents')->where('placement_id', $f['p']->id)->where('code', 'ijazah')->update(['status' => 'valid', 'private_file_id' => $file, 'valid_until' => '2026-10-10']);
        $this->actingAs($f['admin'])->post('/penjadwalan/penempatan/'.$f['p']->ulid.'/perpanjangan', ['new_end_date' => '2026-10-15', 'revision' => 1, 'reason' => 'Perpanjangan memerlukan pembaruan dokumen'])->assertRedirect();
        $e = DB::table('placement_extensions')->first();
        $url = '/penjadwalan/perpanjangan/'.$e->ulid.'/keputusan';
        $this->actingAs($f['chief'])->post($url, ['action' => 'approve', 'revision' => 1, 'reason' => 'KSM memeriksa dokumen perpanjangan'])->assertSessionHasErrors('placement');
        $this->actingAs($f['admin'])->get('/penerimaan/penempatan/'.$f['p']->ulid)->assertOk()->assertSee('Review / pengecualian');
        $this->post('/penerimaan/penempatan/'.$f['p']->ulid.'/dokumen', ['code' => 'ijazah', 'file_ulid' => $fileUlid, 'status' => 'valid', 'valid_until' => '2026-10-15', 'reason' => 'Dokumen sudah diperiksa untuk periode baru'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($f['chief'])->post($url, ['action' => 'approve', 'revision' => 1, 'reason' => 'Dokumen telah mencakup perpanjangan'])->assertRedirect()->assertSessionHasNoErrors();
    }
}
