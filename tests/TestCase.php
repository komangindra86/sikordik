<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function createUserWithRole(string $roleCode = 'peserta', array $attributes = []): User
    {
        $this->seed();
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('code', $roleCode)->value('id'),
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
