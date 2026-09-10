<?php

namespace Tests\Feature;

use App\Services\EducatorAssignmentService;
use App\Services\FileInspector;
use App\Services\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class LogbookTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $f;

    private object $mentorAssignment;

    private object $supervisorAssignment;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('clean');
        $this->mock(FileInspector::class)->shouldReceive('inspect')->andReturn(true);
        $this->f = $this->schedulingFixture();
        $f = $this->f;
        $this->mentorAssignment = $this->assign($f['educator'], 'mentor');
        $this->f['supervisor'] = $this->createUserWithRole('supervisor');
        $educator = DB::table('educators')->insertGetId(['user_id' => $this->f['supervisor']->id, 'department_id' => $f['department'], 'name' => 'Supervisor Pengujian', 'can_supervise' => true]);
        DB::table('educator_licenses')->insert(['educator_id' => $educator, 'license_type' => 'Otorisasi pendidikan', 'license_number' => 'SUP-TEST', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $this->supervisorAssignment = $this->assign($educator, 'supervisor');
        DB::table('placements')->where('id', $f['p']->id)->update(['status' => 'sedang_stase']);
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $this->url = '/logbook/penempatan/'.$f['p']->ulid;
    }

    private function assign(int $educator, string $role): object
    {
        $a = app(EducatorAssignmentService::class)->request($this->f['admin'], $this->f['p']->ulid, ['educator_id' => $educator, 'role' => $role, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan resmi logbook pengujian']);
        app(EducatorAssignmentService::class)->decide($this->f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan logbook pengujian']);

        return $a;
    }

    private function pdf(string $text = 'Dokumen pendidikan'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('institusi.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ".$text."\n%%EOF");
    }

    private function payload(array $extra = [], string $kind = 'participant'): array
    {
        $base = ['kind' => $kind, 'type' => $kind === 'participant' ? 'Logbook institusi' : 'Bimbingan klinis', 'revision' => 0, 'deidentified' => 1, 'notes' => 'Catatan pendidikan',
            'reviewer_assignment_id' => $kind === 'participant' ? $this->mentorAssignment->id : $this->supervisorAssignment->id];
        if ($kind === 'participant') {
            $base['file'] = $this->pdf();
        } else {
            $base += ['author_assignment_id' => $this->mentorAssignment->id, 'date' => '2026-10-02', 'start_time' => '09:00', 'end_time' => '10:30', 'clinical_location_id' => $this->f['location'], 'material' => 'Materi pembelajaran umum'];
        }

        return $extra + $base;
    }

    private function create(string $kind = 'participant'): object
    {
        $this->actingAs($this->f[$kind === 'participant' ? 'owner' : 'mentor'])->post($this->url, $this->payload(kind: $kind))->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('logbooks')->latest('id')->first();
    }

    private function action(object $r, string $role, string $action, int $revision)
    {
        return $this->actingAs($this->f[$role])->post('/logbook/'.$r->ulid.'/status', ['action' => $action, 'revision' => $revision, 'confirm' => 1, 'note' => 'Keputusan pemeriksaan pengujian']);
    }

    public function test_participant_versions_revision_approval_private_download_and_closure(): void
    {
        $r = $this->create();
        $file = DB::table('private_files')->where('resource_type', 'logbook')->first();
        $this->get('/logbook/berkas/'.$file->ulid)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->action($r, 'owner', 'submit', 1)->assertRedirect()->assertSessionHasNoErrors();
        $this->action($r, 'mentor', 'revision', 2)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->f['owner'])->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 3, 'file' => $this->pdf('Versi revisi')]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('logbook_versions', 2);
        $this->assertDatabaseCount('private_files', 2);
        $this->action($r, 'owner', 'submit', 4)->assertRedirect()->assertSessionHasNoErrors();
        $this->action($r, 'mentor', 'approve', 5)->assertRedirect()->assertSessionHasNoErrors();
        $review = DB::table('logbook_reviews')->where('action', 'approve')->first();
        $approval = json_decode($review->approval);
        $this->assertSame($this->f['mentor']->name, $approval->name);
        $this->assertSame('pembimbing', $approval->role);
        $this->assertSame(2, $approval->version);
        $this->assertSame(DB::table('logbook_versions')->where('version', 2)->value('sha256'), $approval->sha256);
        $this->assertDatabaseHas('scheduling_histories', ['resource_type' => 'logbooks', 'event' => 'logbook_approved']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'scheduling.logbook_approved']);
        $this->actingAs($this->f['owner'])->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 6]))->assertUnprocessable();
        DB::table('placements')->where('id', $r->placement_id)->update(['status' => 'selesai']);
        $this->get('/logbook/'.$r->ulid)->assertOk()->assertSee('Dikunci');
        $this->get('/logbook/berkas/'.$file->ulid)->assertOk();
        $this->post($this->url, $this->payload(['type' => 'Logbook lain']))->assertUnprocessable();
        $this->action($r, 'mentor', 'approve', 6)->assertUnprocessable();
    }

    public function test_educator_supervisor_approval_and_recap_count_only_approved(): void
    {
        $r = $this->create('educator');
        $this->get($this->url.'/rekap')->assertOk()->assertSee('0 menit');
        $this->action($r, 'mentor', 'submit', 1)->assertRedirect()->assertSessionHasNoErrors();
        $this->action($r, 'supervisor', 'approve', 2)->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->url.'/rekap')->assertOk()->assertSee('90 menit')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertSame('supervisor', json_decode(DB::table('logbook_reviews')->where('action', 'approve')->value('approval'))->role);
        $this->actingAs($this->f['owner'])->get('/logbook/'.$r->ulid)->assertNotFound();
        $this->get($this->url.'/rekap')->assertOk()->assertDontSee('Bimbingan klinis');
    }

    public function test_scope_idor_and_roles_cannot_bypass_official_assignment(): void
    {
        $r = $this->create();
        $file = DB::table('private_files')->first();
        $this->action($r, 'owner', 'submit', 1)->assertRedirect();
        foreach (['peserta', 'pembimbing', 'supervisor', 'ketua-ksm', 'sekretariat-ksm'] as $role) {
            $u = $this->createUserWithRole($role);
            $this->actingAs($u)->get($this->url)->assertNotFound();
            $this->get('/logbook/'.$r->ulid)->assertNotFound();
            $this->get('/logbook/berkas/'.$file->ulid)->assertNotFound();
            $this->get($this->url.'/rekap')->assertNotFound();
            $this->post($this->url, $this->payload())->assertForbidden();
            $this->post('/logbook/'.$r->ulid.'/status', ['action' => 'approve', 'revision' => 2, 'note' => 'Tidak berwenang', 'confirm' => 1])->assertForbidden();
        }
        foreach (['admin', 'chief', 'kordik', 'supervisor'] as $role) {
            $this->action($r, $role, 'approve', 2)->assertForbidden();
        }
        $this->f['super'] = $this->createUserWithRole('super-admin');
        $this->action($r, 'super', 'approve', 2)->assertForbidden();
        $this->actingAs($this->f['super'])->get('/penerimaan/berkas/'.$file->ulid)->assertNotFound();
    }

    public function test_duplicate_files_logbooks_stale_submits_and_decisions_are_rejected(): void
    {
        $r = $this->create();
        $this->post($this->url, $this->payload())->assertUnprocessable();
        $this->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 1]))->assertUnprocessable();
        $this->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 0, 'file' => $this->pdf('Different')]))->assertUnprocessable();
        $this->action($r, 'mentor', 'approve', 1)->assertUnprocessable();
        $this->action($r, 'owner', 'submit', 1)->assertRedirect();
        $this->action($r, 'owner', 'submit', 1)->assertUnprocessable();
        $this->action($r, 'mentor', 'approve', 1)->assertUnprocessable();
        $this->action($r, 'mentor', 'approve', 2)->assertRedirect();
        $this->action($r, 'mentor', 'reject', 2)->assertUnprocessable();
        $this->assertDatabaseCount('logbook_versions', 1);
        $this->assertDatabaseCount('logbook_reviews', 2);
    }

    public function test_invalid_pdf_size_privacy_and_held_or_tampered_files(): void
    {
        foreach ([['file' => UploadedFile::fake()->createWithContent('bad.pdf', 'not a PDF')], ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['deidentified' => 0], ['file' => null]] as $extra) {
            $this->actingAs($this->f['owner'])->postJson($this->url, $this->payload($extra))->assertUnprocessable();
        }
        $r = $this->create();
        $file = DB::table('private_files')->first();
        foreach (['pending', 'held', 'infected', 'invalid'] as $status) {
            DB::table('private_files')->where('id', $file->id)->update(['scan_status' => $status]);
            $this->action($r, 'owner', 'submit', 1)->assertStatus(423);
            $this->get('/logbook/berkas/'.$file->ulid)->assertStatus(423);
        }
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => 'clean']);
        Storage::disk('local')->put($file->path, 'tampered');
        $this->action($r, 'owner', 'submit', 1)->assertStatus(423);
        $this->get('/logbook/berkas/'.$file->ulid)->assertStatus(423);
        $this->assertDatabaseCount('logbook_reviews', 0);
    }

    public function test_educator_dates_duration_location_duplicates_and_self_approval(): void
    {
        foreach ([['date' => '2026-09-30'], ['date' => '2026-10-04'], ['date' => '2026-10-11'], ['end_time' => '08:30'], ['clinical_location_id' => 9999], ['reviewer_assignment_id' => $this->mentorAssignment->id]] as $extra) {
            $this->actingAs($this->f['mentor'])->postJson($this->url, $this->payload($extra, 'educator'))->assertUnprocessable();
        }
        $r = $this->create('educator');
        $this->post($this->url, $this->payload(kind: 'educator'))->assertUnprocessable();
        $this->action($r, 'mentor', 'submit', 1)->assertRedirect();
        $this->action($r, 'mentor', 'approve', 2)->assertForbidden();
        $this->assertDatabaseCount('logbooks', 1);
    }

    public function test_rejected_activity_can_be_revised_and_old_snapshot_remains(): void
    {
        $r = $this->create('educator');
        $snapshot = DB::table('logbook_versions')->value('snapshot');
        $this->action($r, 'mentor', 'submit', 1)->assertRedirect();
        $this->action($r, 'supervisor', 'reject', 2)->assertRedirect();
        $this->actingAs($this->f['mentor'])->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 3, 'material' => 'Materi setelah perbaikan'], 'educator'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($snapshot, DB::table('logbook_versions')->where('version', 1)->value('snapshot'));
        $this->action($r, 'mentor', 'submit', 4)->assertRedirect();
        $this->action($r, 'supervisor', 'approve', 5)->assertRedirect();
        $this->get('/logbook/'.$r->ulid)->assertOk()->assertSee('Materi setelah perbaikan')->assertSee('Ditolak');
    }

    public function test_assignment_revocation_account_relinking_and_historical_review(): void
    {
        $r = $this->create();
        $this->action($r, 'owner', 'submit', 1)->assertRedirect();
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->actingAs($this->f['mentor'])->get('/logbook/'.$r->ulid)->assertOk();
        DB::table('educator_assignments')->where('id', $this->mentorAssignment->id)->update(['status' => 'replaced']);
        $this->action($r, 'mentor', 'approve', 2)->assertForbidden();
        $this->get('/logbook/'.$r->ulid)->assertNotFound();
        DB::table('educator_assignments')->where('id', $this->mentorAssignment->id)->update(['status' => 'approved']);
        $other = $this->createUserWithRole('pembimbing');
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => $other->id]);
        $this->action($r, 'mentor', 'approve', 2)->assertForbidden();
        $this->actingAs($other)->get('/logbook/'.$r->ulid)->assertNotFound();
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => $this->f['mentor']->id]);
        $this->action($r, 'mentor', 'approve', 2)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_pages_render_for_participant_mentor_supervisor_and_admin(): void
    {
        $participant = $this->create();
        $educator = $this->create('educator');
        $this->action($participant, 'owner', 'submit', 1)->assertRedirect();
        $this->action($educator, 'mentor', 'submit', 1)->assertRedirect();
        $pages = ['index' => ['admin', '/logbook'], 'placement' => ['owner', $this->url], 'participant-form' => ['owner', $this->url.'/tambah'],
            'educator-form' => ['mentor', $this->url.'/tambah?kind=educator'], 'review' => ['mentor', '/logbook/'.$participant->ulid],
            'supervisor' => ['supervisor', '/logbook/'.$educator->ulid], 'report' => ['admin', $this->url.'/rekap']];
        foreach ($pages as $name => [$role, $url]) {
            $html = $this->actingAs($this->f[$role])->get($url)->assertOk()->getContent();
            if (getenv('SIKORDIK_UI_EXPORT') === '1') {
                $dir = storage_path('framework/testing/phase5-ui');
                if (! is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                $html = preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $html);
                file_put_contents($dir.'/'.$name.'.html', str_replace('http://localhost/build/', '/build/', $html));
            }
        }
    }

    public function test_educator_attachment_and_approval_recheck_file_and_snapshot_integrity(): void
    {
        $this->actingAs($this->f['mentor'])->post($this->url, $this->payload(['file' => $this->pdf()], 'educator'))->assertRedirect()->assertSessionHasNoErrors();
        $r = DB::table('logbooks')->first();
        $file = DB::table('private_files')->first();
        $this->action($r, 'mentor', 'submit', 1)->assertRedirect()->assertSessionHasNoErrors();
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => 'held']);
        $this->action($r, 'supervisor', 'approve', 2)->assertStatus(423);
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => 'clean']);
        $v = DB::table('logbook_versions')->first();
        $s = json_decode($v->snapshot, true);
        $s['material'] = 'Perubahan tidak sah';
        DB::table('logbook_versions')->where('id', $v->id)->update(['snapshot' => json_encode($s)]);
        $this->action($r, 'supervisor', 'approve', 2)->assertStatus(423);
        $this->get('/logbook/berkas/'.$file->ulid)->assertStatus(423);
        DB::table('logbook_versions')->where('id', $v->id)->update(['snapshot' => $v->snapshot]);
        $this->get('/logbook/berkas/'.$file->ulid)->assertOk();
        $this->action($r, 'supervisor', 'approve', 2)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_scanner_holds_new_upload_and_inactive_educator_loses_access(): void
    {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('pending');
        $r = $this->create();
        $this->assertDatabaseHas('private_files', ['scan_status' => 'pending']);
        $this->action($r, 'owner', 'submit', 1)->assertStatus(423);
        DB::table('educators')->where('id', $this->f['educator'])->update(['is_active' => false]);
        $this->actingAs($this->f['mentor'])->get('/logbook/'.$r->ulid)->assertNotFound();
        $this->action($r, 'owner', 'submit', 1)->assertUnprocessable();
    }
}
