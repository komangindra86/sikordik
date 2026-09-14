<?php

namespace Tests\Feature;

use App\Services\AssessmentTemplateService;
use App\Services\EducatorAssignmentService;
use App\Services\FileInspector;
use App\Services\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $f;

    private object $assignment;

    private int $template;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('clean');
        $this->mock(FileInspector::class)->shouldReceive('inspect')->andReturn(true);
        $this->f = $this->schedulingFixture();
        $f = $this->f;
        $a = app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan resmi penilaian']);
        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan penilaian']);
        $this->assignment = $a;
        DB::table('placements')->where('id', $f['p']->id)->update(['status' => 'sedang_stase']);
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $this->template = app(AssessmentTemplateService::class)->create($f['admin'], $this->templateInput());
        $this->url = '/penilaian/penempatan/'.$f['p']->ulid;
    }

    private function templateInput(): array
    {
        return ['name' => 'Mini-CEX institusi v1', 'exam_type' => 'Mini-CEX', 'institution_id' => $this->f['institution'], 'calculation' => 'weighted', 'pass_mark' => 70,
            'components' => [['name' => 'Keterampilan', 'input_type' => 'number', 'minimum' => 1, 'maximum' => 5, 'weight' => 60, 'required' => 1],
                ['name' => 'Profesionalisme', 'input_type' => 'number', 'minimum' => 0, 'maximum' => 100, 'weight' => 40, 'required' => 1],
                ['name' => 'Umpan balik', 'input_type' => 'text', 'required' => 0]]];
    }

    private function payload(array $extra = []): array
    {
        $ids = DB::table('assessment_components')->where('assessment_template_id', $this->template)->orderBy('position')->pluck('id');

        return $extra + ['template_id' => $this->template, 'title' => 'Mini-CEX pertama', 'date' => '2026-10-02', 'mode' => 'dynamic', 'revision' => 0,
            'author_assignment_id' => $this->assignment->id, 'mentor_assignment_id' => $this->assignment->id, 'deidentified' => 1,
            'scores' => [$ids[0] => 4, $ids[1] => 80, $ids[2] => 'Umpan balik rahasia draft'], 'notes' => 'Catatan pendidikan'];
    }

    private function pdf(string $content = 'Penilaian institusi'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('nilai.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ".$content."\n%%EOF");
    }

    private function create(array $extra = []): object
    {
        $this->actingAs($this->f['mentor'])->post($this->url, $this->payload($extra))->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('assessments')->latest('id')->first();
    }

    private function action(object $r, string $actor, string $action, ?int $revision = null, array $extra = [])
    {
        return $this->actingAs($this->f[$actor])->post('/penilaian/'.$r->ulid.'/status', $extra + ['action' => $action,
            'revision' => $revision ?? DB::table('assessments')->where('id', $r->id)->value('revision'), 'note' => 'Alasan keputusan pendidikan', 'confirm' => 1, 'deidentified' => 1]);
    }

    private function publish(object $r): void
    {
        $this->action($r, 'mentor', 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $this->action($r, 'mentor', 'publish')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_dynamic_grading_direct_publication_and_participant_privacy(): void
    {
        $r = $this->create();
        $s = json_decode(DB::table('assessment_versions')->value('snapshot'));
        $this->assertEquals(77, $s->total);
        $this->assertTrue($s->passed);
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertNotFound();
        $this->get($this->url)->assertOk()->assertDontSee($r->title);
        $this->action($r, 'mentor', 'publish')->assertUnprocessable();
        foreach (['admin', 'chief', 'kordik', 'owner'] as $role) {
            $this->action($r, $role, 'approve')->assertForbidden();
        }
        $this->action($r, 'mentor', 'approve')->assertRedirect();
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertNotFound();
        $this->action($r, 'mentor', 'publish')->assertRedirect();
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertOk()->assertSee('77')->assertSee('Umpan balik rahasia draft')->assertSee('NL-')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('audit_logs', ['event' => 'scheduling.assessment_publish']);
        $this->assertDatabaseHas('scheduling_notifications', ['user_id' => $this->f['owner']->id, 'message' => 'Penilaian '.$r->title.': Dipublikasikan.']);
        $this->actingAs($this->f['kordik'])->get('/penilaian/'.$r->ulid)->assertOk();
        $this->actingAs($this->f['mentor'])->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 3]))->assertUnprocessable();
    }

    public function test_appeal_correction_preserves_published_values_until_new_publication(): void
    {
        $r = $this->create(['file' => $this->pdf()]);
        $before = DB::table('assessment_versions')->first();
        $this->publish($r);
        $this->action($r, 'owner', 'appeal', extra: ['file' => $this->pdf('Bukti keberatan')])->assertRedirect()->assertSessionHasNoErrors();
        $this->action($r, 'owner', 'appeal')->assertUnprocessable();
        $this->action($r, 'mentor', 'accept')->assertUnprocessable();
        $this->action($r, 'mentor', 'review')->assertRedirect();
        $this->action($r, 'mentor', 'accept')->assertRedirect();
        $revision = DB::table('assessments')->where('id', $r->id)->value('revision');
        $this->actingAs($this->f['mentor'])->get($this->url.'/'.$r->ulid.'/versi')->assertOk();
        $this->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => $revision, 'notes' => 'KOREKSI BELUM PUBLIK', 'file' => $this->pdf('PDF KOREKSI')]))->assertRedirect()->assertSessionHasNoErrors();
        $newFile = DB::table('private_files')->where('resource_type', 'assessment')->latest('id')->first();
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertOk()->assertDontSee('KOREKSI BELUM PUBLIK')->assertSee('Versi 1');
        $this->get('/penilaian/berkas/'.$newFile->ulid)->assertNotFound();
        $this->publish($r);
        $this->assertDatabaseHas('grade_appeals', ['assessment_id' => $r->id, 'status' => 'completed', 'version' => 1, 'corrected_version' => 2]);
        $this->assertSame($before->snapshot, DB::table('assessment_versions')->where('id', $before->id)->value('snapshot'));
        $this->assertSame($before->sha256, DB::table('assessment_versions')->where('id', $before->id)->value('sha256'));
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertOk()->assertSee('KOREKSI BELUM PUBLIK')->assertSee('Versi 1')->assertSee('Versi 2');
        $this->get('/penilaian/berkas/'.$newFile->ulid)->assertOk();
        $this->assertDatabaseCount('assessment_versions', 2);
        $this->assertSame(2, DB::table('assessment_events')->where('action', 'publish')->count());
    }

    public function test_rejected_appeal_cannot_unlock_grade(): void
    {
        $r = $this->create();
        $this->publish($r);
        $this->action($r, 'owner', 'appeal')->assertRedirect();
        $this->action($r, 'mentor', 'review')->assertRedirect();
        $this->action($r, 'mentor', 'reject')->assertRedirect();
        $this->action($r, 'mentor', 'accept')->assertUnprocessable();
        $this->actingAs($this->f['mentor'])->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 6]))->assertUnprocessable();
        $this->assertDatabaseHas('grade_appeals', ['status' => 'rejected']);
        $this->assertDatabaseCount('assessment_versions', 1);
    }

    public function test_upload_mode_scanner_and_integrity_fail_closed(): void
    {
        $r = $this->create(['mode' => 'document', 'file' => $this->pdf()]);
        $file = DB::table('private_files')->where('resource_type', 'assessment')->first();
        $this->actingAs($this->f['owner'])->get('/penilaian/berkas/'.$file->ulid)->assertNotFound();
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => 'unavailable']);
        $this->action($r, 'mentor', 'approve')->assertStatus(423);
        $this->get('/penilaian/berkas/'.$file->ulid)->assertStatus(423);
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => 'clean']);
        $this->publish($r);
        $this->actingAs($this->f['owner'])->get('/penilaian/berkas/'.$file->ulid)->assertOk();
        Storage::disk('local')->put($file->path, 'tampered');
        $this->get('/penilaian/berkas/'.$file->ulid)->assertStatus(423);
        $this->actingAs($this->f['admin'])->get('/penerimaan/berkas/'.$file->ulid)->assertNotFound();
    }

    public function test_scope_roles_assignment_revocation_and_account_relink(): void
    {
        $r = $this->create(['file' => $this->pdf()]);
        $file = DB::table('private_files')->where('resource_type', 'assessment')->first();
        foreach (['peserta', 'pembimbing', 'supervisor', 'ketua-ksm', 'sekretariat-ksm'] as $role) {
            $u = $this->createUserWithRole($role);
            $this->actingAs($u)->get($this->url)->assertNotFound();
            $this->get('/penilaian/'.$r->ulid)->assertNotFound();
            $this->get('/penilaian/berkas/'.$file->ulid)->assertNotFound();
            $this->post($this->url, $this->payload())->assertForbidden();
        }
        $this->f['super'] = $this->createUserWithRole('super-admin');
        $this->action($r, 'super', 'approve')->assertForbidden();
        DB::table('educator_assignments')->where('id', $this->assignment->id)->update(['status' => 'revoked']);
        $this->action($r, 'mentor', 'approve')->assertForbidden();
        $this->get('/penilaian/'.$r->ulid)->assertNotFound();
        DB::table('educator_assignments')->where('id', $this->assignment->id)->update(['status' => 'approved']);
        DB::table('educators')->where('id', $this->f['educator'])->update(['user_id' => null]);
        $this->action($r, 'mentor', 'approve')->assertForbidden();
        $this->get('/penilaian/berkas/'.$file->ulid)->assertNotFound();
    }

    public function test_invalid_scores_dates_template_scope_and_duplicate_or_stale_requests(): void
    {
        $ids = DB::table('assessment_components')->where('assessment_template_id', $this->template)->orderBy('position')->pluck('id');
        $this->actingAs($this->f['mentor']);
        foreach ([[], [$ids[0] => 6, $ids[1] => 80], [$ids[0] => 'NaN', $ids[1] => 80], [$ids[0] => 4, $ids[1] => 80, 99999 => 20]] as $scores) {
            $this->postJson($this->url, $this->payload(['scores' => $scores]))->assertUnprocessable();
        }
        foreach (['2026-09-30', '2026-10-04', '2026-10-11'] as $date) {
            $this->postJson($this->url, $this->payload(['date' => $date]))->assertUnprocessable();
        }
        $other = DB::table('institutions')->insertGetId(['code' => 'OTHER', 'name' => 'Institusi lain']);
        DB::table('assessment_templates')->where('id', $this->template)->update(['institution_id' => $other]);
        $this->post($this->url, $this->payload())->assertNotFound();
        DB::table('assessment_templates')->where('id', $this->template)->update(['institution_id' => $this->f['institution']]);
        $r = $this->create();
        $this->post($this->url, $this->payload())->assertUnprocessable();
        $this->post($this->url.'/'.$r->ulid.'/versi', $this->payload(['revision' => 0]))->assertUnprocessable();
        $this->action($r, 'mentor', 'approve', 1)->assertRedirect();
        $this->action($r, 'mentor', 'approve', 1)->assertUnprocessable();
        $this->action($r, 'mentor', 'publish', 1)->assertUnprocessable();
        $this->assertDatabaseCount('assessment_versions', 1);
    }

    public function test_template_validation_immutability_and_nonaggregated_values(): void
    {
        $this->actingAs($this->f['owner'])->post('/penilaian/template', $this->templateInput())->assertForbidden();
        $bad = $this->templateInput();
        $bad['components'][0]['weight'] = 30;
        $this->actingAs($this->f['admin'])->postJson('/penilaian/template', $bad)->assertUnprocessable();
        $bad = $this->templateInput();
        $bad['components'][0]['maximum'] = 1;
        $this->postJson('/penilaian/template', $bad)->assertUnprocessable();
        $this->get('/penilaian/template')->assertOk()->assertSee('Template penilaian');
        $data = ['name' => 'Formulir tanpa agregasi', 'exam_type' => 'Responsi', 'calculation' => 'none', 'components' => [['name' => 'Kesimpulan', 'input_type' => 'text', 'required' => 1]]];
        $this->post('/penilaian/template', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->template = DB::table('assessment_templates')->latest('id')->value('id');
        $id = DB::table('assessment_components')->where('assessment_template_id', $this->template)->value('id');
        // Use explicit payload because this template has just one component.
        $payload = ['template_id' => $this->template, 'title' => 'Responsi', 'date' => '2026-10-02', 'mode' => 'dynamic', 'revision' => 0, 'author_assignment_id' => $this->assignment->id, 'mentor_assignment_id' => $this->assignment->id, 'deidentified' => 1, 'scores' => [$id => 'Baik']];
        $this->actingAs($this->f['mentor'])->post($this->url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $s = json_decode(DB::table('assessment_versions')->value('snapshot'));
        $this->assertNull($s->total);
        $this->assertSame('Baik', $s->scores[0]->value);
        $this->actingAs($this->f['admin'])->post('/penilaian/template/'.$this->template.'/nonaktif')->assertRedirect();
        $this->actingAs($this->f['mentor'])->post($this->url, ['title' => 'Baru'] + $payload)->assertNotFound();
    }

    public function test_locked_placement_blocks_all_mutations_but_keeps_published_history(): void
    {
        $r = $this->create();
        $this->publish($r);
        DB::table('placements')->where('id', $r->placement_id)->update(['status' => 'selesai']);
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertOk()->assertSee('Dikunci');
        $this->action($r, 'owner', 'appeal')->assertUnprocessable();
        $this->action($r, 'mentor', 'approve')->assertUnprocessable();
        $this->actingAs($this->f['mentor'])->post($this->url, $this->payload(['title' => 'Nilai lain']))->assertUnprocessable();
        $this->get($this->url.'/tambah')->assertUnprocessable();
    }

    public function test_render_forms_and_invalid_file_types(): void
    {
        $this->actingAs($this->f['mentor'])->get('/penilaian')->assertOk();
        $this->get($this->url.'/tambah?date=2026-10-02&template_id='.$this->template)->assertOk()->assertSee('Keterampilan');
        $this->postJson($this->url, $this->payload(['mode' => 'document']))->assertUnprocessable();
        $this->postJson($this->url, $this->payload(['file' => UploadedFile::fake()->createWithContent('nilai.pdf', 'bukan pdf')]))->assertUnprocessable();
        $this->postJson($this->url, $this->payload(['deidentified' => 0]))->assertUnprocessable();
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_snapshot_tampering_blocks_approval_and_publication(): void
    {
        $r = $this->create();
        $v = DB::table('assessment_versions')->first();
        $s = json_decode($v->snapshot, true);
        $s['total'] = 100;
        DB::table('assessment_versions')->where('id', $v->id)->update(['snapshot' => json_encode($s)]);
        $this->action($r, 'mentor', 'approve')->assertStatus(423);
        DB::table('assessment_versions')->where('id', $v->id)->update(['snapshot' => $v->snapshot]);
        $this->action($r, 'mentor', 'approve')->assertRedirect();
        DB::table('assessment_versions')->where('id', $v->id)->update(['snapshot' => json_encode($s)]);
        $this->action($r, 'mentor', 'publish')->assertStatus(423);
    }

    public function test_examiner_can_fill_but_only_designated_mentor_can_approve_and_publish(): void
    {
        $examiner = $this->createUserWithRole('pembimbing');
        $educator = DB::table('educators')->insertGetId(['user_id' => $examiner->id, 'department_id' => $this->f['department'], 'name' => 'Penguji resmi', 'can_examine' => true]);
        DB::table('educator_licenses')->insert(['educator_id' => $educator, 'license_type' => 'Otorisasi pendidikan', 'license_number' => 'EXAM-TEST', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $a = app(EducatorAssignmentService::class)->request($this->f['admin'], $this->f['p']->ulid, ['educator_id' => $educator, 'role' => 'examiner', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'reason' => 'Penugasan penguji resmi']);
        app(EducatorAssignmentService::class)->decide($this->f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Penguji disetujui KSM']);
        $this->actingAs($examiner)->post($this->url, $this->payload(['author_assignment_id' => $a->id]))->assertRedirect()->assertSessionHasNoErrors();
        $r = DB::table('assessments')->first();
        $this->get('/penilaian/'.$r->ulid)->assertOk();
        $this->post('/penilaian/'.$r->ulid.'/status', ['action' => 'approve', 'revision' => 1, 'confirm' => 1, 'note' => 'Pengesahan penguji'])->assertForbidden();
        // Match the actual mentor form: it has no deidentified field.
        $this->actingAs($this->f['mentor'])->post('/penilaian/'.$r->ulid.'/status', ['action' => 'approve', 'revision' => 1, 'confirm' => 1, 'note' => 'Pengesahan pembimbing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/penilaian/'.$r->ulid.'/status', ['action' => 'publish', 'revision' => 2, 'confirm' => 1, 'note' => 'Publikasi pembimbing'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_inactive_educator_participant_relink_self_grading_and_period_scope(): void
    {
        $r = $this->create();
        DB::table('educators')->where('id', $this->f['educator'])->update(['is_active' => false]);
        $this->action($r, 'mentor', 'approve')->assertForbidden();
        $this->get('/penilaian/'.$r->ulid)->assertNotFound();
        DB::table('educators')->where('id', $this->f['educator'])->update(['is_active' => true]);
        $this->publish($r);
        DB::table('participants')->where('id', $this->f['p']->participant_id)->update(['user_id' => null]);
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertNotFound();
        $this->action($r, 'owner', 'appeal')->assertForbidden();
        DB::table('participants')->where('id', $this->f['p']->participant_id)->update(['user_id' => $this->f['mentor']->id]);
        DB::table('user_roles')->insert(['user_id' => $this->f['mentor']->id, 'role_id' => DB::table('roles')->where('code', 'peserta')->value('id')]);
        $this->f['mentor']->refresh();
        $this->actingAs($this->f['mentor'])->post($this->url, $this->payload(['title' => 'Menilai diri']))->assertForbidden();
        DB::table('participants')->where('id', $this->f['p']->participant_id)->update(['user_id' => $this->f['owner']->id]);
        DB::table('assessment_templates')->where('id', $this->template)->update(['start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $this->post($this->url, $this->payload(['title' => 'Di luar periode template']))->assertNotFound();
    }

    public function test_malformed_template_precision_oversize_and_appeal_scanner_are_rejected(): void
    {
        $this->actingAs($this->f['admin'])->postJson('/penilaian/template', ['components' => 'invalid'])->assertUnprocessable();
        $bad = $this->templateInput();
        $bad['components'][0]['minimum'] = 0.001;
        $this->postJson('/penilaian/template', $bad)->assertUnprocessable();
        $bad = $this->templateInput();
        $bad['components'][0]['assessment_template_id'] = $this->template;
        $this->postJson('/penilaian/template', $bad)->assertUnprocessable();
        $this->actingAs($this->f['mentor'])->postJson($this->url, $this->payload(['file' => UploadedFile::fake()->createWithContent('nilai.pdf', "%PDF-1.4\n".str_repeat('x', 10 * 1024 * 1024))]))->assertUnprocessable();
        $r = $this->create();
        $this->publish($r);
        $this->action($r, 'owner', 'appeal', extra: ['file' => $this->pdf('Bukti')])->assertRedirect();
        DB::table('private_files')->where('resource_type', 'grade_appeal')->update(['scan_status' => 'unavailable']);
        $this->action($r, 'mentor', 'review')->assertStatus(423);
        $this->assertDatabaseHas('grade_appeals', ['status' => 'submitted']);
    }

    public function test_published_snapshot_tampering_is_not_displayed_to_participant(): void
    {
        $r = $this->create();
        $this->publish($r);
        $v = DB::table('assessment_versions')->first();
        $s = json_decode($v->snapshot, true);
        $s['scores'][0]['value'] = 5;
        DB::table('assessment_versions')->where('id', $v->id)->update(['snapshot' => json_encode($s)]);
        $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertStatus(423);
    }

    public function test_rendered_phase_six_pages(): void
    {
        $responses = [];
        $responses['templates'] = $this->actingAs($this->f['admin'])->get('/penilaian/template')->assertOk();
        $responses['form'] = $this->actingAs($this->f['mentor'])->get($this->url.'/tambah?date=2026-10-02&template_id='.$this->template)->assertOk();
        $r = $this->create();
        $responses['approval'] = $this->get('/penilaian/'.$r->ulid)->assertOk();
        $this->publish($r);
        $responses['participant'] = $this->actingAs($this->f['owner'])->get('/penilaian/'.$r->ulid)->assertOk();
        if (getenv('SIKORDIK_RENDER_PHASE6') === '1') {
            $directory = storage_path('app/private/phase6-preview');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            foreach ($responses as $name => $response) {
                // Only synthetic test fixture HTML, never application records or real sessions.
                file_put_contents($directory.'/'.$name.'.html', str_replace('http://localhost', 'http://127.0.0.1:8766', $response->getContent()));
            }
        }
    }
}
