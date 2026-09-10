<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_demo_creates_connected_scenarios_without_overwriting_existing_users_and_is_repeatable(): void
    {
        $existing = $this->createUserWithRole('super-admin');
        $password = $existing->password;
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        $this->assertDatabaseCount('users', 12);
        $this->assertDatabaseCount('participants', 6);
        $this->assertDatabaseCount('placements', 6);
        $this->assertDatabaseCount('schedules', 8);
        $this->assertDatabaseCount('attendances', 5);
        $this->assertDatabaseCount('attendance_summaries', 2);
        $this->assertDatabaseCount('private_files', 0);
        $this->assertDatabaseHas('placements', ['status' => 'menunggu_konfirmasi_ksm']);
        $this->assertDatabaseHas('educator_assignments', ['status' => 'pending']);
        $this->assertDatabaseHas('attendance_summaries', ['status' => 'draft']);
        $this->assertDatabaseHas('attendance_summaries', ['status' => 'sealed']);
        $this->assertSame(1, DB::table('attendances')->where('status', 'waiting')->count());
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame('2026-09-10', today()->toDateString());
        $handoff = Storage::disk('local')->get('demo/AKUN-DAN-PANDUAN-DUMMY.md');
        preg_match('/\| admin@demo\.sikordik\.test \| ([A-Za-z0-9]+) \|/', $handoff, $match);
        $this->assertNotEmpty($match);
        $this->assertTrue(Hash::check($match[1], DB::table('users')->where('email', 'admin@demo.sikordik.test')->value('password')));
        $this->post('/login', ['email' => 'admin@demo.sikordik.test', 'password' => $match[1]])->assertRedirect('/dashboard');
        $this->get('/presensi')->assertOk()->assertSee('DUMMY');
        foreach (['presensi', 'verifikasi', 'rekap', 'terkunci'] as $key) {
            $u = User::where('email', $key.'@demo.sikordik.test')->firstOrFail();
            $p = DB::table('placements')->whereIn('participant_id', DB::table('participants')->where('user_id', $u->id)->select('id'))->first();
            $this->actingAs($u)->get('/presensi/penempatan/'.$p->ulid)->assertOk();
        }
        $counts = DB::table('scheduling_histories')->count();
        $this->artisan('sikordik:seed-local-demo')->assertSuccessful();
        $this->assertDatabaseCount('users', 12);
        $this->assertDatabaseCount('participants', 6);
        $this->assertSame($counts, DB::table('scheduling_histories')->count());
        $this->assertSame($handoff, Storage::disk('local')->get('demo/AKUN-DAN-PANDUAN-DUMMY.md'));
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
