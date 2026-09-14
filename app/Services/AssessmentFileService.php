<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssessmentFileService
{
    public function upload(User $u, int $id, string $resource, int $version, UploadedFile $file): object
    {
        abort_unless($file->isValid() && strtolower($file->getClientOriginalExtension()) === 'pdf' && $file->getSize() <= 10 * 1024 * 1024 &&
            (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) === 'application/pdf', 422, 'Berkas harus PDF maksimal 10 MB.');
        $ulid = (string) Str::ulid();
        $path = $file->storeAs('quarantine', $ulid.'.pdf', 'local');
        abort_unless($path, 422, 'Berkas gagal disimpan.');
        $fileId = DB::table('private_files')->insertGetId(['ulid' => $ulid, 'resource_type' => $resource, 'resource_id' => $id, 'category' => 'assessment', 'version' => $version,
            'path' => $path, 'original_name' => 'penilaian-v'.$version.'.pdf', 'mime' => 'application/pdf', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'pending', 'participant_visible' => false, 'deidentified' => true, 'uploaded_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->log('file.uploaded', 'private_file', $ulid, newValues: ['category' => 'assessment', 'version' => $version]);

        return DB::table('private_files')->find($fileId);
    }

    public function clean(?int $id, ?string $hash): void
    {
        if (! $id) {
            abort_if($hash !== null, 423, 'Referensi PDF tidak sesuai.');

            return;
        }
        $f = DB::table('private_files')->where('id', $id)->lockForUpdate()->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($hash && $f->scan_status === 'clean' && hash_equals($hash, $f->sha256) && $disk->exists($f->path) && hash_equals($hash, hash_file('sha256', $disk->path($f->path))), 423, 'PDF tertahan atau integritas berkas berubah.');
    }
}
