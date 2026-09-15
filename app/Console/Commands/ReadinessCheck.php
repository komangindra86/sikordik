<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ReadinessCheck extends Command
{
    protected $signature = 'sikordik:readiness';

    protected $description = 'Pemeriksaan konfigurasi produksi tanpa menampilkan secret atau mengubah data';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        $checks = [
            'Environment production' => app()->environment('production'),
            'Debug nonaktif' => ! config('app.debug'),
            'APP_KEY tersedia' => (bool) config('app.key'),
            'URL HTTPS nyata' => str_starts_with(config('app.url'), 'https://') && ! preg_match('/localhost|\.test|\.invalid|127\.0\.0\.1/', config('app.url')),
            'Cookie secure, HTTP-only, sesi terenkripsi' => config('session.secure') && config('session.http_only') && config('session.encrypt'),
            'Database runtime MySQL bukan root' => config('database.default') === 'mysql' && ! in_array($db['username'] ?? '', ['', 'root']),
            'SMTP dikonfigurasi' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host') && (bool) config('mail.from.address'),
            'Queue database' => config('queue.default') === 'database',
            'Secret backup terpisah' => strlen((string) config('operations.backup_password')) >= 32,
            'Build frontend tersedia' => is_file(public_path('build/manifest.json')),
        ];
        try {
            $process = new Process([config('admissions.qpdf_binary'), '--version']);
            $process->setTimeout(5);
            $process->run();
            $checks['qpdf berjalan'] = $process->isSuccessful();
        } catch (\Throwable) {
            $checks['qpdf berjalan'] = false;
        }
        $socket = @stream_socket_client('tcp://'.config('admissions.clamav_host').':'.config('admissions.clamav_port'), $errno, $error, 2);
        $checks['ClamAV merespons PING'] = false;
        if ($socket) {
            stream_set_timeout($socket, 2);
            fwrite($socket, "zPING\0");
            $checks['ClamAV merespons PING'] = trim((string) stream_get_line($socket, 100, "\0")) === 'PONG';
            fclose($socket);
        }
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '[OK] ' : '[BELUM] ').$label);
        }
        $this->warn('Pemeriksaan ini bukan izin go-live. Wajib UAT, delivery SMTP, scan nyata, worker/scheduler, grant audit, HTTPS/browser dan restore drill sesuai docs/UAT-FASE-8.md.');

        return in_array(false, array_map('boolval', $checks), true) ? self::FAILURE : self::SUCCESS;
    }
}
