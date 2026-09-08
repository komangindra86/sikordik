<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapLocalAdmin extends Command
{
    protected $signature = 'sikordik:bootstrap-local-admin {--email=admin@sikordik.test}';

    protected $description = 'Buat administrator lokal pertama tanpa password statis atau menimpa akun yang ada';

    public function handle(AuditLogger $audit): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Bootstrap ini hanya untuk lokal/testing. Gunakan secret bootstrap deployment untuk produksi.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->fails()) {
            $this->error('Alamat email tidak valid.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($email, $audit) {
            $role = DB::table('roles')->where('code', 'super-admin')->lockForUpdate()->first();
            if (! $role) {
                $this->error('Jalankan migration dan seeder terlebih dahulu.');

                return self::FAILURE;
            }

            $administratorExists = DB::table('user_roles')->join('users', 'users.id', '=', 'user_roles.user_id')
                ->where('role_id', $role->id)->where('users.is_active', true)->whereNull('users.deleted_at')->exists();
            if ($administratorExists || DB::table('users')->where('email', $email)->exists()) {
                $this->error('Administrator aktif atau alamat email sudah ada. Tidak ada akun/password yang diubah.');

                return self::FAILURE;
            }

            $password = Str::password(24);
            $id = DB::table('users')->insertGetId([
                'name' => 'Administrator Lokal', 'email' => $email, 'password' => Hash::make($password),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role->id, 'assigned_at' => now()]);
            $audit->log('user.bootstrap_local', 'user', $id, 'Administrator pertama dibuat melalui CLI lokal.', newValues: ['email' => $email]);

            $path = 'bootstrap/'.Str::ulid().'.txt';
            Storage::disk('local')->put($path, "SIKORDIK - AKSES LOKAL\nEmail: {$email}\nPassword: {$password}\n\nGanti password setelah login melalui Pengguna > ubah akun sendiri.\nHapus file ini setelah kredensial disimpan di password manager.\nAlamat .test hanya untuk lokal; pengiriman email belum dikonfigurasi.\n");
            $this->info('Administrator lokal dibuat. Kredensial privat: '.Storage::disk('local')->path($path));

            return self::SUCCESS;
        });
    }
}
