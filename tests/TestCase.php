<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $host = $app['config']->get("database.connections.{$connection}.host");
        $safe = ($connection === 'sqlite' && $database === ':memory:')
            || ($connection === 'mysql' && is_string($database) && str_ends_with($database, '_test') && in_array($host, ['127.0.0.1', 'localhost'], true));
        if (! $app->environment('testing') || ! $safe || $app['config']->get("database.connections.{$connection}.url")) {
            throw new \RuntimeException('Test hanya boleh memakai SQLite :memory: atau MySQL lokal dengan nama berakhiran _test. Database aplikasi tidak boleh digunakan.');
        }

        return $app;
    }

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
