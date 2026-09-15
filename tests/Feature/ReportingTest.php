<?php

namespace Tests\Feature;

use App\Services\AssessmentService;
use App\Services\AuditLogger;
use App\Services\BackupService;
use App\Services\CompletionService;
use App\Services\DashboardService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Services\VerificationService;
use chillerlan\QRCode\QRCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CompletionFixtures;
use Tests\TestCase;
use ZipArchive;

class ReportingTest extends TestCase
{
    use CompletionFixtures, RefreshDatabase;

    public function test_all_reports_and_exports_respect_scope_and_hide_sensitive_fields(): void
    {
        $f = $this->completionFixture();
        $outside = $this->createUserWithRole('peserta');
        foreach (array_keys(ReportService::TYPES) as $type) {
            if ($type === 'audit') {
                continue;
            }
            $url = '/laporan?type='.$type.'&placement='.$f['p']->ulid;
            $this->actingAs($f['admin'])->get($url)->assertOk()->assertDontSee('SV-');
            $response = $this->actingAs($outside)->get($url);
            if ($type === 'letters') {
                $response->assertForbidden();
            } elseif (in_array($type, ['grades', 'logbooks'])) {
                $response->assertNotFound();
            } else {
                $response->assertOk()->assertDontSee('Peserta Pengujian');
            }
        }
        $this->actingAs($outside)->get('/laporan?type=audit&format=xlsx')->assertForbidden();
        $super = $this->createUserWithRole('super-admin');
        $this->actingAs($super)->get('/laporan?type=audit')->assertOk();
        $xlsx = $this->actingAs($f['owner'])->get('/laporan?format=xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $pdf = $this->get('/laporan?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', ['event' => 'reports.exported', 'user_id' => $f['owner']->id]);
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->get('/laporan?from=2026-12-31&to=2026-01-01')->assertRedirect()->assertSessionHasErrors('to');
    }

    public function test_grade_reports_use_published_version_even_when_new_draft_exists(): void
    {
        $f = $this->completionFixture();
        $v = DB::table('assessment_versions')->where('assessment_id', $f['grade']->id)->first();
        $s = json_decode($v->snapshot, true);
        $s['scores'] = array_map(fn ($score) => array_replace($score, ['value' => 'SECRET-DRAFT']), $s['scores']);
        DB::table('assessment_versions')->insert(['assessment_id' => $f['grade']->id, 'version' => 2, 'snapshot' => json_encode($s), 'sha256' => app(AssessmentService::class)->fingerprint($s), 'created_by' => $f['mentor']->id, 'created_at' => now()]);
        DB::table('assessments')->where('id', $f['grade']->id)->update(['current_version' => 2, 'status' => 'draft']);
        $url = '/laporan?type=grades&placement='.$f['p']->ulid;
        $this->actingAs($f['owner'])->get($url)->assertOk()->assertDontSee('SECRET-DRAFT')->assertSee('80');
        $this->actingAs($f['mentor'])->get($url)->assertOk()->assertSee('SECRET-DRAFT');
        $cards = collect(app(DashboardService::class)->data($f['owner'])['cards'])->keyBy('label');
        $this->assertEquals(0, $cards['Nilai belum dipublikasikan']['value']);
        DB::table('assessments')->where('id', $f['grade']->id)->update(['published_version' => null]);
        $this->actingAs($f['owner'])->get($url)->assertOk()->assertDontSee('Responsi akhir');
    }

    public function test_dashboards_work_for_every_role_without_cross_scope_counts(): void
    {
        $f = $this->completionFixture();
        foreach (['admin', 'chief', 'mentor', 'owner', 'kordik'] as $actor) {
            $this->actingAs($f[$actor])->get('/dashboard')->assertOk()->assertSee('Jadwal hari ini');
        }
        foreach (['super-admin', 'sekretariat-ksm', 'supervisor', 'peserta'] as $role) {
            $u = $this->createUserWithRole($role);
            $this->actingAs($u)->get('/dashboard')->assertOk();
            if ($role !== 'super-admin') {
                $cards = app(DashboardService::class)->data($u)['cards'];
                $this->assertEquals(0, array_sum(array_column($cards, 'value')));
            }
        }
    }

    public function test_qr_verification_checks_authorization_and_hash_for_each_document_type(): void
    {
        $f = $this->completionFixture();
        foreach (['submit' => 'admin', 'approve' => 'kordik'] as $action => $actor) {
            $r = DB::table('completion_requests')->latest('id')->first();
            app(CompletionService::class)->act($f[$actor], $f['p']->ulid, ['action' => $action, 'revision' => DB::table('placements')->where('id', $f['p']->id)->value('revision'), 'request_id' => $r?->id, 'request_revision' => $r?->revision, 'reason' => 'Pemeriksaan kelengkapan resmi', 'confirm' => 1]);
        }
        $entries = app(VerificationService::class)->entries($f['owner'], $f['p']->ulid);
        $this->assertCount(5, $entries);
        $outside = $this->createUserWithRole('peserta');
        foreach ($entries as $entry) {
            $url = '/verifikasi/'.$entry['type'].'/'.$entry['id'];
            $this->actingAs($f['owner'])->get($url)->assertOk()->assertSee('Hash snapshot sesuai')->assertSee('Pengesahan versi berlaku');
            $this->actingAs($outside)->get($url)->assertNotFound();
        }
        $this->actingAs($f['owner'])->get('/verifikasi?placement='.$f['p']->ulid)->assertOk();
        DB::table('attendance_summaries')->update(['snapshot' => '{}']);
        $this->get('/verifikasi/summary/'.DB::table('attendance_summaries')->value('id'))->assertStatus(423);
        DB::table('assessment_versions')->update(['snapshot' => '{}']);
        $id = DB::table('assessment_events')->where('action', 'publish')->value('id');
        $this->get('/verifikasi/grade/'.$id)->assertStatus(423);
    }

    public function test_verification_marks_reopened_completion_as_historical(): void
    {
        $f = $this->completionFixture();
        foreach ([['submit', 'admin'], ['approve', 'kordik'], ['reopen_request', 'admin'], ['approve', 'kordik'], ['reopen_execute', 'admin']] as [$action,$actor]) {
            $r = DB::table('completion_requests')->latest('id')->first();
            app(CompletionService::class)->act($f[$actor], $f['p']->ulid, ['action' => $action, 'revision' => DB::table('placements')->where('id', $f['p']->id)->value('revision'), 'request_id' => $r?->id, 'request_revision' => $r?->revision, 'reason' => 'Koreksi resmi pengesahan pendidikan', 'confirm' => 1]);
        }
        $id = DB::table('completion_requests')->where('kind', 'completion')->value('id');
        $this->actingAs($f['owner'])->get('/verifikasi/completion/'.$id)->assertOk()->assertSee('Riwayat pengesahan');
    }

    public function test_spreadsheet_encodes_formula_like_input_as_text(): void
    {
        $bytes = app(ReportExporter::class)->xlsx([['Nama'], ['=HYPERLINK("https://example.invalid")'], ['<script> & nama']]);
        $path = tempnam(storage_path('framework'), 'test-export-');
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertStringContainsString('t="inlineStr"', $xml);
            $this->assertStringContainsString('&lt;script&gt; &amp; nama', $xml);
            $this->assertNotFalse(simplexml_load_string($xml));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_encrypted_backup_recovers_rows_and_files_and_rejects_wrong_password(): void
    {
        $this->createUserWithRole('admin-kordik');
        Storage::fake('local');
        Storage::disk('local')->put('documents/test.pdf', 'private-file-fixture');
        Storage::disk('local')->put('bootstrap/credentials.txt', 'must-not-backup');
        $password = str_repeat('fixture-only-', 4);
        $service = app(BackupService::class);
        $path = $service->create($password);
        $manifest = $service->verify($path, $password);
        $this->assertArrayHasKey('database/users.jsonl', $manifest['entries']);
        $this->assertArrayHasKey('files/documents/test.pdf', $manifest['entries']);
        $this->assertArrayNotHasKey('files/bootstrap/credentials.txt', $manifest['entries']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setPassword($password);
        $rows = array_map(fn ($line) => json_decode($line, true), explode("\n", trim($zip->getFromName('database/users.jsonl'))));
        $this->assertSame(DB::table('users')->first()->email, $rows[0]['email']);
        $this->assertSame('private-file-fixture', $zip->getFromName('files/documents/test.pdf'));
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $service->verify($path, 'wrong-password');
    }

    public function test_guest_inactive_and_security_headers(): void
    {
        $this->get('/laporan')->assertRedirect('/login');
        $this->get('/verifikasi/grade/1')->assertRedirect('/login');
        $u = $this->createUserWithRole('peserta');
        $this->actingAs($u)->get('/laporan')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $u->update(['is_active' => false]);
        $this->get('/laporan')->assertRedirect('/login');
    }

    public function test_backup_restore_drill_and_safe_target_guard(): void
    {
        $this->completionFixture();
        $service = app(BackupService::class);
        $password = str_repeat('test-restore-secret-', 3);
        $path = $service->create($password);
        config(['database.connections.restore_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        try {
            $this->artisan('migrate', ['--database' => 'restore_test', '--force' => true])->assertSuccessful();
            $dir = $service->restoreDrill($path, $password, 'restore_test');
            $this->assertSame(DB::table('participants')->count(), DB::connection('restore_test')->table('participants')->count());
            $this->assertSame(DB::table('assessments')->value('ulid'), DB::connection('restore_test')->table('assessments')->value('ulid'));
            $this->assertSame([], DB::connection('restore_test')->select('PRAGMA foreign_key_check'));
            $file = DB::table('private_files')->first();
            $this->assertSame($file->sha256, hash_file('sha256', $dir.'/'.$file->path));
            $this->expectException(\RuntimeException::class);
            $service->restoreDrill($path, $password, 'restore_test');
        } finally {
            DB::purge('restore_test');
        }
    }

    public function test_qr_can_be_decoded_and_optional_qa_artifacts(): void
    {
        $f = $this->completionFixture();
        config(['app.url' => 'https://sikordik.example.test']);
        $entry = app(VerificationService::class)->entries($f['owner'], $f['p']->ulid)[0];
        $response = $this->actingAs($f['owner'])->get('/verifikasi/'.$entry['type'].'/'.$entry['id'])->assertOk();
        preg_match('~src="data:image/png;base64,([^"]+)"~', $response->getContent(), $match);
        $decoded = (new QRCode)->readFromBlob(base64_decode($match[1]));
        $this->assertSame('https://sikordik.example.test/verifikasi/'.$entry['type'].'/'.$entry['id'], (string) $decoded);
        if (getenv('SIKORDIK_RENDER_QA') === '1') {
            $dir = storage_path('app/phase8-qa');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir.'/verification.html', $response->getContent());
            file_put_contents($dir.'/dashboard.html', $this->get('/dashboard')->assertOk()->getContent());
            file_put_contents($dir.'/reports.html', $this->get('/laporan')->assertOk()->getContent());
            $rows = app(ReportService::class)->query($f['owner'], 'placements', [])->get()->map(fn ($r) => app(ReportService::class)->present($r))->all();
            file_put_contents($dir.'/report.pdf', app(ReportExporter::class)->pdf('Penempatan pendidikan', array_merge(...array_fill(0, 60, $rows))));
            file_put_contents($dir.'/report.xlsx', app(ReportExporter::class)->xlsx([array_keys($rows[0]), ...array_map('array_values', $rows)]));
        }
    }

    public function test_old_attendance_qr_preserves_approval_and_tampering_is_rejected(): void
    {
        $f = $this->completionFixture();
        $entry = collect(app(VerificationService::class)->entries($f['owner'], $f['p']->ulid))->firstWhere('type', 'attendance');
        $original = app(VerificationService::class)->verify($f['owner'], 'attendance', $entry['id']);
        DB::table('attendances')->where('id', $f['attendance']->id)->update(['revision' => 99, 'status' => 'corrected']);
        $historical = app(VerificationService::class)->verify($f['owner'], 'attendance', $entry['id']);
        $this->assertFalse($historical['current']);
        $this->assertSame($original['number'], $historical['number']);
        $history = DB::table('scheduling_histories')->where('id', $entry['id'])->first();
        $after = json_decode($history->after, true);
        $after['activity'] = 'Tampered';
        DB::table('scheduling_histories')->where('id', $entry['id'])->update(['after' => json_encode($after)]);
        $this->actingAs($f['owner'])->get('/verifikasi/attendance/'.$entry['id'])->assertStatus(423);
    }

    public function test_pdf_limit_is_rejected_and_export_failure_is_not_audited_as_success(): void
    {
        $f = $this->schedulingFixture();
        $batch = [];
        for ($i = 0; $i < 501; $i++) {
            $batch[] = ['ulid' => (string) Str::ulid(), 'institution_id' => $f['institution'], 'number' => 'LIMIT-'.$i, 'normalized_number' => 'LIMIT-'.$i, 'letter_date' => '2026-09-01', 'year' => 2026, 'subject' => 'Export cap fixture', 'created_by' => $f['admin']->id];
        }
        foreach (array_chunk($batch, 50) as $chunk) {
            DB::table('incoming_letters')->insert($chunk);
        }
        $this->actingAs($f['admin'])->get('/laporan?type=letters&format=pdf')->assertStatus(422);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'reports.exported']);
    }

    public function test_missing_surveys_are_reported_and_new_audit_secrets_are_filtered(): void
    {
        $f = $this->schedulingFixture();
        $rows = app(ReportService::class)->query($f['owner'], 'surveys', [])->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['belum_dimulai'], $rows->pluck('Status')->unique()->values()->all());
        app(AuditLogger::class)->log('test.secret_filter', newValues: ['nested' => ['backup_password' => 'never-log-this', 'access_token' => 'never-log-token'], 'app_key' => 'never-log-key']);
        $values = DB::table('audit_logs')->where('event', 'test.secret_filter')->value('new_values');
        $this->assertStringNotContainsString('never-log', $values);
    }
}
