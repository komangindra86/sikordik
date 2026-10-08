<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompletionService;
use App\Services\FileInspector;
use App\Services\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(10, 0));
    }

    private function workingFileChecks(): void
    {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->andReturn('clean');
        $this->mock(FileInspector::class)->shouldReceive('inspect')->andReturn(true);
    }

    public function test_demo_creates_connected_scenarios_without_overwriting_existing_users_and_is_repeatable(): void
    {
        $this->workingFileChecks();
        $existing = $this->createUserWithRole('super-admin');
        $password = $existing->password;
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        // Basic package (six placements) plus the advanced one (three more, dated around today).
        $this->assertDatabaseCount('users', 15);
        $this->assertDatabaseCount('participants', 9);
        $this->assertDatabaseCount('placements', 9);
        $this->assertDatabaseCount('schedules', 12);
        $this->assertDatabaseCount('attendances', 9);
        $this->assertDatabaseCount('attendance_summaries', 4);
        // Only the two generated practice logbooks; no requirement document is faked.
        $this->assertSame(2, DB::table('private_files')->where('resource_type', 'logbook')->count());
        $this->assertDatabaseCount('private_files', 2);
        $this->assertDatabaseHas('placements', ['status' => 'menunggu_konfirmasi_ksm']);
        $this->assertDatabaseHas('educator_assignments', ['status' => 'pending']);
        $this->assertDatabaseHas('attendance_summaries', ['status' => 'draft']);
        $this->assertDatabaseHas('attendance_summaries', ['status' => 'sealed']);
        $this->assertSame(1, DB::table('attendances')->where('status', 'waiting')->count());
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame('2026-09-10', today()->toDateString());

        $scenario = fn (string $key) => DB::table('placements')->whereIn('participant_id', DB::table('participants')->where('nim', 'DEMO-'.strtoupper($key))->select('id'))->first();
        $this->assertSame('terverifikasi', $scenario('jadwal')->status);
        $this->assertSame('2026-09-24', $scenario('jadwal')->end_date);
        $this->assertDatabaseHas('educator_assignments', ['placement_id' => $scenario('jadwal')->id, 'status' => 'approved']);
        $this->assertSame('sedang_stase', $scenario('tutup')->status);
        $this->assertTrue(app(CompletionService::class)->checklist($scenario('tutup'))['ready']);
        $this->assertSame('selesai', $scenario('selesai')->status);
        $this->assertDatabaseHas('completion_requests', ['placement_id' => $scenario('selesai')->id, 'status' => 'completed']);

        $handoff = Storage::disk('local')->get('demo/AKUN-DAN-PANDUAN-DUMMY.md');
        $this->assertStringContainsString('Paket lanjutan', $handoff);
        preg_match('/\| admin@demo\.sikordik\.test \| ([A-Za-z0-9]+) \|/', $handoff, $match);
        $this->assertNotEmpty($match);
        $this->assertTrue(Hash::check($match[1], DB::table('users')->where('email', 'admin@demo.sikordik.test')->value('password')));
        $this->post('/login', ['email' => 'admin@demo.sikordik.test', 'password' => $match[1]])->assertRedirect('/dashboard');
        $this->get('/presensi')->assertOk()->assertSee('DUMMY');
        $this->get('/dashboard')->assertOk()->assertSee('Periksa kelengkapan')->assertSee('DUMMY 08');
        foreach (['presensi', 'verifikasi', 'rekap', 'terkunci', 'tutup', 'selesai'] as $key) {
            $u = User::where('email', $key.'@demo.sikordik.test')->firstOrFail();
            $p = DB::table('placements')->whereIn('participant_id', DB::table('participants')->where('user_id', $u->id)->select('id'))->first();
            $this->actingAs($u)->get('/presensi/penempatan/'.$p->ulid)->assertOk();
            $this->get('/stase/'.$p->ulid)->assertOk();
        }
        $counts = DB::table('scheduling_histories')->count();
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        $this->assertDatabaseCount('users', 15);
        $this->assertDatabaseCount('participants', 9);
        $this->assertSame($counts, DB::table('scheduling_histories')->count());
        $this->assertSame($handoff, Storage::disk('local')->get('demo/AKUN-DAN-PANDUAN-DUMMY.md'));
    }

    public function test_basic_package_is_kept_when_the_advanced_one_cannot_be_built(): void
    {
        // No file scanner here: the practice logbook stays held, so the advanced package is rolled back as a whole.
        $this->artisan('sikordik:seed-local-demo')->expectsOutputToContain('Paket lanjutan tidak dibuat')->assertSuccessful();
        $this->assertDatabaseCount('users', 11);
        $this->assertDatabaseCount('placements', 6);
        $this->assertDatabaseCount('logbooks', 0);
        $this->assertDatabaseMissing('users', ['email' => 'jadwal@demo.sikordik.test']);
        $this->assertStringNotContainsString('Paket lanjutan', Storage::disk('local')->get('demo/AKUN-DAN-PANDUAN-DUMMY.md'));

        // Once checking works, the same command adds only what is missing.
        $this->workingFileChecks();
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        $this->assertDatabaseCount('users', 14);
        $this->assertDatabaseCount('placements', 9);
    }

    public function test_dry_run_does_not_write_and_production_is_rejected(): void
    {
        $this->artisan('sikordik:seed-local-demo', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('participants', 0);
        Storage::disk('local')->assertMissing('demo/AKUN-DAN-PANDUAN-DUMMY.md');
        $this->app->instance('env', 'production');
        $this->artisan('sikordik:seed-local-demo')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_demo_email_is_not_adopted_or_reset(): void
    {
        $user = $this->createUserWithRole('peserta', ['email' => 'admin@demo.sikordik.test']);
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('participants', 0);
        $this->assertSame(['peserta'], $user->fresh()->roleCodes());
        Storage::disk('local')->assertMissing('demo/AKUN-DAN-PANDUAN-DUMMY.md');
    }
}
