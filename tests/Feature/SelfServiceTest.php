<?php

namespace Tests\Feature;

use App\Services\FileInspector;
use App\Services\MalwareScanner;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('ijazah.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
    }

    /** Placement accepted by Tim Kordik, documents still to be handed in. */
    private function awaitingDocuments(): array
    {
        $f = $this->schedulingFixture();
        DB::table('placements')->where('id', $f['p']->id)->update(['status' => 'menunggu_dokumen', 'document_status' => 'pending']);
        DB::table('placement_documents')->where('placement_id', $f['p']->id)->update(['status' => 'pending', 'reason' => null, 'reviewed_by' => null]);

        return $f;
    }

    public function test_user_changes_own_password_and_other_sessions_are_closed(): void
    {
        $user = $this->createUserWithRole('pembimbing', ['password' => Hash::make('kata-sandi-lama-123')]);
        $new = ['password' => 'kata-sandi-baru-456', 'password_confirmation' => 'kata-sandi-baru-456'];

        $this->actingAs($user)->get('/akun')->assertOk()->assertSee('Ganti kata sandi')->assertSee($user->email);
        $this->put('/akun/kata-sandi', ['current_password' => 'salah-total-000'] + $new)->assertSessionHasErrors('current_password');
        $this->put('/akun/kata-sandi', ['current_password' => 'kata-sandi-lama-123', 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertSessionHasErrors('password');
        $this->put('/akun/kata-sandi', ['current_password' => 'kata-sandi-lama-123', 'password' => 'kata-sandi-lama-123', 'password_confirmation' => 'kata-sandi-lama-123'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('kata-sandi-lama-123', $user->fresh()->password));

        DB::table('sessions')->insert(['id' => 'perangkat-lain', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->put('/akun/kata-sandi', ['current_password' => 'kata-sandi-lama-123'] + $new)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('kata-sandi-baru-456', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'perangkat-lain']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed', 'user_id' => $user->id]);
    }

    public function test_admin_activates_an_accepted_participant_and_hands_over_a_one_time_link(): void
    {
        $f = $this->awaitingDocuments();
        DB::table('participants')->where('id', $f['participant']->id)->update(['user_id' => null, 'email' => 'baru@peserta.test']);
        $this->assertContains('Aktifkan akun peserta', array_column(app(TaskService::class)->for($f['admin']), 'title'));

        $this->actingAs($f['admin'])->get('/penerimaan/penempatan/'.$f['p']->ulid)->assertOk()->assertSee('Peserta belum punya akun')->assertSee('baru@peserta.test');
        $response = $this->post('/penerimaan/peserta/'.$f['participant']->ulid.'/aktivasi', ['email' => 'baru@peserta.test', 'ownership_confirmed' => 1]);
        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('setup_link');
        $link = session('setup_link');
        $this->assertStringNotContainsString('baru@peserta.test', $link);
        $this->assertDatabaseHas('audit_logs', ['event' => 'participant.account_activated', 'user_id' => $f['admin']->id]);

        $this->post('/logout');
        $this->get($link)->assertOk()->assertSee('Kata sandi baru');
        $this->post('/reset-kata-sandi', ['token' => basename($link), 'email' => 'baru@peserta.test', 'password' => 'sandi-peserta-baru-1', 'password_confirmation' => 'sandi-peserta-baru-1'])->assertSessionHasNoErrors();
        $this->post('/login', ['email' => 'baru@peserta.test', 'password' => 'sandi-peserta-baru-1'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($f['participant']->id, DB::table('participants')->where('user_id', auth()->id())->value('id'));
    }

    public function test_participant_hands_in_own_documents_and_nothing_else(): void
    {
        Storage::fake('local');
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('clean');
        $this->mock(FileInspector::class)->shouldReceive('inspect')->andReturn(true);
        $f = $this->awaitingDocuments();
        $url = '/penerimaan/berkas/placement/'.$f['p']->ulid;
        $tasks = fn () => collect(app(TaskService::class)->for($f['owner']))->firstWhere('title', 'Unggah dokumen persyaratan');

        $adminTitles = fn () => array_column(app(TaskService::class)->for($f['admin']), 'title');
        $this->assertStringContainsString('Ijazah', $tasks()['items'][0]['detail']);
        // Nothing to check yet: the next step is the participant's.
        $this->assertNotContains('Periksa dokumen peserta', $adminTitles());
        $this->actingAs($f['owner'])->get('/penerimaan/penempatan/'.$f['p']->ulid)->assertOk()->assertSee('Unggah berkas')->assertDontSee('Nyatakan valid')->assertDontSee('Berkas pendukung lain');
        $this->post($url, ['category' => 'ijazah', 'deidentified' => 1, 'file' => $this->pdf()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('private_files', ['resource_type' => 'placement', 'resource_id' => $f['p']->id, 'category' => 'ijazah', 'uploaded_by' => $f['owner']->id, 'scan_status' => 'clean']);
        $this->assertStringNotContainsString('Ijazah', $tasks()['items'][0]['detail']);
        $this->assertContains('Periksa dokumen peserta', $adminTitles());

        $this->post($url, ['category' => 'pendukung', 'deidentified' => 1, 'file' => $this->pdf()])->assertForbidden();
        $letter = DB::table('incoming_letters')->value('ulid');
        $this->post('/penerimaan/berkas/letter/'.$letter, ['category' => 'surat', 'deidentified' => 1, 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($this->createUserWithRole('peserta'))->post($url, ['category' => 'ijazah', 'deidentified' => 1, 'file' => $this->pdf()])->assertForbidden();
        $this->assertSame(1, DB::table('private_files')->count());

        // Declaring a document valid stays with Admin Kordik.
        $file = DB::table('private_files')->first();
        $review = ['code' => 'ijazah', 'status' => 'valid', 'file_ulid' => $file->ulid];
        $this->actingAs($f['owner'])->post('/penerimaan/penempatan/'.$f['p']->ulid.'/dokumen', $review)->assertForbidden();
        $this->actingAs($f['admin'])->get('/penerimaan/penempatan/'.$f['p']->ulid)->assertOk()->assertSee('Nyatakan valid (versi 1)')->assertSee('Minta perbaikan');
        $this->post('/penerimaan/penempatan/'.$f['p']->ulid.'/dokumen', $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('placement_documents', ['placement_id' => $f['p']->id, 'code' => 'ijazah', 'status' => 'valid', 'reviewed_by' => $f['admin']->id]);
    }

    public function test_file_checks_are_only_skipped_on_a_local_machine_that_opted_in(): void
    {
        config(['admissions.clamav_host' => '127.0.0.1', 'admissions.clamav_port' => 1, 'admissions.qpdf_binary' => 'qpdf-tidak-terpasang']);
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        $this->assertSame('pending', (new MalwareScanner)->scan($path));
        $this->assertFalse((new FileInspector)->inspect($path, 'application/pdf'));

        // Opting in is not enough on its own: tests and servers keep the real checks.
        config(['admissions.scan_bypass' => true]);
        $this->assertSame('pending', (new MalwareScanner)->scan($path));
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertSame('clean', (new MalwareScanner)->scan($path));
        $this->assertTrue((new FileInspector)->inspect($path, 'application/pdf'));
        file_put_contents($path, 'bukan pdf');
        $this->assertFalse((new FileInspector)->inspect($path, 'application/pdf'));

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(MalwareScanner::bypassed());
        $this->assertSame('pending', (new MalwareScanner)->scan($path));
        unlink($path);
    }
}
