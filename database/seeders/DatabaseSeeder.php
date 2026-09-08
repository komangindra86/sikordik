<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();

        foreach (config('rbac.permissions') as $code => [$name, $module]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'module' => $module, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        foreach (config('rbac.roles') as $code => [$name, $permissionCodes]) {
            DB::table('roles')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'is_system' => true, 'updated_at' => $now, 'created_at' => $now],
            );
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            DB::table('role_permissions')->where('role_id', $roleId)->delete();

            if ($permissionCodes !== ['*']) {
                $permissionIds = DB::table('permissions')->whereIn('code', $permissionCodes)->pluck('id');
                DB::table('role_permissions')->insert($permissionIds->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id])->all());
            }
        }

        foreach ([
            ['code' => 'KOAS', 'name' => 'Koas'],
            ['code' => 'RESIDEN', 'name' => 'Residen'],
            ['code' => 'NONKEDOKTERAN', 'name' => 'Nonkedokteran'],
        ] as $participantType) {
            DB::table('participant_types')->updateOrInsert(
                ['code' => $participantType['code']],
                array_merge($participantType, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            );
        }

        $email = env('INITIAL_ADMIN_EMAIL');
        $password = env('INITIAL_ADMIN_PASSWORD');
        if ($email && $password && ! DB::table('users')->where('email', $email)->exists()) {
            $userId = DB::table('users')->insertGetId([
                'name' => env('INITIAL_ADMIN_NAME', 'Super Admin'),
                'email' => mb_strtolower($email),
                'password' => Hash::make($password),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => DB::table('roles')->where('code', 'super-admin')->value('id'),
                'assigned_at' => $now,
            ]);
        }
    }
}
