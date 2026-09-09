<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateFileService
{
    public function upload(User $actor, string $resource, string $ulid, string $category, UploadedFile $upload, bool $deidentified): object
    {
        abort_unless(app(AdmissionsAccess::class)->role($actor, ['admin-kordik']), 403);
        abort_unless($deidentified, 422, 'Pernyataan bebas identitas pasien wajib.');
        $table = match ($resource) {
            'letter' => 'incoming_letters', 'placement' => 'placements', 'participant' => 'participants', default => abort(404)
        };
        $allowed = match ($resource) {
            'letter' => ['surat'], 'participant' => ['foto'], 'placement' => ['ijazah', 'bhd', 'sip', 'str', 'kompetensi', 'pendukung', 'administrasi']
        };
        abort_unless(in_array($category, $allowed, true), 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        $ext = strtolower($upload->getClientOriginalExtension());
        $types = $category === 'foto' ? ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] : ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if ($category === 'surat') {
            $types = ['pdf' => 'application/pdf'];
        }
        if (! $upload->isValid() || ($types[$ext] ?? null) !== $mime || $upload->getSize() > ($category === 'foto' ? 3 : 10) * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Ekstensi, MIME, atau ukuran berkas tidak sesuai.']);
        }
        $fileUlid = (string) Str::ulid();
        $path = 'quarantine/'.$fileUlid.'.'.$ext;
        $upload->storeAs('quarantine', $fileUlid.'.'.$ext, 'local');
        // A failed transaction leaves a private quarantine orphan for operational review, never a public file.
        $file = DB::transaction(function () use ($actor, $resource, $ulid, $category, $upload, $mime, $fileUlid, $path, $table) {
            $parent = $resource === 'placement' ? app(PlacementService::class)->locked($ulid) : DB::table($table)->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
            $version = (int) DB::table('private_files')->where('resource_type', $resource)->where('resource_id', $parent->id)->where('category', $category)->max('version') + 1;
            $id = DB::table('private_files')->insertGetId([
                'ulid' => $fileUlid, 'resource_type' => $resource, 'resource_id' => $parent->id, 'category' => $category, 'version' => $version,
                'path' => $path, 'original_name' => mb_substr(preg_replace('/[^\pL\pN. _-]/u', '_', basename($upload->getClientOriginalName())), 0, 200),
                'mime' => $mime, 'size' => $upload->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
                'scan_status' => 'pending', 'participant_visible' => $resource === 'placement' && $category !== 'pendukung', 'deidentified' => true,
                'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->log('file.uploaded', 'private_file', $fileUlid, newValues: ['version' => $version, 'category' => $category]);

            return DB::table('private_files')->find($id);
        }, 5);
        $this->scan($file);

        return DB::table('private_files')->find($file->id);
    }

    public function scan(object $file): void
    {
        if ($file->scan_status === 'infected') {
            return;
        }
        $path = Storage::disk('local')->path($file->path);
        $status = is_file($path) && hash_equals($file->sha256, hash_file('sha256', $path)) ? app(MalwareScanner::class)->scan($path) : 'invalid';
        if ($status === 'clean') {
            if ($file->resource_type === 'import') {
                try {
                    app(ParticipantImportService::class)->readXlsx($path);
                } catch (ValidationException) {
                    $status = 'held';
                }
            } elseif (! app(FileInspector::class)->inspect($path, $file->mime)) {
                $status = 'held';
            }
        }
        DB::table('private_files')->where('id', $file->id)->update(['scan_status' => $status, 'scanned_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->log('file.scanned', 'private_file', $file->ulid, newValues: ['scan_status' => $status]);
    }
}
