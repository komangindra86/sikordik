<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class FileInspector
{
    public function inspect(string $path, string $mime): bool
    {
        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesize($path);

            if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 25000000 || $size['mime'] !== $mime) {
                return false;
            }
            $decoded = @imagecreatefromstring(file_get_contents($path));
            if (! $decoded) {
                return false;
            }
            imagedestroy($decoded);

            return true;
        }
        if ($mime !== 'application/pdf') {
            return false;
        }
        // Decode PDF structure before looking for active objects, including compressed objects.
        $out = $path.'.inspection';
        try {
            $check = new Process([config('admissions.qpdf_binary'), '--check', $path]);
            $check->setTimeout(15);
            $check->run();
            if (! $check->isSuccessful() || str_contains(str_replace('not encrypted', '', strtolower($check->getOutput())), 'encrypted')) {
                return false;
            }
            $decode = new Process([config('admissions.qpdf_binary'), '--qdf', '--object-streams=disable', '--stream-data=uncompress', $path, $out]);
            $decode->setTimeout(15);
            $decode->run();
            if (! $decode->isSuccessful() || ! is_file($out) || filesize($out) > 100 * 1024 * 1024) {
                return false;
            }
            $contents = file_get_contents($out);
            $contents = preg_replace_callback('/#([a-fA-F0-9]{2})/', fn ($m) => chr(hexdec($m[1])), $contents);

            return ! preg_match('~/\s*(JavaScript|JS|Launch|EmbeddedFile|Filespec|OpenAction|AA|RichMedia|XFA|Encrypt|SubmitForm|ImportData|GoToR)\b~i', $contents);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_file($out)) {
                unlink($out);
            }
        }
    }
}
