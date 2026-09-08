<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_edit_reset_or_disable_existing_super_admin(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $super = $this->createUserWithRole('super-admin');
        $password = $super->password;
        $this->actingAs($admin)->get("/pengguna/{$super->id}/ubah")->assertForbidden();
        $this->put("/pengguna/{$super->id}", $this->userPayload($super->email))->assertForbidden();
        $this->patch("/pengguna/{$super->id}/status", ['is_active' => false, 'change_reason' => 'Percobaan pengambilalihan'])->assertForbidden();
        $this->assertSame($password, $super->fresh()->password);
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_admin_cannot_grant_disguised_privileged_custom_role_or_modify_its_holder(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $role = $this->customRole(['roles.manage']);
        $target = $this->createUserWithRole();
        DB::table('user_roles')->insert(['user_id' => $target->id, 'role_id' => $role, 'assigned_at' => now()]);
        $payload = $this->userPayload('escalate@example.test');
        $payload['role_ids'] = [$role];
        $this->actingAs($admin)->post('/pengguna', $payload)->assertSessionHasErrors('role_ids');
        $this->put("/pengguna/{$target->id}", $this->userPayload($target->email))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.test']);
    }

    public function test_super_admin_cannot_demote_or_disable_self(): void
    {
        $super = $this->createUserWithRole('super-admin');
        $this->actingAs($super)->put("/pengguna/{$super->id}", $this->userPayload($super->email))->assertSessionHasErrors('role_ids');
        $this->patch("/pengguna/{$super->id}/status", ['is_active' => false, 'change_reason' => 'Percobaan nonaktif sendiri'])->assertStatus(422);
        $this->assertContains('super-admin', $super->roleCodes());
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_delegated_role_manager_cannot_grant_permissions_it_does_not_have(): void
    {
        $user = $this->createUserWithRole();
        $role = $this->customRole(['roles.manage']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'assigned_at' => now()]);
        $this->actingAs($user)->post('/role', ['code' => 'escalation', 'name' => 'Eskalasi', 'permission_ids' => [DB::table('permissions')->where('code', 'users.update')->value('id')]])->assertSessionHasErrors('permission_ids');
        $this->assertDatabaseMissing('roles', ['code' => 'escalation']);
    }

    public function test_ksm_list_search_options_and_direct_resource_requests_obey_scope(): void
    {
        $user = $this->createUserWithRole('sekretariat-ksm');
        [$first, $second] = $this->departments();
        DB::table('user_scopes')->insert(['user_id' => $user->id, 'scope_type' => 'department', 'scope_id' => $first, 'assigned_at' => now()]);
        $role = DB::table('roles')->where('code', 'sekretariat-ksm')->value('id');
        $this->grant($role, ['masters.create', 'masters.update', 'masters.status']);
        foreach ([$first, $second] as $id) {
            DB::table('clinical_locations')->insert(['code' => 'LOC'.$id, 'name' => 'Lokasi-'.$id, 'department_id' => $id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('educators')->insert(['name' => 'Pendidik-'.$id, 'department_id' => $id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($user)->get('/master/departments')->assertSee('KSM A Rahasia')->assertDontSee('KSM B Rahasia');
        $this->get('/master/departments?q=KSM+B')->assertDontSee('KSM B Rahasia');
        $this->get('/master/clinical-locations')->assertSee('Lokasi-'.$first)->assertDontSee('Lokasi-'.$second);
        $this->get('/master/educators')->assertSee('Pendidik-'.$first)->assertDontSee('Pendidik-'.$second);
        $this->get('/master/educators/tambah')->assertSee('KSM A Rahasia')->assertDontSee('KSM B Rahasia');
        $this->get("/master/departments/{$second}/ubah")->assertNotFound();
        $this->put("/master/departments/{$second}", ['code' => 'B', 'name' => 'Diubah ilegal', 'change_reason' => 'Percobaan lintas KSM'])->assertNotFound();
        $this->patch("/master/departments/{$second}/status", ['is_active' => false, 'change_reason' => 'Percobaan lintas KSM'])->assertNotFound();
        $this->post('/master/clinical-locations', ['code' => 'BAD', 'name' => 'Lokasi Ilegal', 'department_id' => $second])->assertForbidden();
        $this->post('/master/clinical-locations', ['code' => 'GLOBAL', 'name' => 'Lokasi tanpa KSM'])->assertForbidden();
        $this->assertDatabaseHas('departments', ['id' => $second, 'name' => 'KSM B Rahasia', 'is_active' => true]);
        $this->assertDatabaseMissing('clinical_locations', ['code' => 'BAD']);
    }

    public function test_missing_department_scope_is_deny_by_default(): void
    {
        $user = $this->createUserWithRole('sekretariat-ksm');
        $this->departments();
        $this->actingAs($user)->get('/master/departments')->assertDontSee('KSM A Rahasia')->assertDontSee('KSM B Rahasia');
        $this->get('/dashboard')->assertSee('KSM dalam cakupan')->assertDontSee('Pengguna aktif');
    }

    public function test_master_duplicate_is_rejected_and_audit_has_no_mutation_routes(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $this->actingAs($admin)->post('/master/institutions', ['code' => 'DUP', 'name' => 'Institusi'])->assertSessionHasNoErrors();
        $this->post('/master/institutions', ['code' => 'DUP', 'name' => 'Duplikat'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('institutions', 1);
        $this->put('/audit-log/1', [])->assertNotFound();
        $this->delete('/audit-log/1')->assertNotFound();
    }

    private function userPayload(string $email): array
    {
        return ['name' => 'Nama Baru', 'email' => $email, 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password', 'role_ids' => [DB::table('roles')->where('code', 'peserta')->value('id')], 'change_reason' => 'Perubahan untuk pengujian'];
    }

    private function departments(): array
    {
        return array_map(fn ($code) => DB::table('departments')->insertGetId(['code' => $code, 'name' => "KSM {$code} Rahasia", 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]), ['A', 'B']);
    }

    private function customRole(array $permissions): int
    {
        $role = DB::table('roles')->insertGetId(['code' => 'custom', 'name' => 'Role Kustom', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($role, $permissions);

        return $role;
    }

    private function grant(int $role, array $permissions): void
    {
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
    }
}
