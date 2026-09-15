<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BackupService
{
    public function create(string $password): string
    {
        if (strlen($password) < 32) {
            throw new \RuntimeException('Secret backup minimal 32 karakter.');
        }
        if (! app()->isDownForMaintenance() && ! app()->environment('testing')) {
            throw new \RuntimeException('Aktifkan maintenance dan hentikan worker sebelum backup konsisten.');
        }
        $disk = Storage::disk('local');
        $dir = 'backups/'.Str::ulid();
        $disk->makeDirectory($dir);
        $path = $disk->path($dir.'/sikordik.zip');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Backup tidak dapat dibuat.');
        }
        $manifest = ['format' => 1, 'created_at' => now()->toIso8601String(), 'entries' => []];
        $temporary = [];
        $add = function (string $name, string $file) use ($zip, $password, &$manifest): void {
            if (! $zip->addFile($file, $name) || ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
                throw new \RuntimeException('Enkripsi backup gagal.');
            }
            $manifest['entries'][$name] = hash_file('sha256', $file);
        };
        try {
            DB::transaction(function () use ($disk, $dir, $add, &$temporary) {
                foreach (Schema::getTableListing(DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : 'main', false) as $table) {
                    $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
                    if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                        throw new \RuntimeException('Nama tabel tidak didukung.');
                    }
                    $file = $disk->path($dir.'/'.$table.'.tmp');
                    $temporary[] = $file;
                    $handle = fopen($file, 'xb');
                    try {
                        foreach (DB::table($table)->cursor() as $row) {
                            $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                            if (fwrite($handle, $line) !== strlen($line)) {
                                throw new \RuntimeException('Penyimpanan backup penuh.');
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                    $add('database/'.$table.'.jsonl', $file);
                }
                foreach ($disk->allFiles() as $name) {
                    if (str_starts_with($name, 'backups/') || str_starts_with($name, 'restore-drills/') || str_starts_with($name, 'bootstrap/') || str_starts_with($name, 'demo/') || str_ends_with($name, '.gitignore')) {
                        continue;
                    }
                    $real = realpath($disk->path($name));
                    $root = realpath($disk->path(''));
                    if (! $real || ! str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $root), '/').'/') || is_link($disk->path($name))) {
                        throw new \RuntimeException('Berkas di luar disk privat ditolak.');
                    }
                    $add('files/'.$name, $real);
                }
            });
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)) || ! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password) || ! $zip->close()) {
                throw new \RuntimeException('Finalisasi backup gagal.');
            }
            $this->verify($path, $password);

            return $path;
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        } finally {
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function verify(string $path, string $password): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Arsip tidak dapat dibuka.');
        }
        try {
            $zip->setPassword($password);
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                throw new \RuntimeException('Secret salah atau manifest rusak.');
            }
            $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['entries'] ?? null) || count($manifest['entries']) + 1 !== $zip->numFiles) {
                throw new \RuntimeException('Format backup tidak cocok.');
            }
            foreach ($manifest['entries'] as $name => $hash) {
                if (! preg_match('~^(database/[a-zA-Z0-9_]+\.jsonl|files/[a-zA-Z0-9_./-]+)$~', $name) || str_contains($name, '..')) {
                    throw new \RuntimeException('Path arsip tidak aman.');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new \RuntimeException('Isi backup tidak dapat dipulihkan.');
                }
                try {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $stream);
                    $actual = hash_final($context);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($hash, $actual)) {
                    throw new \RuntimeException('Hash backup tidak sesuai.');
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function restoreDrill(string $path, string $password, ?string $connection = null): string
    {
        $db = DB::connection($connection);
        $safeMysql = $db->getDriverName() === 'mysql' && str_ends_with($db->getDatabaseName(), '_restore') && in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true);
        $safeTest = app()->environment('testing') && $db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:';
        if ((! app()->environment(['local', 'testing'])) || (! $safeMysql && ! $safeTest)) {
            throw new \RuntimeException('Restore hanya di local/testing, MySQL localhost berakhiran _restore (atau SQLite in-memory untuk test).');
        }
        $manifest = $this->verify($path, $password);
        $schema = $db->getSchemaBuilder();
        $tables = $schema->getTableListing($db->getDriverName() === 'mysql' ? $db->getDatabaseName() : 'main', false);
        foreach ($tables as $table) {
            if ($table !== 'migrations' && $db->table($table)->exists()) {
                throw new \RuntimeException('Target restore harus kosong setelah migrate tanpa seed.');
            }
        }
        $archiveTables = array_map(fn ($name) => basename($name, '.jsonl'), array_values(array_filter(array_keys($manifest['entries']), fn ($name) => str_starts_with($name, 'database/'))));
        sort($tables);
        sort($archiveTables);
        if ($tables !== $archiveTables) {
            throw new \RuntimeException('Skema target berbeda; gunakan commit dan migrasi yang sama dengan backup.');
        }
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setPassword($password);
        $dir = 'restore-drills/'.Str::ulid();
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        try {
            // Restore files before the transaction; failures leave a private drill
            // directory for diagnosis, never overwrite application file paths.
            foreach ($manifest['entries'] as $name => $hash) {
                if (! str_starts_with($name, 'files/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                try {
                    $disk->put($dir.'/'.substr($name, 6), $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($hash, hash_file('sha256', $disk->path($dir.'/'.substr($name, 6))))) {
                    throw new \RuntimeException('Hash file hasil restore tidak sesuai.');
                }
            }
            $schema->disableForeignKeyConstraints();
            try {
                $db->transaction(function () use ($db, $zip, $archiveTables) {
                    foreach ($archiveTables as $table) {
                        if ($table === 'migrations') {
                            continue;
                        }
                        $stream = $zip->getStream('database/'.$table.'.jsonl');
                        try {
                            while (($line = fgets($stream)) !== false) {
                                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                                $db->table($table)->insert($row);
                            }
                        } finally {
                            fclose($stream);
                        }
                    }
                });
            } finally {
                $schema->enableForeignKeyConstraints();
            }

            return $disk->path($dir);
        } finally {
            $zip->close();
        }
    }
}
