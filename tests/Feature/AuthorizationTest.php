<?php

namespace Tests\Feature;

use App\Services\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_cannot_access_user_management(): void
    {
        $participant = $this->createUserWithRole('peserta');
        $this->actingAs($participant)->get('/pengguna')->assertForbidden();
    }

    public function test_admin_kordik_can_access_user_and_master_management(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $this->actingAs($admin)->get('/pengguna')->assertOk();
        $this->actingAs($admin)->get('/master/institutions')->assertOk();
        $this->actingAs($admin)->get('/role')->assertForbidden();
    }

    public function test_super_admin_can_manage_role_permissions(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $this->actingAs($superAdmin)->get('/role')->assertOk();

        $permission = \DB::table('permissions')->where('code', 'dashboard.view')->value('id');
        $this->actingAs($superAdmin)->post('/role', [
            'code' => 'auditor-kustom', 'name' => 'Auditor Kustom', 'permission_ids' => [$permission],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roles', ['code' => 'auditor-kustom', 'is_system' => false]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.created']);
    }

    public function test_ksm_scope_service_rejects_other_department(): void
    {
        $user = $this->createUserWithRole('sekretariat-ksm');
        $first = \DB::table('departments')->insertGetId(['code' => 'A', 'name' => 'KSM A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $second = \DB::table('departments')->insertGetId(['code' => 'B', 'name' => 'KSM B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('user_scopes')->insert(['user_id' => $user->id, 'scope_type' => 'department', 'scope_id' => $first, 'assigned_at' => now()]);

        $service = app(UserAccessService::class);
        $this->assertTrue($service->canAccessDepartment($user, $first));
        $this->assertFalse($service->canAccessDepartment($user, $second));
    }
}
