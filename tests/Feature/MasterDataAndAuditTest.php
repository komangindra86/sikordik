<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_data_create_and_update_are_audited(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $this->actingAs($admin)->post('/master/institutions', ['code' => 'UNIV', 'name' => 'Universitas Uji'])->assertSessionHasNoErrors();
        $id = DB::table('institutions')->value('id');

        $this->actingAs($admin)->put("/master/institutions/{$id}", ['code' => 'UNIV', 'name' => 'Universitas Uji Baru', 'change_reason' => 'Perubahan nama resmi'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('institutions', ['id' => $id, 'name' => 'Universitas Uji Baru']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'master.updated', 'reason' => 'Perubahan nama resmi']);
    }

    public function test_master_update_requires_reason(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $id = DB::table('participant_types')->where('code', 'KOAS')->value('id');
        $this->actingAs($admin)->put("/master/participant-types/{$id}", ['code' => 'KOAS', 'name' => 'Koas Baru'])->assertSessionHasErrors('change_reason');
    }

    public function test_audit_logger_filters_passwords(): void
    {
        $admin = $this->createUserWithRole('admin-kordik');
        $this->actingAs($admin);
        app(AuditLogger::class)->log('test.sensitive', 'test', 1, oldValues: ['password' => 'sangat-rahasia'], newValues: ['name' => 'Aman']);
        $log = DB::table('audit_logs')->where('event', 'test.sensitive')->first();

        $this->assertStringNotContainsString('sangat-rahasia', (string) $log->old_values);
    }
}
