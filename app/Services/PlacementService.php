<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlacementService
{
    public const RESERVING = ['menunggu_konfirmasi_ksm', 'diterima_ksm', 'menunggu_persetujuan_kordik', 'menunggu_dokumen', 'terverifikasi', 'dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai'];

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['placement' => $message]);
    }

    public function create(User $actor, array $input): object
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $data = Validator::make($input, [
            'participant_ulid' => 'required|exists:participants,ulid', 'letter_ulid' => 'required|exists:incoming_letters,ulid',
            'study_program_id' => 'required|integer|exists:study_programs,id,is_active,1',
            'participant_type_id' => 'required|integer|exists:participant_types,id,is_active,1',
            'department_id' => 'required|integer|exists:departments,id,is_active,1',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $participant = DB::table('participants')->where('ulid', $data['participant_ulid'])->lockForUpdate()->firstOrFail();
            $letter = DB::table('incoming_letters')->where('ulid', $data['letter_ulid'])->firstOrFail();
            $program = DB::table('study_programs')->find($data['study_program_id']);
            if ((int) $program->institution_id !== (int) $letter->institution_id) {
                $this->fail('Program harus berasal dari institusi pengirim surat.');
            }
            $type = DB::table('participant_types')->find($data['participant_type_id']);
            $institution = DB::table('institutions')->find($letter->institution_id);
            abort_unless($institution->is_active, 422, 'Institusi tidak aktif.');
            $department = DB::table('departments')->find($data['department_id']);
            $id = DB::table('placements')->insertGetId([
                'ulid' => (string) Str::ulid(), 'participant_id' => $participant->id, 'incoming_letter_id' => $letter->id,
                'institution_id' => $institution->id, 'study_program_id' => $program->id, 'participant_type_id' => $type->id,
                'department_id' => $department->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'snapshot' => json_encode(['institution' => $institution->name, 'program' => $program->name, 'participant_type' => $type->name, 'department' => $department->name], JSON_THROW_ON_ERROR),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $defaults = ['surat' => 'Surat pengantar', 'ijazah' => 'Ijazah'];
            if (in_array($type->code, ['KOAS', 'RESIDEN'])) {
                $defaults['bhd'] = 'Sertifikat BHD';
            }
            if ($type->code === 'RESIDEN') {
                $defaults += ['sip' => 'SIP', 'str' => 'STR', 'kompetensi' => 'Sertifikat kompetensi'];
            }
            $templates = DB::table('document_templates')->where('participant_type_id', $type->id)->where('is_active', true);
            foreach (['institution_id' => $institution->id, 'study_program_id' => $program->id, 'department_id' => $department->id] as $key => $value) {
                $templates->where(fn ($q) => $q->whereNull($key)->orWhere($key, $value));
            }
            foreach ($templates->orderBy('id')->get() as $template) {
                $defaults[$template->code] = $template->label;
            }
            foreach ($defaults as $code => $label) {
                DB::table('placement_documents')->insert(['placement_id' => $id, 'code' => $code, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
            $placement = DB::table('placements')->find($id);
            $this->history($actor, $placement, null, 'created', null);

            return $placement;
        }, 5);
    }

    public function locked(string $ulid): object
    {
        $id = DB::table('placements')->where('ulid', $ulid)->value('participant_id');
        abort_unless($id, 404);
        DB::table('participants')->where('id', $id)->lockForUpdate()->firstOrFail();

        return DB::table('placements')->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
    }

    public function conflicts(object $placement, bool $locking = false): Collection
    {
        $end = $placement->status === 'selesai' ? ($placement->actual_end_date ?? $placement->end_date) : $placement->end_date;
        $q = DB::table('placements')->where('participant_id', $placement->participant_id)->where('id', '!=', $placement->id)
            ->whereIn('status', self::RESERVING)->where('start_date', '<=', $end)
            ->whereRaw("CASE WHEN status = 'selesai' THEN COALESCE(actual_end_date, end_date) ELSE end_date END >= ?", [$placement->start_date]);

        return ($locking ? $q->lockForUpdate() : $q)->get();
    }

    public function fingerprint(object $a, object $b): string
    {
        $rows = [$a, $b];
        usort($rows, fn ($x, $y) => $x->id <=> $y->id);

        return hash('sha256', json_encode(array_map(fn ($p) => [$p->id, $p->department_id, $p->start_date, $p->end_date, $p->actual_end_date, $p->revision], $rows), JSON_THROW_ON_ERROR));
    }

    public function checkOverlap(object $p): void
    {
        foreach ($this->conflicts($p, true) as $other) {
            if ((int) $other->department_id === (int) $p->department_id) {
                $this->fail('Periode inklusif bentrok dalam KSM yang sama. Ubah placement yang sudah ada.');
            }
            $allowed = DB::table('overlap_exceptions')->where('status', 'approved')->where('fingerprint', $this->fingerprint($p, $other))->lockForUpdate()->exists();
            if (! $allowed) {
                $this->fail('Periode lintas KSM bentrok. Persetujuan pengecualian Tim Kordik diperlukan sebelum pengajuan.');
            }
        }
    }

    public function transition(User $actor, string $ulid, string $action, string $expected, int $revision, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $ulid, $action, $expected, $revision, $reason) {
            $p = $this->locked($ulid);
            app(AdmissionsAccess::class)->placement($actor, $ulid);
            if ($p->status !== $expected || (int) $p->revision !== $revision) {
                $this->fail('Data berubah. Muat ulang sebelum melanjutkan.');
            }
            $access = app(AdmissionsAccess::class);
            $map = [
                'submit' => ['draft', 'menunggu_konfirmasi_ksm', 'admin'],
                'ksm_accept' => ['menunggu_konfirmasi_ksm', 'diterima_ksm', 'ksm'],
                'ksm_reject' => ['menunggu_konfirmasi_ksm', 'ditolak_ksm', 'ksm'],
                'kordik_accept' => ['menunggu_persetujuan_kordik', 'menunggu_dokumen', 'kordik'],
                'kordik_reject' => ['menunggu_persetujuan_kordik', 'ditolak_kordik', 'kordik'],
                'verify' => ['menunggu_dokumen', 'terverifikasi', 'admin'],
            ];
            if (isset($map[$action])) {
                [$from, $to, $role] = $map[$action];
                if ($p->status !== $from) {
                    $this->fail('Transisi status tidak diizinkan.');
                }
            } elseif ($action === 'revise' && in_array($p->status, ['ditolak_ksm', 'ditolak_kordik', 'dibatalkan'])) {
                $to = 'draft';
                $role = 'admin';
            } elseif ($action === 'cancel' && in_array($p->status, array_diff(array_merge(['draft'], self::RESERVING), ['selesai']))) {
                $to = 'dibatalkan';
                $role = in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian']) ? 'kordik' : 'admin';
            } elseif ($action === 'reopen' && $p->status === 'selesai') {
                $to = 'menunggu_penyelesaian';
                $role = 'kordik';
            } else {
                $this->fail('Transisi status tidak diizinkan.');
            }
            $allowed = match ($role) {
                'admin' => $access->admin($actor),
                'kordik' => $access->role($actor, ['tim-kordik']),
                'ksm' => $access->role($actor, ['ketua-ksm']) && in_array((int) $p->department_id, $actor->departmentScopeIds(), true),
            };
            abort_unless($allowed, 403);
            if (in_array($action, ['ksm_reject', 'kordik_reject', 'revise', 'cancel', 'reopen']) && mb_strlen(trim((string) $reason)) < 10) {
                $this->fail('Alasan minimal 10 karakter wajib diisi.');
            }
            $before = $p->status;
            if ($action === 'verify') {
                $this->checkDocuments($p);
            }
            $p->status = $to;
            if ($action === 'reopen') {
                $p->actual_end_date = null;
                $p->revision++;
            }
            if (in_array($to, self::RESERVING)) {
                $this->checkOverlap($p);
            }
            $changes = ['status' => $to, 'updated_at' => now()];
            if (str_starts_with($action, 'ksm_')) {
                $changes['ksm_status'] = $action === 'ksm_accept' ? 'accepted' : 'rejected';
            }
            if (str_starts_with($action, 'kordik_')) {
                $changes['kordik_status'] = $action === 'kordik_accept' ? 'accepted' : 'rejected';
            }
            if ($action === 'verify') {
                $changes['document_status'] = 'verified';
            }
            if ($action === 'revise') {
                $changes += ['revision' => $p->revision + 1, 'actual_end_date' => null, 'ksm_status' => 'pending', 'kordik_status' => 'pending', 'document_status' => 'pending', 'completion_status' => 'pending'];
                DB::table('placement_documents')->where('placement_id', $p->id)->update(['status' => 'pending', 'reviewed_by' => null, 'updated_at' => now()]);
            }
            if ($action === 'reopen') {
                $changes += ['revision' => $p->revision, 'actual_end_date' => null, 'completion_status' => 'pending'];
            }
            DB::table('placements')->where('id', $p->id)->update($changes);
            $p = DB::table('placements')->find($p->id);
            $this->history($actor, $p, $before, $action, $reason);
            if ($to === 'diterima_ksm') {
                DB::table('placements')->where('id', $p->id)->update(['status' => 'menunggu_persetujuan_kordik']);
                $p->status = 'menunggu_persetujuan_kordik';
                $this->history($actor, $p, 'diterima_ksm', 'forward_to_kordik', null);
            }
        }, 5);
    }

    public function revisePeriod(User $actor, string $ulid, array $input): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $data = Validator::make($input, ['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'department_id' => 'required|exists:departments,id,is_active,1', 'revision' => 'required|integer', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $ulid, $data) {
            $p = $this->locked($ulid);
            if ((int) $p->revision !== (int) $data['revision'] || ! in_array($p->status, ['draft', 'menunggu_konfirmasi_ksm', 'menunggu_persetujuan_kordik', 'menunggu_dokumen', 'terverifikasi'])) {
                $this->fail('Revisi tidak tersedia pada status/versi ini.');
            }
            $old = clone $p;
            $p->start_date = $data['start_date'];
            $p->end_date = $data['end_date'];
            $p->department_id = (int) $data['department_id'];
            $p->revision++;
            if ($old->status !== 'draft') {
                $this->checkOverlap($p);
            }
            $snapshot = json_decode($p->snapshot, true);
            $snapshot['department'] = DB::table('departments')->where('id', $p->department_id)->value('name');
            DB::table('placements')->where('id', $p->id)->update(['start_date' => $p->start_date, 'end_date' => $p->end_date, 'department_id' => $p->department_id, 'revision' => $p->revision, 'snapshot' => json_encode($snapshot), 'status' => 'draft', 'ksm_status' => 'pending', 'kordik_status' => 'pending', 'document_status' => 'pending', 'updated_at' => now()]);
            DB::table('placement_documents')->where('placement_id', $p->id)->update(['status' => 'pending', 'reviewed_by' => null, 'updated_at' => now()]);
            // Keep prior obligations and add requirements for the revised KSM/context.
            $templates = DB::table('document_templates')->where('participant_type_id', $p->participant_type_id)->where('is_active', true);
            foreach (['institution_id', 'study_program_id', 'department_id'] as $key) {
                $templates->where(fn ($q) => $q->whereNull($key)->orWhere($key, $p->$key));
            }
            foreach ($templates->get() as $template) {
                DB::table('placement_documents')->insertOrIgnore(['placement_id' => $p->id, 'code' => $template->code, 'label' => $template->label, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->history($actor, DB::table('placements')->find($p->id), $old->status, 'period_revised', $data['reason']);
        }, 5);
    }

    public function requestException(User $actor, string $ulid, array $input): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $data = Validator::make($input, ['conflict_ulid' => 'required|string', 'file_ulid' => 'required|string', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $ulid, $data) {
            $p = $this->locked($ulid);
            if ($p->status !== 'draft') {
                $this->fail('Pengecualian diajukan dari draft.');
            }
            $other = $this->conflicts($p, true)->firstWhere('ulid', $data['conflict_ulid']);
            if (! $other || (int) $other->department_id === (int) $p->department_id) {
                $this->fail('Pengecualian hanya untuk benturan lintas KSM.');
            }
            $file = DB::table('private_files')->where('ulid', $data['file_ulid'])->where('resource_type', 'placement')->where('resource_id', $p->id)->where('category', 'pendukung')->where('scan_status', 'clean')->firstOrFail();
            DB::table('overlap_exceptions')->insert(['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'conflicting_placement_id' => $other->id, 'fingerprint' => $this->fingerprint($p, $other), 'reason' => $data['reason'], 'private_file_id' => $file->id, 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->history($actor, $p, $p->status, 'exception_requested', $data['reason']);
        }, 5);
    }

    public function decideException(User $actor, string $ulid, bool $approved, string $reason): void
    {
        abort_unless(app(AdmissionsAccess::class)->role($actor, ['tim-kordik']), 403);
        Validator::make(['reason' => $reason], ['reason' => 'required|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $ulid, $approved, $reason) {
            $e = DB::table('overlap_exceptions')->where('ulid', $ulid)->firstOrFail();
            $p = $this->locked(DB::table('placements')->where('id', $e->placement_id)->value('ulid'));
            $e = DB::table('overlap_exceptions')->where('id', $e->id)->lockForUpdate()->first();
            abort_if((int) $e->requested_by === (int) $actor->id, 403, 'Tidak boleh menyetujui permohonan sendiri.');
            $other = $this->conflicts($p, true)->firstWhere('id', $e->conflicting_placement_id);
            if ($e->status !== 'pending' || $p->status !== 'draft' || ! $other || $e->fingerprint !== $this->fingerprint($p, $other)) {
                $this->fail('Permohonan sudah diputus atau periode berubah. Ajukan ulang.');
            }
            abort_unless(DB::table('private_files')->where('id', $e->private_file_id)->where('scan_status', 'clean')->exists(), 422);
            DB::table('overlap_exceptions')->where('id', $e->id)->update(['status' => $approved ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decision_reason' => $reason, 'updated_at' => now()]);
            $this->history($actor, $p, $p->status, $approved ? 'exception_approved' : 'exception_rejected', $reason);
        }, 5);
    }

    public function reviewDocument(User $actor, string $ulid, array $input): void
    {
        $data = Validator::make($input, ['code' => 'required|string', 'file_ulid' => 'nullable|string', 'status' => 'required|in:valid,rejected,exception', 'valid_until' => 'nullable|date_format:Y-m-d', 'reason' => 'required|string|min:10|max:2000'])->validate();
        $access = app(AdmissionsAccess::class);
        abort_unless($data['status'] === 'exception' ? $access->role($actor, ['tim-kordik']) : $access->role($actor, ['admin-kordik']), 403);
        DB::transaction(function () use ($actor, $ulid, $data) {
            $p = $this->locked($ulid);
            if (! in_array($p->status, ['menunggu_dokumen', 'terverifikasi', 'dijadwalkan', 'sedang_stase'])) {
                $this->fail('Review dokumen tidak tersedia pada status ini.');
            }
            $doc = DB::table('placement_documents')->where('placement_id', $p->id)->where('code', $data['code'])->firstOrFail();
            $file = null;
            if ($data['status'] === 'valid') {
                $file = DB::table('private_files')->where('ulid', $data['file_ulid'] ?? '')->where('scan_status', 'clean')->firstOrFail();
                $resource = $doc->code === 'surat' ? 'letter' : 'placement';
                $resourceId = $resource === 'letter' ? $p->incoming_letter_id : $p->id;
                if ($file->resource_type !== $resource || (int) $file->resource_id !== (int) $resourceId || $file->category !== (in_array($doc->code, ['surat', 'ijazah', 'bhd', 'sip', 'str', 'kompetensi']) ? $doc->code : 'administrasi')) {
                    $this->fail('Berkas bukan dokumen yang sesuai untuk placement ini.');
                }
                if (($data['valid_until'] ?? null) && $data['valid_until'] < $p->end_date) {
                    $this->fail('Dokumen harus berlaku sampai akhir penempatan.');
                }
            }
            DB::table('placement_documents')->where('id', $doc->id)->update(['private_file_id' => $file?->id, 'status' => $data['status'], 'valid_until' => $data['valid_until'] ?? null, 'reason' => $data['reason'], 'reviewed_by' => $actor->id, 'updated_at' => now()]);
            $this->history($actor, $p, $p->status, 'document_'.$data['status'].':'.$doc->code, $data['reason']);
        }, 5);
    }

    public function checkDocuments(object $p): void
    {
        foreach (DB::table('placement_documents')->where('placement_id', $p->id)->lockForUpdate()->get() as $doc) {
            if ($doc->status === 'exception') {
                continue;
            }
            if ($doc->status !== 'valid' || ($doc->valid_until && $doc->valid_until < max($p->end_date, now()->toDateString())) || ! DB::table('private_files')->where('id', $doc->private_file_id)->where('scan_status', 'clean')->exists()) {
                $this->fail('Checklist belum lengkap/valid: '.$doc->label);
            }
        }
    }

    public function history(User $actor, object $p, ?string $from, string $action, ?string $reason): void
    {
        DB::table('placement_histories')->insert(['placement_id' => $p->id, 'from_status' => $from, 'to_status' => $p->status, 'action' => $action, 'reason' => $reason, 'actor_id' => $actor->id, 'snapshot' => json_encode((array) $p, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(AuditLogger::class)->log('placement.'.$action, 'placement', $p->ulid, reason: $reason, newValues: ['actor_id' => $actor->id, 'from' => $from, 'to' => $p->status, 'revision' => $p->revision]);
    }
}
