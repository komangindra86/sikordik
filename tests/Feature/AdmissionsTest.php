<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileInspector;
use App\Services\MalwareScanner;
use App\Services\ParticipantService;
use App\Services\PlacementService;
use App\Services\PrivateFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AdmissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kordik;

    private User $chief;

    private int $institution;

    private int $program;

    private int $department;

    private int $otherDepartment;

    private object $letter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->createUserWithRole('admin-kordik');
        $this->kordik = $this->createUserWithRole('tim-kordik');
        $this->chief = $this->createUserWithRole('ketua-ksm');
        $this->institution = DB::table('institutions')->insertGetId(['code' => 'TEST', 'name' => 'Institusi Test']);
        $level = DB::table('education_levels')->insertGetId(['code' => 'S1', 'name' => 'S1']);
        $this->program = DB::table('study_programs')->insertGetId(['institution_id' => $this->institution, 'education_level_id' => $level, 'code' => 'TEST', 'name' => 'Program Test']);
        $this->department = DB::table('departments')->insertGetId(['code' => 'A', 'name' => 'KSM Test A']);
        $this->otherDepartment = DB::table('departments')->insertGetId(['code' => 'B', 'name' => 'KSM Test B']);
        DB::table('user_scopes')->insert(['user_id' => $this->chief->id, 'scope_type' => 'department', 'scope_id' => $this->department]);
        $this->actingAs($this->admin)->post('/penerimaan/surat', ['institution_id' => $this->institution, 'number' => '001/IX/2026', 'letter_date' => '2026-09-01', 'subject' => 'Surat Test'])->assertSessionHasNoErrors();
        $this->letter = DB::table('incoming_letters')->first();
    }

    private function participant(array $extra = []): object
    {
        return app(ParticipantService::class)->create($this->admin, $extra + ['name' => 'Peserta '.Str::random(8), 'institution_id' => $this->institution]);
    }

    private function placement(?object $participant = null, array $extra = []): object
    {
        return app(PlacementService::class)->create($this->admin, $extra + ['participant_ulid' => ($participant ?? $this->participant())->ulid, 'letter_ulid' => $this->letter->ulid, 'study_program_id' => $this->program, 'participant_type_id' => DB::table('participant_types')->where('code', 'KOAS')->value('id'), 'department_id' => $this->department, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
    }

    private function action(object $p, string $action, ?User $actor = null, ?string $status = null)
    {
        $fresh = DB::table('placements')->find($p->id);

        return $this->actingAs($actor ?? $this->admin)->post('/penerimaan/penempatan/'.$p->ulid.'/status', ['action' => $action, 'expected_status' => $status ?? $fresh->status, 'revision' => $fresh->revision, 'reason' => 'Alasan pengujian yang sah']);
    }

    private function awaitingDocuments(object $p): void
    {
        $this->action($p, 'submit')->assertSessionHasNoErrors();
        $this->action($p, 'ksm_accept', $this->chief)->assertSessionHasNoErrors();
        $this->action($p, 'kordik_accept', $this->kordik)->assertSessionHasNoErrors();
    }

    private function file(object $p, string $category = 'ijazah', string $resource = 'placement'): object
    {
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('clean'));
        $this->mock(FileInspector::class, fn ($m) => $m->shouldReceive('inspect')->andReturn(true));

        return app(PrivateFileService::class)->upload($this->admin, $resource, $p->ulid, $category, UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj <<>> endobj\n%%EOF"), true);
    }

    public function test_returning_participant_and_batch_letter_keep_one_identity_and_snapshots(): void
    {
        $p = $this->participant(['nim' => ' 001a ', 'email' => 'TEST@EXAMPLE.COM']);
        $first = $this->placement($p);
        $this->action($first, 'submit')->assertSessionHasNoErrors();
        $second = $this->placement($p, ['start_date' => '2027-01-01', 'end_date' => '2027-01-10']);
        $this->action($second, 'submit')->assertSessionHasNoErrors();
        $this->placement();
        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseCount('incoming_letters', 1);
        $this->assertSame('001A', $p->nim);
        $this->assertSame('test@example.com', $p->email);
        $this->assertMatchesRegularExpression('/^PDK-\d{4}-000001$/', $p->number);
        DB::table('institutions')->where('id', $this->institution)->update(['name' => 'Nama berubah']);
        $this->assertSame('Institusi Test', json_decode(DB::table('placements')->find($first->id)->snapshot, true)['institution']);
        foreach (['/penerimaan', '/penerimaan/peserta', '/penerimaan/surat', '/penerimaan/impor', '/penerimaan/persyaratan', '/penerimaan/penempatan/tambah', '/penerimaan/penempatan/'.$first->ulid] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_duplicate_review_does_not_auto_merge_and_nik_is_unique(): void
    {
        $data = ['name' => '  Made   Indra ', 'birth_date' => '2000-01-02', 'nik' => '0123456789012345', 'nim' => '001a', 'email' => 'SAME@EXAMPLE.TEST', 'institution_id' => $this->institution];
        $this->participant($data);
        $this->actingAs($this->admin)->post('/penerimaan/peserta/pratinjau', $data)->assertOk()->assertSee('NIK sama')->assertSee('Nama dan tanggal lahir sama');
        $this->post('/penerimaan/peserta', $data + ['review_confirmed' => 1, 'duplicate_reason' => 'Orang berbeda berdasarkan pemeriksaan'])->assertSessionHasErrors('duplicate_reason');
        $data['nik'] = null;
        $this->post('/penerimaan/peserta', $data + ['review_confirmed' => 1])->assertSessionHasErrors('duplicate_reason');
        $this->post('/penerimaan/peserta', $data + ['review_confirmed' => 1, 'duplicate_reason' => 'Orang berbeda berdasarkan pemeriksaan'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'participant.created', 'reason' => 'Orang berbeda berdasarkan pemeriksaan']);
        $this->assertSame([], app(ParticipantService::class)->candidates(['name' => 'Nama lain', 'institution_id' => $this->institution]));
    }

    public function test_letter_uniqueness_uses_sender_year_and_normalized_number(): void
    {
        $data = ['institution_id' => $this->institution, 'number' => ' 001/ix/2026 ', 'letter_date' => '2026-12-01', 'subject' => 'Test'];
        $this->post('/penerimaan/surat', $data)->assertSessionHasErrors('normalized_number');
        $data['letter_date'] = '2027-01-01';
        $this->post('/penerimaan/surat', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('incoming_letters', 2);
    }

    public function test_inclusive_overlap_same_ksm_and_cross_ksm_rejected_but_next_day_allowed(): void
    {
        $p = $this->participant();
        $first = $this->placement($p);
        $this->action($first, 'submit')->assertSessionHasNoErrors();
        $same = $this->placement($p, ['start_date' => '2026-10-10', 'end_date' => '2026-10-20']);
        $this->action($same, 'submit')->assertSessionHasErrors('placement');
        $cross = $this->placement($p, ['department_id' => $this->otherDepartment]);
        $this->action($cross, 'submit')->assertSessionHasErrors('placement');
        $next = $this->placement($p, ['start_date' => '2026-10-11', 'end_date' => '2026-10-20']);
        $this->action($next, 'submit')->assertSessionHasNoErrors();
        $this->action($this->placement(), 'submit')->assertSessionHasNoErrors();
        $this->assertSame('draft', DB::table('placements')->find($same->id)->status);
    }

    public function test_completed_placement_uses_actual_end_and_cancelled_does_not_reserve(): void
    {
        $p = $this->participant();
        $old = $this->placement($p);
        DB::table('placements')->where('id', $old->id)->update(['status' => 'selesai', 'actual_end_date' => '2026-10-05']);
        $new = $this->placement($p, ['start_date' => '2026-10-06', 'end_date' => '2026-10-10']);
        $this->action($new, 'submit')->assertSessionHasNoErrors();
        $this->action($old, 'reopen', $this->kordik)->assertSessionHasErrors('placement');
        $this->action($old, 'cancel')->assertSessionHasErrors('placement');
        $this->action($new, 'cancel')->assertSessionHasNoErrors();
        $this->action($old, 'reopen', $this->kordik)->assertSessionHasNoErrors();
    }

    public function test_workflow_enforces_scope_roles_stale_status_and_transactional_history(): void
    {
        $p = $this->placement();
        $this->action($p, 'kordik_accept', $this->kordik)->assertSessionHasErrors('placement');
        $this->action($p, 'submit')->assertSessionHasNoErrors();
        $this->action($p, 'submit', status: 'draft')->assertSessionHasErrors('placement');
        $this->action($p, 'ksm_accept', $this->admin)->assertForbidden();
        $other = $this->createUserWithRole('ketua-ksm');
        DB::table('user_scopes')->insert(['user_id' => $other->id, 'scope_type' => 'department', 'scope_id' => $this->otherDepartment]);
        $this->action($p, 'ksm_accept', $other)->assertNotFound();
        $this->action($p, 'ksm_accept', $this->chief)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placement_histories', ['placement_id' => $p->id, 'to_status' => 'diterima_ksm']);
        $this->assertDatabaseHas('placement_histories', ['placement_id' => $p->id, 'to_status' => 'menunggu_persetujuan_kordik']);
        $this->action($p, 'kordik_accept', $this->admin)->assertForbidden();
        $this->action($p, 'kordik_reject', $this->kordik)->assertSessionHasNoErrors();
        $this->action($p, 'revise')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placements', ['id' => $p->id, 'revision' => 2, 'status' => 'draft', 'ksm_status' => 'pending']);
        $this->assertDatabaseCount('placement_histories', 6);
    }

    public function test_exception_needs_other_approver_and_becomes_invalid_after_period_revision(): void
    {
        $p = $this->participant();
        $first = $this->placement($p);
        $this->action($first, 'submit');
        $second = $this->placement($p, ['department_id' => $this->otherDepartment]);
        $file = $this->file($second, 'pendukung');
        $this->actingAs($this->admin)->post('/penerimaan/penempatan/'.$second->ulid.'/pengecualian', ['conflict_ulid' => $first->ulid, 'file_ulid' => $file->ulid, 'reason' => 'Program paralel dengan waktu terpisah'])->assertSessionHasNoErrors();
        $exception = DB::table('overlap_exceptions')->first();
        DB::table('user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => DB::table('roles')->where('code', 'tim-kordik')->value('id')]);
        $this->post('/penerimaan/pengecualian/'.$exception->ulid.'/keputusan', ['approved' => 1, 'reason' => 'Persetujuan oleh diri sendiri'])->assertForbidden();
        $this->actingAs($this->kordik)->post('/penerimaan/pengecualian/'.$exception->ulid.'/keputusan', ['approved' => 1, 'reason' => 'Dokumen dan periode telah diperiksa'])->assertSessionHasNoErrors();
        $this->action($second, 'submit')->assertSessionHasNoErrors();
        $this->action($second, 'cancel')->assertSessionHasNoErrors();
        $this->action($second, 'revise')->assertSessionHasNoErrors();
        $this->action($second, 'submit')->assertSessionHasErrors('placement');
        $this->assertDatabaseCount('overlap_exceptions', 1);
    }

    public function test_private_file_entitlement_version_quarantine_and_unpublished_category(): void
    {
        $p = $this->placement();
        $file = $this->file($p);
        // Preserve fake disk between versions.
        $next = app(PrivateFileService::class)->upload($this->admin, 'placement', $p->ulid, 'ijazah', UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\n%%EOF"), true);
        $this->assertSame(2, (int) $next->version);
        Storage::disk('local')->assertExists($file->path);
        $owner = $this->createUserWithRole();
        $other = $this->createUserWithRole();
        $super = $this->createUserWithRole('super-admin');
        DB::table('participants')->where('id', $p->participant_id)->update(['user_id' => $owner->id]);
        $url = '/penerimaan/berkas/'.$file->ulid;
        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($super)->get($url)->assertNotFound();
        $this->actingAs($this->chief)->get($url)->assertNotFound(); // Identity document outside KSM entitlement.
        DB::table('private_files')->where('id', $file->id)->update(['category' => 'bhd']);
        $this->get($url)->assertOk();
        DB::table('placements')->where('id', $p->id)->update(['department_id' => $this->otherDepartment]);
        $this->get($url)->assertNotFound();
        DB::table('private_files')->where('id', $file->id)->update(['category' => 'nilai']);
        $this->actingAs($owner)->get($url)->assertNotFound();
        $this->actingAs($this->admin)->get($url)->assertNotFound();
        DB::table('private_files')->where('id', $file->id)->update(['category' => 'ijazah', 'scan_status' => 'pending']);
        $this->get($url)->assertStatus(423);
    }

    public function test_wrong_mime_size_and_unavailable_scanner_never_release_file(): void
    {
        $p = $this->placement();
        Storage::fake('local');
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('pending'));
        $url = '/penerimaan/berkas/placement/'.$p->ulid;
        $this->post($url, ['category' => 'ijazah', 'deidentified' => 1, 'file' => UploadedFile::fake()->createWithContent('bad.pdf', '<html>bad</html>')])->assertSessionHasErrors('file');
        $this->post($url, ['category' => 'ijazah', 'deidentified' => 1, 'file' => UploadedFile::fake()->create('large.pdf', 10241)])->assertSessionHasErrors('file');
        $this->post($url, ['category' => 'ijazah', 'deidentified' => 1, 'file' => UploadedFile::fake()->createWithContent('valid.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('private_files', ['scan_status' => 'pending']);
        $this->get('/penerimaan/berkas/'.DB::table('private_files')->value('ulid'))->assertStatus(423);
    }

    public function test_batch_letter_not_visible_to_participant_and_checklist_activation_requires_verification(): void
    {
        $p = $this->placement();
        $this->awaitingDocuments($p);
        $this->action($p, 'verify')->assertSessionHasErrors('placement');
        $this->actingAs($this->admin)->post('/penerimaan/peserta/'.DB::table('participants')->find($p->participant_id)->ulid.'/aktivasi', ['email' => 'activate@example.test', 'ownership_confirmed' => 1, 'ownership_reason' => 'Identitas telah diperiksa petugas'])->assertSessionHasErrors('email');
        foreach (['surat', 'ijazah', 'bhd'] as $code) {
            $this->actingAs($this->kordik)->post('/penerimaan/penempatan/'.$p->ulid.'/dokumen', ['code' => $code, 'status' => 'exception', 'reason' => 'Pengecualian resmi berdasarkan pemeriksaan'])->assertSessionHasNoErrors();
        }
        $this->action($p, 'verify')->assertSessionHasNoErrors();
        $participant = DB::table('participants')->find($p->participant_id);
        $url = '/penerimaan/peserta/'.$participant->ulid.'/aktivasi';
        $payload = ['email' => 'activate@example.test', 'ownership_confirmed' => 1, 'ownership_reason' => 'Identitas telah diperiksa petugas'];
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $owner = User::where('email', 'activate@example.test')->firstOrFail();
        $letterFile = $this->file($this->letter, 'surat', 'letter');
        $this->actingAs($owner)->get('/penerimaan/berkas/'.$letterFile->ulid)->assertNotFound();
        $this->get('/penerimaan/penempatan/'.$p->ulid)->assertOk()->assertDontSee('Pengecualian resmi berdasarkan pemeriksaan');
        $this->get('/penerimaan/peserta')->assertForbidden();
        $this->get('/penerimaan/surat')->assertForbidden();
    }

    private function xlsx(array $rows, array $extraEntries = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_merge([['name', 'birth_date', 'nik', 'nim', 'email']], $rows) as $n => $row) {
            $xml .= '<row r="'.($n + 1).'">';
            foreach ($row as $c => $value) {
                $xml .= '<c r="'.chr(65 + $c).($n + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        foreach ($extraEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('participants.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_import_preview_confirmation_retry_duplicates_and_formulas(): void
    {
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('clean'));
        $file = $this->xlsx([['Peserta Impor', '2000-01-01', '', '001A', 'import@example.test'], ['Peserta Impor', '2000-01-01', '', '001A', 'import@example.test'], ['=HYPERLINK("bad")', '', '', '', '']]);
        $this->post('/penerimaan/impor', ['institution_id' => $this->institution, 'file' => $file])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participants', 0);
        $import = DB::table('participant_imports')->first();
        $url = '/penerimaan/impor/'.$import->ulid;
        $this->get($url)->assertOk();
        $this->post($url, [])->assertSessionHasErrors('confirmed');
        $this->post($url, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participants', 1);
        $this->assertSame(2, DB::table('participant_import_rows')->where('status', 'failed')->count());
        $this->post($url, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participants', 1);
        $p = DB::table('participants')->first();
        $this->post($url, ['confirmed' => 1, 'choices' => [3 => ['existing_ulid' => $p->ulid, 'reason' => 'Baris kedua orang yang sama']]])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('participant_import_rows')->where('status', 'committed')->count());
        $this->assertDatabaseCount('participants', 1);
        $this->post('/penerimaan/impor', ['institution_id' => $this->institution, 'file' => $file])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participant_imports', 1);
        $this->actingAs($this->createUserWithRole('admin-kordik'))->get($url)->assertNotFound();
    }

    public function test_template_changes_do_not_rewrite_historical_checklists(): void
    {
        $p = $this->placement();
        $this->post('/penerimaan/persyaratan', ['participant_type_id' => $p->participant_type_id, 'department_id' => $this->department, 'code' => 'surat_sehat', 'label' => 'Surat sehat', 'reason' => 'Persyaratan resmi tambahan'])->assertSessionHasNoErrors();
        $new = $this->placement();
        $this->assertSame(3, DB::table('placement_documents')->where('placement_id', $p->id)->count());
        $this->assertSame(4, DB::table('placement_documents')->where('placement_id', $new->id)->count());
    }

    public function test_identity_edit_is_audited_stale_safe_and_preserves_placement_snapshot(): void
    {
        $participant = $this->participant();
        $placement = $this->placement($participant);
        $this->get('/penerimaan/peserta/'.$participant->ulid.'/ubah')->assertOk();
        $data = ['name' => 'Nama dikoreksi', 'institution_id' => $this->institution, 'expected_hash' => ParticipantService::identityHash($participant), 'reason' => 'Koreksi sesuai dokumen asli'];
        $this->post('/penerimaan/peserta/'.$participant->ulid.'/ubah', $data)->assertSessionHasNoErrors();
        $this->post('/penerimaan/peserta/'.$participant->ulid.'/ubah', $data)->assertSessionHasErrors('name');
        $this->assertDatabaseHas('participants', ['id' => $participant->id, 'name' => 'Nama dikoreksi', 'number' => $participant->number]);
        $this->assertSame($placement->snapshot, DB::table('placements')->find($placement->id)->snapshot);
        $this->assertDatabaseHas('audit_logs', ['event' => 'participant.updated', 'reason' => 'Koreksi sesuai dokumen asli']);
    }

    public function test_document_review_rejects_foreign_files_expiry_and_unauthorized_exception(): void
    {
        $p = $this->placement();
        $other = $this->placement();
        $this->awaitingDocuments($p);
        $foreign = $this->file($other);
        $own = $this->file($p);
        $url = '/penerimaan/penempatan/'.$p->ulid.'/dokumen';
        $data = ['code' => 'ijazah', 'file_ulid' => $foreign->ulid, 'status' => 'valid', 'reason' => 'Pemeriksaan dokumen persyaratan'];
        $this->actingAs($this->admin)->post($url, $data)->assertSessionHasErrors('placement');
        $data['file_ulid'] = $own->ulid;
        $data['valid_until'] = '2026-10-09';
        $this->post($url, $data)->assertSessionHasErrors('placement');
        $data['valid_until'] = '2027-10-10';
        $this->post($url, $data)->assertSessionHasNoErrors();
        $data['status'] = 'exception';
        $this->post($url, $data)->assertForbidden();
        $this->actingAs($this->chief)->get('/penerimaan/penempatan/'.$p->ulid)->assertOk();
        $this->actingAs($this->kordik)->get('/penerimaan/penempatan/'.$p->ulid)->assertOk();
    }

    public function test_period_revision_rechecks_conflicts_and_stale_revision(): void
    {
        $p = $this->participant();
        $a = $this->placement($p);
        $this->action($a, 'submit');
        $b = $this->placement($p, ['start_date' => '2026-10-11', 'end_date' => '2026-10-20']);
        $this->action($b, 'submit');
        $url = '/penerimaan/penempatan/'.$b->ulid.'/periode';
        $data = ['start_date' => '2026-10-10', 'end_date' => '2026-10-20', 'department_id' => $this->department, 'revision' => 1, 'reason' => 'Perubahan periode berdasarkan surat'];
        $this->post($url, $data)->assertSessionHasErrors('placement');
        $data['start_date'] = '2026-10-12';
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasErrors('placement');
        $this->assertDatabaseHas('placements', ['id' => $b->id, 'status' => 'draft', 'revision' => 2]);
    }

    public function test_activation_never_links_an_existing_account_by_email(): void
    {
        $p = $this->placement();
        DB::table('placements')->where('id', $p->id)->update(['status' => 'terverifikasi']);
        $participant = DB::table('participants')->find($p->participant_id);
        $this->post('/penerimaan/peserta/'.$participant->ulid.'/aktivasi', ['email' => $this->kordik->email, 'ownership_confirmed' => 1, 'ownership_reason' => 'Percobaan menautkan akun yang ada'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('participants', ['id' => $participant->id, 'user_id' => null]);
    }

    public function test_import_rejects_zip_bomb_and_active_content_and_keeps_quarantine(): void
    {
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('clean'));
        $file = $this->xlsx([['Peserta', '', '', '', '']], ['xl/vbaProject.bin' => 'macro']);
        $this->post('/penerimaan/impor', ['institution_id' => $this->institution, 'file' => $file])->assertSessionHasErrors('file');
        $this->assertDatabaseHas('private_files', ['resource_type' => 'import', 'scan_status' => 'held']);
        $file = $this->xlsx([['Peserta', '', '', '', '']], ['xl/oversized.xml' => str_repeat('A', 9 * 1024 * 1024)]);
        $this->post('/penerimaan/impor', ['institution_id' => $this->institution, 'file' => $file])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('participant_import_rows', 0);
        $this->get('/penerimaan/impor/'.DB::table('participant_imports')->value('ulid'))->assertOk()->assertSee('held');
    }

    public function test_infected_and_structurally_held_files_stay_private(): void
    {
        $p = $this->placement();
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('infected'));
        $this->post('/penerimaan/berkas/placement/'.$p->ulid, ['category' => 'ijazah', 'deidentified' => 1, 'file' => UploadedFile::fake()->createWithContent('bad.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('private_files', ['scan_status' => 'infected']);
        $this->get('/penerimaan/berkas/'.DB::table('private_files')->value('ulid'))->assertStatus(423);
        $this->mock(MalwareScanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('clean'));
        config(['admissions.qpdf_binary' => 'nonexistent-qpdf-test-binary']);
        $this->post('/penerimaan/berkas/placement/'.$p->ulid, ['category' => 'ijazah', 'deidentified' => 1, 'file' => UploadedFile::fake()->createWithContent('unreadable.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('private_files', ['scan_status' => 'held']);
    }
}
