<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FoundationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_throttles_after_five_failures_then_recovers(): void
    {
        $user = $this->createUserWithRole(attributes: ['email' => 'limit@example.test', 'password' => Hash::make('a-secure-password')]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'LIMIT@example.test', 'password' => 'a-secure-password'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan masuk.', session('errors')->first('email'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => ' LIMIT@example.test ', 'password' => 'a-secure-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_delivery_is_generic_and_endpoint_is_throttled(): void
    {
        Notification::fake();
        $user = $this->createUserWithRole();
        $this->post('/lupa-kata-sandi', ['email' => $user->email])->assertSessionHasNoErrors();
        $message = session('status');
        $this->post('/lupa-kata-sandi', ['email' => 'unknown@example.test'])->assertSessionHas('status', $message);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post('/lupa-kata-sandi', ['email' => 'unknown@example.test'])->assertRedirect();
        $this->post('/lupa-kata-sandi', ['email' => 'unknown@example.test'])->assertStatus(429);
    }

    public function test_reset_revokes_sessions_rotates_remember_token_and_token_cannot_be_reused(): void
    {
        $user = $this->createUserWithRole(attributes: ['remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];

        $this->post('/reset-kata-sandi', $payload)->assertRedirect('/login');
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_reset', 'auditable_id' => (string) $user->id]);
        $this->post('/reset-kata-sandi', $payload)->assertSessionHasErrors('email');
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = $this->createUserWithRole();
        $oldPassword = $user->password;
        $token = Password::createToken($user);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post('/reset-kata-sandi', ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
        $this->assertSame($oldPassword, $user->fresh()->password);
    }

    public function test_deactivation_deletes_sessions_and_blocks_an_existing_login(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $user = $this->createUserWithRole(attributes: ['remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->patch("/pengguna/{$user->id}/status", ['is_active' => false, 'change_reason' => 'Akun sudah tidak digunakan'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_ends_authentication_and_is_audited(): void
    {
        $user = $this->createUserWithRole();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dynamic_pages_are_not_cacheable_and_private_files_have_no_public_route(): void
    {
        $this->get('/login')->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $user = $this->createUserWithRole();
        $this->actingAs($user)->get('/dashboard')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->get('/storage/private/secret.pdf')->assertNotFound();
    }

    public function test_reseeding_preserves_changed_system_roles_and_inactive_master_data(): void
    {
        $this->seed();
        $roleId = DB::table('roles')->where('code', 'admin-kordik')->value('id');
        DB::table('roles')->where('id', $roleId)->update(['name' => 'Nama yang dikustomisasi']);
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        DB::table('participant_types')->where('code', 'KOAS')->update(['name' => 'Koas Disesuaikan', 'is_active' => false]);
        $this->seed();
        $this->assertDatabaseHas('roles', ['id' => $roleId, 'name' => 'Nama yang dikustomisasi']);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $roleId]);
        $this->assertDatabaseHas('participant_types', ['code' => 'KOAS', 'name' => 'Koas Disesuaikan', 'is_active' => false]);
        $this->assertDatabaseCount('roles', 8);
        $this->assertDatabaseCount('permissions', 12);
    }

    public function test_local_admin_bootstrap_is_private_audited_and_cannot_overwrite_an_account(): void
    {
        $this->seed();
        Storage::fake('local');
        $this->artisan('sikordik:bootstrap-local-admin')->assertSuccessful();
        $user = User::where('email', 'admin@sikordik.test')->firstOrFail();
        $passwordHash = $user->password;
        $files = Storage::disk('local')->files('bootstrap');
        $this->assertCount(1, $files);
        preg_match('/Password: (.+)/', Storage::disk('local')->get($files[0]), $matches);
        $this->assertTrue(Hash::check($matches[1], $passwordHash));
        $this->assertContains('super-admin', $user->roleCodes());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.bootstrap_local']);
        $this->assertStringNotContainsString($matches[1], DB::table('audit_logs')->value('new_values'));
        $this->artisan('sikordik:bootstrap-local-admin')->assertFailed();
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_local_bootstrap_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('sikordik:bootstrap-local-admin')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
