<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ScheduleService
{
    public const OPEN_PLACEMENTS = ['terverifikasi', 'dijadwalkan', 'sedang_stase'];

    private function editor(User $u, object $p): void
    {
        $a = app(SchedulingAccess::class);
        abort_unless($a->owner($u, $p) || $a->manage($u, $p->department_id), 403);
    }

    public function save(User $u, string $placement, array $input, ?string $ulid = null): object
    {
        $d = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'start_time' => 'nullable|required_with:end_time|date_format:H:i',
            'end_time' => 'nullable|required_with:start_time|date_format:H:i|after:start_time', 'activity' => 'required|string|max:150',
            'clinical_location_id' => 'required|integer|exists:clinical_locations,id,is_active,1', 'clinical_group_id' => 'nullable|integer',
            'mentor_assignment_id' => 'required|integer', 'examiner_assignment_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000',
            'revision' => 'required|integer|min:0', 'reason' => 'nullable|string|min:10|max:2000', 'replaces_id' => 'nullable|integer',
            'change_kind' => 'required|in:schedule,cancel'])->validate();

        return DB::transaction(function () use ($u, $placement, $d, $ulid) {
            $p = app(PlacementService::class)->locked($placement);
            $this->editor($u, $p);
            abort_unless(in_array($p->status, self::OPEN_PLACEMENTS), 422, 'Penempatan belum terverifikasi atau sudah ditutup.');
            $old = $ulid ? DB::table('schedules')->where('ulid', $ulid)->where('placement_id', $p->id)->lockForUpdate()->firstOrFail() : null;
            abort_if($old && (! in_array($old->status, ['draft', 'revision']) || $old->revision != $d['revision']), 422, 'Jadwal terkunci atau versi berubah.');
            if (! app(SchedulingAccess::class)->owner($u, $p) || ($d['replaces_id'] ?? null)) {
                abort_if(mb_strlen(trim($d['reason'] ?? '')) < 10, 422, 'Perubahan administratif wajib beralasan.');
            }
            $values = $d + array_fill_keys(['start_time', 'end_time', 'clinical_group_id', 'examiner_assignment_id', 'notes', 'reason', 'replaces_id'], null);
            unset($values['revision']);
            $values += ['placement_id' => $p->id, 'status' => 'draft', 'approved_by' => null, 'submitted_by' => null];
            $values['revision'] = ($old->revision ?? 0) + 1;
            if ($old) {
                abort_if(($old->replaces_id ?? null) != ($d['replaces_id'] ?? null), 422, 'Target perubahan tidak boleh diganti.');
            }
            if ($d['replaces_id'] ?? null) {
                $target = DB::table('schedules')->where('id', $d['replaces_id'])->where('placement_id', $p->id)->lockForUpdate()->firstOrFail();
                abort_unless($target->status === 'published', 422, 'Hanya jadwal terbit yang dapat diajukan perubahan.');
                abort_if(DB::table('schedules')->where('replaces_id', $target->id)->whereIn('status', ['draft', 'revision', 'submitted', 'approved'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists(), 422, 'Sudah ada permohonan perubahan aktif.');
                $values['replaces_revision'] = $target->revision;
                if ($d['change_kind'] === 'cancel') {
                    foreach (['date', 'start_time', 'end_time', 'activity', 'clinical_location_id', 'clinical_group_id', 'notes'] as $field) {
                        $values[$field] = $target->$field;
                    }
                    $values['examiner_assignment_id'] = null;
                }
            } else {
                abort_unless($d['change_kind'] === 'schedule', 422);
            }
            $this->validate($p, (object) $values, false);
            $values['updated_at'] = now();
            if ($old) {
                DB::table('schedules')->where('id', $old->id)->update($values);
                $id = $old->id;
            } else {
                $id = DB::table('schedules')->insertGetId($values + ['ulid' => (string) Str::ulid(), 'created_by' => $u->id, 'created_at' => now()]);
            }
            app(SchedulingJournal::class)->record($u, 'schedules', $id, 'schedule_saved', $old, $d['reason'] ?? null);

            return DB::table('schedules')->find($id);
        }, 5);
    }

    public function validate(object $p, object $s, bool $conflicts = true): void
    {
        abort_if($s->date < $p->start_date || $s->date > $p->end_date, 422, 'Jadwal berada di luar periode penempatan.');
        $location = DB::table('clinical_locations')->where('id', $s->clinical_location_id)->where('is_active', true)->first();
        abort_unless($location && (! $location->department_id || $location->department_id == $p->department_id), 422, 'Lokasi tidak sesuai KSM.');
        foreach (['mentor_assignment_id' => 'mentor', 'examiner_assignment_id' => 'examiner'] as $field => $role) {
            if (! ($s->$field ?? null)) {
                continue;
            }
            $a = DB::table('educator_assignments')->where('id', $s->$field)->where('placement_id', $p->id)->where('role', $role)->where('status', 'approved')->lockForUpdate()->first();
            abort_unless($a && $a->start_date <= $s->date && $a->end_date >= $s->date, 422, 'Penugasan pendidik tidak sesuai tanggal/peran.');
            $educator = app(EducatorAssignmentService::class)->eligible($a->educator_id, $role, $s->date, $s->date, $p->department_id);
            abort_unless($a->educator_user_id == $educator->user_id, 422, 'Akun pendidik berubah. Penugasan harus disahkan ulang.');
        }
        if ($s->clinical_group_id ?? null) {
            abort_unless(DB::table('clinical_groups')->where('id', $s->clinical_group_id)->where('department_id', $p->department_id)->exists(), 422, 'Kelompok tidak sesuai KSM.');
            abort_unless(DB::table('group_memberships')->where('placement_id', $p->id)->where('clinical_group_id', $s->clinical_group_id)->where('start_date', '<=', $s->date)->where('end_date', '>=', $s->date)->exists(), 422, 'Peserta bukan anggota kelompok pada tanggal jadwal.');
        }
        if ($conflicts && ($s->change_kind ?? 'schedule') !== 'cancel') {
            $q = DB::table('schedules')->whereIn('placement_id', DB::table('placements')->where('participant_id', $p->participant_id)->whereNotIn('status', ['dibatalkan', 'ditolak_ksm', 'ditolak_kordik'])->select('id'))
                ->where('date', $s->date)->whereIn('status', ['submitted', 'approved', 'published', 'completed'])
                ->when($s->id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->when($s->replaces_id ?? null, fn ($q, $id) => $q->where('id', '!=', $id));
            if ($s->start_time ?? null) {
                $q->where(fn ($q) => $q->whereNull('start_time')->orWhere(fn ($q) => $q->where('start_time', '<', $s->end_time)->where('end_time', '>', $s->start_time)));
            }
            abort_if($q->lockForUpdate()->exists(), 422, 'Jam peserta bentrok, termasuk jadwal di KSM lain. Jadwal tanpa jam memesan satu hari penuh.');
        }
    }

    public function transition(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:submit,approve,revise,publish,withdraw,complete', 'revision' => 'required|integer|min:1', 'reason' => 'nullable|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $s = DB::table('schedules')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $s->placement_id)->value('ulid'));
            $s = DB::table('schedules')->where('id', $s->id)->lockForUpdate()->first();
            app(SchedulingAccess::class)->placement($u, $p->ulid);
            abort_unless(in_array($p->status, self::OPEN_PLACEMENTS) && $s->revision == $d['revision'], 422, 'Penempatan terkunci atau jadwal berubah.');
            $a = app(SchedulingAccess::class);
            $action = $d['action'];
            $to = match ($action) {
                'submit' => 'submitted', 'approve' => 'approved', 'revise' => 'revision', 'publish' => 'published', 'withdraw' => 'cancelled', 'complete' => 'completed'
            };
            $from = match ($action) {
                'submit' => ['draft'], 'approve', 'revise' => ['submitted'], 'publish' => ['approved'], 'withdraw' => ['draft', 'revision', 'submitted', 'approved'], 'complete' => ['published']
            };
            abort_unless(in_array($s->status, $from), 422, 'Transisi jadwal tidak sah.');
            if (in_array($action, ['approve', 'revise', 'complete'])) {
                abort_unless($a->mentor($u, $s), 403);
                abort_if(($u->id == $s->created_by || $u->id == $s->submitted_by || $a->owner($u, $p)) && $action === 'approve', 403, 'Tidak boleh menyetujui pengajuan sendiri.');
            } else {
                $this->editor($u, $p);
            }
            if (in_array($action, ['revise', 'withdraw'])) {
                abort_if(mb_strlen(trim($d['reason'] ?? '')) < 10, 422, 'Alasan minimal 10 karakter wajib diisi.');
            }
            if ($action === 'complete') {
                abort_if($s->date >= now()->toDateString() || $s->change_kind === 'cancel', 422, 'Jadwal baru dapat diselesaikan setelah hari kegiatan berakhir.');
            }
            $target = null;
            if ($s->replaces_id && in_array($action, ['submit', 'approve', 'publish'])) {
                $target = DB::table('schedules')->where('id', $s->replaces_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->status === 'published' && $target->revision == $s->replaces_revision, 422, 'Jadwal asal berubah. Ajukan ulang.');
            }
            if (in_array($action, ['submit', 'approve', 'publish'])) {
                if ($action === 'publish') {
                    $approver = $s->approved_by ? User::find($s->approved_by) : null;
                    abort_unless($approver && $a->mentor($approver, $s), 422, 'Persetujuan pembimbing tidak lagi berlaku. Tarik dan ajukan jadwal baru.');
                }
                $this->validate($p, $s);
                if ($s->change_kind !== 'cancel') {
                    app(PlacementService::class)->checkOverlap($p);
                    app(PlacementService::class)->checkDocuments($p);
                }
            }
            $journal = app(SchedulingJournal::class);
            if ($action === 'publish' && $target) {
                DB::table('schedules')->where('id', $target->id)->update(['status' => $s->change_kind === 'cancel' ? 'cancelled' : 'superseded', 'revision' => $target->revision + 1, 'updated_at' => now()]);
                $journal->record($u, 'schedules', $target->id, 'schedule_replaced', $target, $s->reason);
                if ($s->change_kind === 'cancel') {
                    $to = 'cancelled';
                }
            }
            $changes = ['status' => $to, 'revision' => $s->revision + 1, 'updated_at' => now()];
            if ($action === 'submit') {
                $changes['submitted_by'] = $u->id;
            }
            if ($action === 'approve') {
                $changes['approved_by'] = $u->id;
            }
            DB::table('schedules')->where('id', $s->id)->update($changes);
            $journal->record($u, 'schedules', $s->id, 'schedule_'.$action, $s, $d['reason'] ?? $s->reason);
            if ($action === 'publish' && $p->status === 'terverifikasi' && $s->change_kind !== 'cancel') {
                DB::table('placements')->where('id', $p->id)->update(['status' => 'dijadwalkan', 'activity_status' => 'scheduled', 'updated_at' => now()]);
                app(PlacementService::class)->history($u, DB::table('placements')->find($p->id), $p->status, 'schedule_published', $s->reason);
            }
            $mentor = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.id', $s->mentor_assignment_id)->value('e.user_id');
            $journal->notify([$mentor, $s->created_by, DB::table('participants')->where('id', $p->participant_id)->value('user_id')], $p, 'Status jadwal berubah: '.self::label($to).'.');
        }, 5);
    }

    public static function label(string $status): string
    {
        return ['draft' => 'Draft', 'submitted' => 'Diajukan', 'revision' => 'Perlu revisi', 'approved' => 'Disetujui', 'published' => 'Diterbitkan', 'cancelled' => 'Dibatalkan', 'completed' => 'Selesai', 'superseded' => 'Digantikan'][$status] ?? $status;
    }
}
