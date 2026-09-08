<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_multi_role_user_with_department_scope_and_audit(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $department = DB::table('departments')->insertGetId(['code' => 'PD', 'name' => 'Penyakit Dalam', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $roles = DB::table('roles')->whereIn('code', ['sekretariat-ksm', 'pembimbing'])->pluck('id')->all();

        $this->actingAs($admin)->post('/pengguna', [
            'name' => 'Pengguna Baru', 'email' => 'baru@example.test', 'password' => 'kata-sandi-aman',
            'password_confirmation' => 'kata-sandi-aman', 'role_ids' => $roles, 'department_ids' => [$department],
        ])->assertSessionHasNoErrors();

        $userId = DB::table('users')->where('email', 'baru@example.test')->value('id');
        $this->assertNotNull($userId);
        $this->assertDatabaseCount('user_roles', 3);
        $this->assertDatabaseHas('user_scopes', ['user_id' => $userId, 'scope_type' => 'department', 'scope_id' => $department]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'auditable_id' => (string) $userId]);
    }

    public function test_ksm_role_requires_scope(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $role = DB::table('roles')->where('code', 'sekretariat-ksm')->value('id');

        $this->actingAs($admin)->from('/pengguna/tambah')->post('/pengguna', [
            'name' => 'Tanpa Scope', 'email' => 'tanpa@example.test', 'password' => 'kata-sandi-aman',
            'password_confirmation' => 'kata-sandi-aman', 'role_ids' => [$role],
        ])->assertSessionHasErrors('department_ids');

        $this->assertDatabaseMissing('users', ['email' => 'tanpa@example.test']);
    }

    public function test_admin_cannot_grant_super_admin_role(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $role = DB::table('roles')->where('code', 'super-admin')->value('id');

        $this->actingAs($admin)->post('/pengguna', [
            'name' => 'Eskalasi', 'email' => 'eskalasi@example.test', 'password' => 'kata-sandi-aman',
            'password_confirmation' => 'kata-sandi-aman', 'role_ids' => [$role],
        ])->assertSessionHasErrors('role_ids');
    }
}
