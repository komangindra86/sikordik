<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class RestoreDrill extends Command
{
    protected $signature = 'sikordik:restore-drill {archive : Path backup terenkripsi}';

    protected $description = 'Pulihkan ke database MySQL lokal kosong berakhiran _restore; tidak menyentuh database operasional';

    public function handle(BackupService $service): int
    {
        try {
            $path = $service->restoreDrill($this->argument('archive'), (string) config('operations.backup_password'));
            $this->info('Database uji dipulihkan. File hasil restore dan hash terverifikasi: '.$path);
            $this->warn('Jangan aktifkan worker, SMTP, atau akses publik pada salinan uji. Verifikasi relasi, login, unduhan dan waktu pemulihan di lingkungan terisolasi.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(get_class($e) === \RuntimeException::class ? $e->getMessage() : 'Restore gagal. Periksa kompatibilitas skema/format dan akses penyimpanan; isi baris database tidak ditampilkan.');

            return self::FAILURE;
        }
    }
}
