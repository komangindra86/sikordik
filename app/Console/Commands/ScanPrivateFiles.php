<?php

namespace App\Console\Commands;

use App\Services\PrivateFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanPrivateFiles extends Command
{
    protected $signature = 'sikordik:scan-private-files {--limit=50}';

    protected $description = 'Periksa ulang karantina menggunakan ClamAV dan pemeriksa struktur file';

    public function handle(PrivateFileService $service): int
    {
        $files = DB::table('private_files')->whereIn('scan_status', ['pending', 'held'])->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        foreach ($files as $file) {
            $service->scan($file);
        }
        $this->info($files->count().' berkas diperiksa; hasil tersedia di metadata/audit.');

        return self::SUCCESS;
    }
}
