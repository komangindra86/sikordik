<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'sikordik:backup {--verify= : Path arsip untuk verifikasi tanpa membuat backup}';

    protected $description = 'Backup database dan file privat terenkripsi; maintenance dan secret terpisah wajib';

    public function handle(BackupService $service): int
    {
        try {
            $password = (string) config('operations.backup_password');
            if (strlen($password) < 32) {
                throw new \RuntimeException('SIKORDIK_BACKUP_PASSWORD minimal 32 karakter wajib diisi melalui secret environment.');
            }
            if ($this->option('verify')) {
                $manifest = $service->verify($this->option('verify'), $password);
                $this->info('Dekripsi dan hash '.count($manifest['entries']).' entri cocok. Restore database terisolasi tetap diperlukan.');
            } else {
                $this->info('Backup terenkripsi dan terverifikasi: '.$service->create($password));
                $this->warn('Salin ke penyimpanan offsite. Simpan secret backup dan APP_KEY secara terpisah.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
