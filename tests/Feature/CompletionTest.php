<?php

namespace Tests\Feature;

use App\Services\AssessmentService;
use App\Services\CompletionService;
use App\Services\PlacementService;
use App\Services\PrivateFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompletionFixtures;
use Tests\TestCase;

class CompletionTest extends TestCase
{
    use CompletionFixtures, RefreshDatabase;

    private array $f;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->completionFixture();
        $this->url = '/penyelesaian/'.$this->f['p']->ulid;
    }

    private function act(string $actor, string $action, array $extra = [])
    {
        $r = DB::table('completion_requests')->latest('id')->first();

        return $this->actingAs($this->f[$actor])->post($this->url.'/status', $extra + ['action' => $action, 'revision' => DB::table('placements')->where('id', $this->f['p']->id)->value('revision'), 'request_id' => $r?->id, 'request_revision' => $r?->revision, 'reason' => 'Pemeriksaan kewajiban pendidikan lengkap', 'confirm' => 1]);
    }

    private function closePlacement(): void
    {
        $this->act('admin', 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('kordik', 'approve')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_complete_workflow_seal_read_access_and_reopening_preserves_history(): void
    {
        $this->actingAs($this->f['owner'])->get($this->url)->assertOk()->assertSee('Lengkap')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->closePlacement();
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'selesai', 'actual_end_date' => '2026-10-10']);
        $seal = DB::table('completion_requests')->first();
        $this->assertSame($seal->sha256, json_decode($seal->approval)->sha256);
        $this->assertSame('tim-kordik', json_decode($seal->approval)->role);
        foreach (['admin', 'owner', 'chief', 'kordik'] as $role) {
            $this->actingAs($this->f[$role])->get($this->url)->assertOk()->assertSee('SL-')->assertSee('Penempatan dikunci');
        }
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$this->f['grade']->ulid)->assertOk();
        $file = DB::table('private_files')->where('resource_type', 'logbook')->first();
        $this->get('/logbook/berkas/'.$file->ulid)->assertOk();
        $this->act('admin', 'reopen_request')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('admin', 'reopen_execute')->assertForbidden();
        $this->act('kordik', 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $this->travel(1)->seconds();
        $this->act('admin', 'reopen_execute')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'menunggu_penyelesaian', 'completed_at' => null]);
        $this->assertSame($seal->approval, DB::table('completion_requests')->where('id', $seal->id)->value('approval'));
        $this->closePlacement();
        $this->assertSame(2, DB::table('completion_requests')->where('kind', 'completion')->where('status', 'completed')->count());
    }

    public function test_roles_idor_stale_requests_and_self_approval_are_rejected(): void
    {
        foreach (['owner', 'chief', 'mentor', 'kordik'] as $role) {
            $response = $this->act($role, 'submit');
            $role === 'mentor' ? $response->assertNotFound() : $response->assertForbidden();
        }
        $other = $this->createUserWithRole('peserta');
        $this->actingAs($other)->get($this->url)->assertNotFound();
        $this->get('/penyelesaian')->assertOk()->assertDontSee('Peserta Pengujian');
        $this->f['super'] = $this->createUserWithRole('super-admin');
        $this->act('super', 'submit')->assertForbidden();
        $this->act('admin', 'submit', ['revision' => 999])->assertUnprocessable();
        $this->act('admin', 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('admin', 'submit')->assertUnprocessable();
        DB::table('user_roles')->insert(['user_id' => $this->f['admin']->id, 'role_id' => DB::table('roles')->where('code', 'tim-kordik')->value('id')]);
        $this->act('admin', 'approve')->assertForbidden();
        $this->act('kordik', 'approve', ['request_revision' => 99])->assertUnprocessable();
        $this->act('kordik', 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('kordik', 'approve')->assertUnprocessable();
        $this->assertDatabaseCount('completion_requests', 1);
    }

    public function test_every_gate_blocks_submission_and_no_partial_request_is_saved(): void
    {
        foreach ([['placement_documents', 'status', 'pending', 'documents'], ['attendance_summaries', 'status', 'superseded', 'attendance'], ['logbooks', 'status', 'revision', 'logbooks'], ['assessments', 'status', 'draft', 'grades'], ['survey_responses', 'status', 'submitted', 'participant'], ['schedules', 'status', 'submitted', 'obligations'], ['educator_assignments', 'status', 'pending', 'obligations']] as [$table, $field, $value, $gate]) {
            $r = DB::table($table)->first();
            DB::table($table)->where('id', $r->id)->update([$field => $value]);
            $check = app(CompletionService::class)->checklist(DB::table('placements')->find($this->f['p']->id));
            $this->assertFalse((bool) $check['checks'][$gate]['ok'], $table);
            $this->act('admin', 'submit')->assertUnprocessable();
            $this->assertDatabaseCount('completion_requests', 0);
            DB::table($table)->where('id', $r->id)->update([$field => $r->$field]);
        }
        $this->travelTo(now()->setDate(2026, 10, 10));
        $this->act('admin', 'submit')->assertUnprocessable();
    }

    public function test_appeal_after_submission_blocks_approval_and_requires_new_admin_review(): void
    {
        $this->act('admin', 'submit')->assertRedirect();
        app(AssessmentService::class)->transition($this->f['owner'], $this->f['grade']->ulid, ['action' => 'appeal', 'revision' => 3, 'note' => 'Mohon periksa kembali penilaian', 'confirm' => 1, 'deidentified' => 1]);
        $this->act('kordik', 'approve')->assertUnprocessable();
        foreach (['review' => 4, 'reject' => 5] as $action => $revision) {
            app(AssessmentService::class)->transition($this->f['mentor'], $this->f['grade']->ulid, ['action' => $action, 'revision' => $revision, 'note' => 'Tinjauan sesuai bukti pendidikan', 'confirm' => 1]);
        }
        $this->act('kordik', 'approve')->assertUnprocessable();
        $this->act('admin', 'withdraw')->assertRedirect()->assertSessionHasNoErrors();
        $this->closePlacement();
    }

    public function test_closed_placement_blocks_legacy_reopen_attendance_and_document_mutations(): void
    {
        $this->closePlacement();
        $p = DB::table('placements')->find($this->f['p']->id);
        $this->actingAs($this->f['kordik'])->post('/penerimaan/penempatan/'.$p->ulid.'/status', ['action' => 'reopen', 'expected_status' => 'selesai', 'revision' => $p->revision, 'reason' => 'Coba jalur pembukaan lama'])->assertSessionHasErrors();
        $this->actingAs($this->f['admin'])->post('/presensi/penempatan/'.$p->ulid, ['action' => 'correct', 'date' => '2026-10-02', 'clinical_location_id' => $this->f['location'], 'mentor_assignment_id' => $this->f['a']->id, 'attendance_status' => 'hadir', 'activity' => 'Koreksi kegiatan pendidikan', 'revision' => 2, 'reason' => 'Koreksi setelah penutupan'])->assertUnprocessable();
        $this->post('/penerimaan/berkas/placement/'.$p->ulid, ['category' => 'administrasi', 'deidentified' => 1, 'file' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%%EOF")])->assertUnprocessable();
        $this->post('/presensi/penempatan/'.$p->ulid.'/rekap', ['action' => 'generate', 'version' => 1])->assertUnprocessable();
        $this->actingAs($this->f['owner'])->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'submit', 'revision' => 3, 'confirm' => 1])->assertUnprocessable();
    }

    public function test_archive_retains_records_downloads_and_requires_three_years_since_last_completion(): void
    {
        $this->closePlacement();
        $this->act('admin', 'archive')->assertUnprocessable();
        $this->travelTo(now()->setDate(2029, 10, 11)->setTime(12, 1));
        $this->act('admin', 'archive')->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->f['owner'])->get('/penyelesaian')->assertOk()->assertDontSee('Peserta Pengujian');
        $this->get('/penyelesaian?archive=1')->assertOk()->assertSee('Peserta Pengujian');
        $this->get($this->url)->assertOk()->assertSee('Arsip tidak menghapus data');
        $file = DB::table('private_files')->where('resource_type', 'logbook')->first();
        $this->get('/logbook/berkas/'.$file->ulid)->assertOk();
        $this->act('admin', 'reopen_request')->assertUnprocessable();
        $this->assertDatabaseCount('logbook_versions', 1);
        $this->assertDatabaseCount('assessment_versions', 1);
        $this->assertDatabaseCount('survey_responses', 2);
    }

    public function test_survey_confirmation_role_and_token_status_are_not_automatic(): void
    {
        $r = DB::table('survey_responses')->where('kind', 'patient')->first();
        DB::table('survey_responses')->where('id', $r->id)->update(['status' => 'issued', 'revision' => 1, 'verified_by' => null]);
        $this->actingAs($this->f['owner'])->get($this->url)->assertOk();
        $this->assertDatabaseHas('survey_responses', ['id' => $r->id, 'status' => 'issued']);
        $this->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'submit', 'revision' => 1])->assertSessionHasErrors('confirm');
        $this->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'submit', 'revision' => 1, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'verify', 'revision' => 2, 'confirm' => 1])->assertForbidden();
        $this->actingAs($this->f['admin'])->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'reject', 'revision' => 2, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->f['owner'])->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'submit', 'revision' => 3, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->f['admin'])->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'verify', 'revision' => 4, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url.'/survei', ['kind' => 'patient', 'action' => 'verify', 'revision' => 4, 'confirm' => 1])->assertUnprocessable();
        $this->assertSame($r->token, DB::table('survey_responses')->where('id', $r->id)->value('token'));
    }

    public function test_google_form_configuration_restricts_urls_and_retains_existing_tokens(): void
    {
        $this->actingAs($this->f['owner'])->get('/penyelesaian/formulir')->assertForbidden();
        $this->actingAs($this->f['admin'])->get('/penyelesaian/formulir')->assertOk();
        foreach (['https://evil.example/forms', 'https://forms.gle.evil.example/test', 'https://docs.google.com/forms/d/e/123/edit', 'https://forms.gle/test?redirect=bad', 'https://user@forms.gle/test'] as $url) {
            $this->post('/penyelesaian/formulir', ['kind' => 'patient', 'name' => 'Formulir baru', 'url' => $url, 'confirm' => 1])->assertUnprocessable();
        }
        $form = DB::table('survey_forms')->first();
        $this->post('/penyelesaian/formulir/'.$form->id.'/nonaktif')->assertRedirect();
        $this->actingAs($this->f['owner'])->get($this->url)->assertOk()->assertSee('https://forms.gle/TestForm123');
    }

    public function test_integrity_failure_holds_completion_and_rejection_leaves_data_open(): void
    {
        $v = DB::table('assessment_versions')->first();
        DB::table('assessment_versions')->where('id', $v->id)->update(['sha256' => str_repeat('0', 64)]);
        $this->act('admin', 'submit')->assertUnprocessable();
        DB::table('assessment_versions')->where('id', $v->id)->update(['sha256' => $v->sha256]);
        $this->act('admin', 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('kordik', 'reject')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placements', ['id' => $this->f['p']->id, 'status' => 'sedang_stase']);
        $this->assertDatabaseHas('completion_requests', ['status' => 'rejected']);
        $this->closePlacement();
    }

    public function test_ui_renders_roles_without_leaking_survey_tokens_to_ksm(): void
    {
        $token = DB::table('survey_responses')->value('token');
        $this->actingAs($this->f['chief'])->get($this->url)->assertOk()->assertDontSee($token);
        if (getenv('SIKORDIK_UI_EXPORT') === '1') {
            $dir = storage_path('framework/testing/phase7-ui');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            foreach (['owner' => $this->url, 'admin' => $this->url, 'forms' => '/penyelesaian/formulir', 'index' => '/penyelesaian'] as $name => $url) {
                $html = $this->actingAs($this->f[$name === 'owner' ? 'owner' : 'admin'])->get($url)->assertOk()->getContent();
                $html = preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $html);
                file_put_contents($dir.'/'.$name.'.html', str_replace('http://localhost/build/', '/build/', $html));
            }
        }
    }

    public function test_reopening_rechecks_overlap_and_rolls_back_execution(): void
    {
        $this->closePlacement();
        $p = DB::table('placements')->find($this->f['p']->id);
        // Legacy early completion fixture: reopening must reserve the original end again.
        DB::table('placements')->where('id', $p->id)->update(['actual_end_date' => '2026-10-05']);
        $service = app(PlacementService::class);
        $next = $service->create($this->f['admin'], ['participant_ulid' => $this->f['participant']->ulid, 'letter_ulid' => DB::table('incoming_letters')->where('id', $p->incoming_letter_id)->value('ulid'), 'study_program_id' => $p->study_program_id, 'participant_type_id' => $p->participant_type_id, 'department_id' => $p->department_id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-10']);
        $service->transition($this->f['admin'], $next->ulid, 'submit', 'draft', 1, null);
        $this->act('admin', 'reopen_request')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('kordik', 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $this->act('admin', 'reopen_execute')->assertSessionHasErrors('placement');
        $this->assertDatabaseHas('placements', ['id' => $p->id, 'status' => 'selesai', 'actual_end_date' => '2026-10-05']);
        $this->assertDatabaseHas('completion_requests', ['kind' => 'reopen', 'status' => 'approved', 'executed_by' => null]);
    }

    public function test_document_expiry_is_evaluated_over_stase_and_physical_integrity_is_required(): void
    {
        $p = $this->f['p'];
        $file = app(PrivateFileService::class)->upload($this->f['admin'], 'placement', $p->ulid, 'ijazah', UploadedFile::fake()->createWithContent('ijazah.pdf', "%PDF-1.4\nDokumen administratif\n%%EOF"), true);
        DB::table('placement_documents')->where('placement_id', $p->id)->where('code', 'ijazah')->update(['status' => 'valid', 'private_file_id' => $file->id, 'valid_until' => '2026-10-09', 'reviewed_by' => $this->f['admin']->id]);
        $this->act('admin', 'submit')->assertUnprocessable();
        DB::table('placement_documents')->where('placement_id', $p->id)->where('code', 'ijazah')->update(['valid_until' => '2026-10-10']);
        $this->act('admin', 'submit')->assertRedirect()->assertSessionHasNoErrors();
        Storage::disk('local')->put($file->path, 'Berkas berubah');
        $this->act('kordik', 'approve')->assertUnprocessable();
        $this->assertDatabaseHas('completion_requests', ['status' => 'pending']);
    }
}
