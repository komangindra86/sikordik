<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LogbookService
{
    public const LABELS = ['draft' => 'Draft', 'submitted' => 'Diajukan', 'revision' => 'Perlu revisi', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'locked' => 'Dikunci'];

    public function fingerprint(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function writable(object $p): void
    {
        abort_unless(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']), 422, 'Logbook hanya dapat diubah pada penempatan berjalan atau menunggu penyelesaian. Data selesai dikunci.');
    }

    private function assignment(int $id, object $p, string $role, ?string $date = null): object
    {
        $a = DB::table('educator_assignments')->where('id', $id)->where('placement_id', $p->id)->where('role', $role)->where('status', 'approved')->lockForUpdate()->first();
        abort_unless($a, 422, 'Penugasan pemeriksa atau pembimbing tidak berlaku.');
        $u = User::find($a->educator_user_id);
        abort_unless($u && app(LogbookAccess::class)->assignments($u, $role)->where('a.id', $id)->exists(), 422, 'Akun penugasan berubah atau tidak aktif.');
        abort_if($date && ($date < $a->start_date || $date > $a->end_date), 422, 'Tanggal kegiatan di luar penugasan.');

        return $a;
    }

    public function save(User $actor, string $placement, array $input, ?UploadedFile $upload, ?string $ulid = null): object
    {
        $d = Validator::make($input, [
            'kind' => 'required|in:participant,educator', 'type' => 'required|string|max:100', 'revision' => 'required|integer|min:0',
            'reviewer_assignment_id' => 'required|integer', 'author_assignment_id' => 'required_if:kind,educator|nullable|integer',
            'notes' => 'nullable|string|max:2000', 'deidentified' => 'required|accepted',
            'date' => 'required_if:kind,educator|nullable|date_format:Y-m-d',
            'clinical_location_id' => 'required_if:kind,educator|nullable|integer',
            'start_time' => 'required_if:kind,educator|nullable|date_format:H:i', 'end_time' => 'required_if:kind,educator|nullable|date_format:H:i|after:start_time',
            'material' => 'required_if:kind,educator|nullable|string|max:4000',
        ])->validate();
        $file = null;
        $row = DB::transaction(function () use ($actor, $placement, $d, $upload, $ulid, &$file) {
            $p = app(PlacementService::class)->locked($placement);
            $this->writable($p);
            $access = app(LogbookAccess::class);
            $old = $ulid ? DB::table('logbooks')->where('placement_id', $p->id)->where('ulid', $ulid)->lockForUpdate()->firstOrFail() : null;
            if ($old) {
                abort_unless($access->author($actor, $p, $old), 403);
                abort_unless(in_array($old->status, ['draft', 'revision', 'rejected']) && $old->kind === $d['kind'] && (int) $old->revision === (int) $d['revision'], 422, 'Status atau versi berubah. Muat ulang.');
            } else {
                abort_unless((int) $d['revision'] === 0, 422, 'Versi awal harus nol.');
            }
            $author = null;
            if ($d['kind'] === 'participant') {
                abort_unless($access->owner($actor, $p), 403);
                abort_unless($upload, 422, 'PDF wajib diunggah untuk setiap versi.');
                $duplicate = DB::table('logbooks')->where('placement_id', $p->id)->where('kind', 'participant')->where('type', $d['type']);
                if ($old) {
                    $duplicate->where('id', '!=', $old->id);
                }
                abort_if($duplicate->lockForUpdate()->exists(), 422, 'Jenis logbook sudah ada. Tambahkan versi pada logbook tersebut.');
            } else {
                $author = $this->assignment((int) $d['author_assignment_id'], $p, 'mentor', $d['date']);
                abort_unless((int) $author->educator_user_id === (int) $actor->id, 403);
                abort_if($d['date'] < $p->start_date || $d['date'] > $p->end_date || $d['date'] > now()->toDateString(), 422, 'Tanggal harus sudah berlangsung dan berada dalam periode penempatan.');
                abort_unless(DB::table('clinical_locations')->where('id', $d['clinical_location_id'])->where('department_id', $p->department_id)->where('is_active', true)->exists(), 422, 'Lokasi tidak sesuai KSM.');
            }
            $reviewer = $this->assignment((int) $d['reviewer_assignment_id'], $p, $d['kind'] === 'participant' ? 'mentor' : 'supervisor', $d['kind'] === 'educator' ? $d['date'] : null);
            abort_if((int) $reviewer->educator_user_id === (int) $actor->id, 403, 'Pengesahan sendiri tidak diizinkan.');
            $version = ($old->current_version ?? 0) + 1;
            $values = ['type' => $d['type'], 'author_assignment_id' => $author?->id, 'reviewer_assignment_id' => $reviewer->id,
                'status' => 'draft', 'current_version' => $version, 'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $id = $old->id;
                DB::table('logbooks')->where('id', $id)->update($values);
            } else {
                $id = DB::table('logbooks')->insertGetId($values + ['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'kind' => $d['kind'], 'author_id' => $actor->id, 'created_at' => now()]);
            }
            if ($upload) {
                $file = $this->upload($actor, $id, $version, $upload);
            }
            $snapshot = ['institution_id' => $p->institution_id, 'institution' => json_decode($p->snapshot)->institution,
                'department_id' => $p->department_id, 'department' => json_decode($p->snapshot)->department,
                'participant' => DB::table('participants')->where('id', $p->participant_id)->value('name'),
                'author' => $actor->name, 'author_assignment_id' => $author?->id, 'type' => $d['type'], 'notes' => $d['notes'] ?? null,
                'file_hash' => $file?->sha256, 'reviewer_assignment_id' => $reviewer->id];
            if ($d['kind'] === 'educator') {
                $snapshot += array_intersect_key($d, array_flip(['date', 'start_time', 'end_time', 'material', 'clinical_location_id']));
                $snapshot['location'] = DB::table('clinical_locations')->where('id', $d['clinical_location_id'])->value('name');
                $snapshot['duration_minutes'] = (int) ((strtotime($d['end_time']) - strtotime($d['start_time'])) / 60);
                // Prevent exact duplicate activity submissions, while allowing different activities on the same day.
                $others = DB::table('logbooks as l')->join('logbook_versions as v', function ($q) {
                    $q->on('v.logbook_id', '=', 'l.id')->on('v.version', '=', 'l.current_version');
                })->where('l.placement_id', $p->id)->where('l.author_id', $actor->id)->where('l.kind', 'educator')->where('l.id', '!=', $id)->lockForUpdate()->get(['v.snapshot']);
                foreach ($others as $other) {
                    $s = json_decode($other->snapshot, true);
                    abort_if($s['date'] === $d['date'] && $s['start_time'] === $d['start_time'] && $s['end_time'] === $d['end_time'] && $s['type'] === $d['type'], 422, 'Kegiatan yang sama sudah dicatat.');
                }
            }
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            DB::table('logbook_versions')->insert(['logbook_id' => $id, 'version' => $version, 'private_file_id' => $file?->id,
                'reviewer_assignment_id' => $reviewer->id, 'snapshot' => $json, 'sha256' => $this->fingerprint($snapshot), 'created_by' => $actor->id, 'created_at' => now()]);
            app(SchedulingJournal::class)->record($actor, 'logbooks', $id, 'logbook_version_saved', $old);

            return DB::table('logbooks')->find($id);
        }, 5);
        if ($file) {
            app(PrivateFileService::class)->scan($file);
        }

        return $row;
    }

    private function upload(User $actor, int $id, int $version, UploadedFile $upload): object
    {
        abort_unless($upload->isValid() && strtolower($upload->getClientOriginalExtension()) === 'pdf' && $upload->getSize() <= 10 * 1024 * 1024 &&
            (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()) === 'application/pdf', 422, 'Berkas harus PDF maksimal 10 MB.');
        $hash = hash_file('sha256', $upload->getRealPath());
        abort_if(DB::table('private_files')->where('resource_type', 'logbook')->where('resource_id', $id)->where('sha256', $hash)->exists(), 422, 'PDF yang sama sudah tersimpan dalam riwayat.');
        $ulid = (string) Str::ulid();
        $path = $upload->storeAs('quarantine', $ulid.'.pdf', 'local');
        abort_unless($path, 422, 'Berkas gagal disimpan.');
        // A rollback may leave a private quarantine orphan, never a public file.
        $fileId = DB::table('private_files')->insertGetId(['ulid' => $ulid, 'resource_type' => 'logbook', 'resource_id' => $id, 'category' => 'logbook', 'version' => $version,
            'path' => $path, 'original_name' => 'logbook-v'.$version.'.pdf', 'mime' => 'application/pdf', 'size' => $upload->getSize(), 'sha256' => $hash,
            'scan_status' => 'pending', 'participant_visible' => false, 'deidentified' => true, 'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->log('file.uploaded', 'private_file', $ulid, newValues: ['version' => $version, 'category' => 'logbook']);

        return DB::table('private_files')->find($fileId);
    }

    public function cleanFile(object $version): void
    {
        $snapshot = json_decode($version->snapshot, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($version->sha256, $this->fingerprint($snapshot)), 423, 'Integritas versi berubah.');
        if (! $version->private_file_id) {
            return;
        }
        $file = DB::table('private_files')->where('id', $version->private_file_id)->lockForUpdate()->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($file->scan_status === 'clean' && hash_equals($snapshot['file_hash'], $file->sha256) && $disk->exists($file->path) && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path))), 423, 'PDF masih tertahan, ditolak, atau integritas berkas berubah.');
    }

    public function transition(User $actor, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:submit,approve,revision,reject', 'revision' => 'required|integer|min:1',
            'note' => 'required_unless:action,submit|nullable|string|min:5|max:2000', 'confirm' => 'required|accepted'])->validate();
        DB::transaction(function () use ($actor, $ulid, $d) {
            $ref = DB::table('logbooks')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $ref->placement_id)->value('ulid'));
            $r = DB::table('logbooks')->where('id', $ref->id)->lockForUpdate()->firstOrFail();
            $this->writable($p);
            $access = app(LogbookAccess::class);
            abort_unless($d['action'] === 'submit' ? $access->author($actor, $p, $r) : $access->reviewer($actor, $r), 403);
            abort_unless((int) $r->revision === (int) $d['revision'] && ($d['action'] === 'submit' ? $r->status === 'draft' : $r->status === 'submitted'), 422, 'Status atau versi berubah. Muat ulang.');
            $v = DB::table('logbook_versions')->where('logbook_id', $r->id)->where('version', $r->current_version)->lockForUpdate()->firstOrFail();
            $s = json_decode($v->snapshot, true);
            abort_unless(hash_equals($v->sha256, $this->fingerprint($s)), 423, 'Integritas versi berubah.');
            $reviewer = $this->assignment($r->reviewer_assignment_id, $p, $r->kind === 'participant' ? 'mentor' : 'supervisor', $s['date'] ?? null);
            if ($r->kind === 'educator') {
                $this->assignment($r->author_assignment_id, $p, 'mentor', $s['date']);
            } else {
                abort_unless(DB::table('participants')->where('id', $p->participant_id)->where('user_id', $r->author_id)->exists(), 422, 'Tautan akun peserta berubah.');
            }
            if (in_array($d['action'], ['submit', 'approve'])) {
                $this->cleanFile($v);
            }
            $status = ['submit' => 'submitted', 'approve' => 'approved', 'revision' => 'revision', 'reject' => 'rejected'][$d['action']];
            $approval = null;
            $reviewUlid = (string) Str::ulid();
            if ($d['action'] === 'approve') {
                $approval = json_encode(['document_number' => 'LB-'.$reviewUlid, 'user_id' => $actor->id, 'name' => $actor->name,
                    'role' => $r->kind === 'participant' ? 'pembimbing' : 'supervisor', 'position' => $r->kind === 'participant' ? 'Pembimbing penempatan' : 'Supervisor penempatan',
                    'assignment_id' => $reviewer->id, 'approved_at' => now()->toIso8601String(), 'version' => $r->current_version, 'sha256' => $v->sha256, 'file_hash' => $s['file_hash']], JSON_THROW_ON_ERROR);
            }
            DB::table('logbook_reviews')->insert(['ulid' => $reviewUlid, 'logbook_id' => $r->id, 'version' => $r->current_version, 'action' => $d['action'],
                'note' => $d['note'] ?? null, 'actor_id' => $actor->id, 'approval' => $approval, 'created_at' => now()]);
            DB::table('logbooks')->where('id', $r->id)->update(['status' => $status, 'revision' => $r->revision + 1, 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($actor, 'logbooks', $r->id, 'logbook_'.$status, $r, $d['note'] ?? null);
            app(SchedulingJournal::class)->notify([$d['action'] === 'submit' ? $reviewer->educator_user_id : $r->author_id], $p, 'Logbook '.$r->type.': '.self::LABELS[$status].'.');
        }, 5);
    }
}
