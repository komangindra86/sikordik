<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EducatorAssignmentService
{
    public function eligible(int $educator, string $role, string $start, string $end, int $department): object
    {
        $e = DB::table('educators')->where('id', $educator)->lockForUpdate()->firstOrFail();
        $flag = ['mentor' => 'can_mentor', 'examiner' => 'can_examine', 'supervisor' => 'can_supervise'][$role];
        $user = $e->user_id ? User::find($e->user_id) : null;
        if (! $e->is_active || ! $e->$flag || (int) $e->department_id !== $department || ! $user ||
            ! app(SchedulingAccess::class)->role($user, [$role === 'supervisor' ? 'supervisor' : 'pembimbing'])) {
            throw ValidationException::withMessages(['educator_id' => 'Pendidik harus aktif, memiliki akun dan peran yang sesuai, serta berasal dari KSM penempatan.']);
        }
        // Credential types are recorded by authorized staff; no profession-specific legal rule is inferred.
        $licenses = DB::table('educator_licenses')->where('educator_id', $e->id)->where('is_active', true)->lockForUpdate()->get();
        if ($licenses->isEmpty() || $licenses->contains(fn ($l) => ($l->issued_at && $l->issued_at > $start) || ($l->expires_at && $l->expires_at < max($end, now()->toDateString())))) {
            throw ValidationException::withMessages(['educator_id' => 'Catat lisensi/otorisasi pendidikan aktif; seluruh lisensi aktif harus berlaku sepanjang penugasan.']);
        }

        return $e;
    }

    public function license(User $actor, int $educator, array $input, ?int $id = null): void
    {
        abort_unless(app(SchedulingAccess::class)->admin($actor), 403);
        $data = Validator::make($input, ['license_type' => 'required|string|max:40',
            'license_number' => ['required', 'string', 'max:100', Rule::unique('educator_licenses')->where('license_type', $input['license_type'] ?? '')->ignore($id)],
            'issued_at' => 'required|date_format:Y-m-d', 'expires_at' => 'nullable|date_format:Y-m-d|after_or_equal:issued_at',
            'is_active' => 'required|boolean', 'revision' => 'required|integer|min:0', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $educator, $data, $id) {
            DB::table('educators')->where('id', $educator)->lockForUpdate()->firstOrFail();
            $before = $id ? DB::table('educator_licenses')->where('educator_id', $educator)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            abort_if($before && $before->revision != $data['revision'], 422, 'Lisensi berubah. Muat ulang.');
            $values = array_intersect_key($data, array_flip(['license_type', 'license_number', 'issued_at', 'expires_at', 'is_active']));
            $values += ['educator_id' => $educator, 'revision' => ($before->revision ?? 0) + 1, 'updated_at' => now()];
            if ($before) {
                DB::table('educator_licenses')->where('id', $id)->update($values);
            } else {
                $id = DB::table('educator_licenses')->insertGetId($values + ['created_at' => now()]);
            }
            app(SchedulingJournal::class)->record($actor, 'educator_licenses', $id, 'license_saved', $before, $data['reason']);
        });
    }

    public function request(User $actor, string $ulid, array $input): object
    {
        $data = Validator::make($input, ['educator_id' => 'required|integer|exists:educators,id', 'role' => 'required|in:mentor,examiner,supervisor',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'replaces_id' => 'nullable|integer', 'clinical_group_id' => 'nullable|integer', 'reason' => 'required|string|min:10|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $ulid, $data) {
            $p = app(PlacementService::class)->locked($ulid);
            abort_unless(app(SchedulingAccess::class)->manage($actor, $p->department_id), 403);
            $this->validate($p, (object) $data);
            $data['educator_user_id'] = DB::table('educators')->where('id', $data['educator_id'])->value('user_id');
            $id = DB::table('educator_assignments')->insertGetId($data + ['ulid' => (string) Str::ulid(), 'placement_id' => $p->id,
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $journal = app(SchedulingJournal::class);
            $journal->record($actor, 'educator_assignments', $id, 'assignment_requested', null, $data['reason']);
            $journal->notify($journal->roleUsers('ketua-ksm', $p->department_id), $p, 'Penugasan pendidik menunggu keputusan KSM.');

            return DB::table('educator_assignments')->find($id);
        }, 5);
    }

    private function validate(object $p, object $a): void
    {
        abort_unless(in_array($p->status, ['terverifikasi', 'dijadwalkan', 'sedang_stase']), 422, 'Penugasan hanya pada penempatan terverifikasi atau berjalan.');
        abort_if($a->start_date < $p->start_date || $a->end_date > $p->end_date, 422, 'Tanggal penugasan di luar penempatan.');
        $educator = $this->eligible($a->educator_id, $a->role, $a->start_date, $a->end_date, $p->department_id);
        abort_if(isset($a->educator_user_id) && $a->educator_user_id != $educator->user_id, 422, 'Akun pendidik berubah. Ajukan penugasan baru.');
        if ($a->clinical_group_id ?? null) {
            abort_unless(DB::table('clinical_groups')->where('id', $a->clinical_group_id)->where('department_id', $p->department_id)->exists(), 422, 'Kelompok tidak sesuai KSM.');
            abort_unless(DB::table('group_memberships')->where('placement_id', $p->id)->where('clinical_group_id', $a->clinical_group_id)
                ->where('start_date', '<=', $a->start_date)->where('end_date', '>=', $a->end_date)->exists(), 422, 'Keanggotaan kelompok tidak mencakup penugasan.');
        }
        if ($a->replaces_id ?? null) {
            $old = DB::table('educator_assignments')->where('id', $a->replaces_id)->where('placement_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($old->status === 'approved' && $old->role === $a->role, 422, 'Penugasan lama sudah berubah atau perannya berbeda.');
            abort_if(DB::table('schedules')->where('placement_id', $p->id)->whereIn('status', ['submitted', 'approved', 'published'])
                ->where(fn ($q) => $q->where('mentor_assignment_id', $old->id)->orWhere('examiner_assignment_id', $old->id))->exists(), 422,
                'Revisi/batalkan jadwal aktif pendidik lama melalui persetujuan sebelum mengganti penugasan.');
        }
        abort_if(DB::table('educator_assignments')->where('placement_id', $p->id)->where('educator_id', $a->educator_id)->where('role', $a->role)
            ->whereIn('status', ['pending', 'approved'])->when($a->id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->when($a->replaces_id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where('start_date', '<=', $a->end_date)->where('end_date', '>=', $a->start_date)->exists(), 422, 'Penugasan rangkap pada peran dan periode yang sama.');
    }

    public function decide(User $actor, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['revision' => 'required|integer|min:1', 'action' => 'required|in:approve,reject', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $ulid, $d) {
            $a = DB::table('educator_assignments')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $a->placement_id)->value('ulid'));
            $a = DB::table('educator_assignments')->where('id', $a->id)->lockForUpdate()->first();
            abort_unless(app(SchedulingAccess::class)->chief($actor, $p->department_id) && $actor->id != $a->requested_by, 403);
            abort_unless($a->status === 'pending' && $a->revision == $d['revision'], 422, 'Keputusan sudah berubah. Muat ulang.');
            $journal = app(SchedulingJournal::class);
            if ($d['action'] === 'approve') {
                $this->validate($p, $a);
                if ($a->replaces_id) {
                    $old = DB::table('educator_assignments')->find($a->replaces_id);
                    DB::table('educator_assignments')->where('id', $old->id)->update(['status' => 'replaced', 'revision' => $old->revision + 1, 'updated_at' => now()]);
                    $journal->record($actor, 'educator_assignments', $old->id, 'assignment_replaced', $old, $d['reason']);
                }
            }
            DB::table('educator_assignments')->where('id', $a->id)->update(['status' => $d['action'] === 'approve' ? 'approved' : 'rejected',
                'approved_by' => $actor->id, 'decision_reason' => $d['reason'], 'revision' => $a->revision + 1, 'updated_at' => now()]);
            $journal->record($actor, 'educator_assignments', $a->id, 'assignment_'.$d['action'], $a, $d['reason']);
            $journal->notify([$a->requested_by, DB::table('educators')->where('id', $a->educator_id)->value('user_id'), DB::table('participants')->where('id', $p->participant_id)->value('user_id')], $p, 'Keputusan penugasan pendidik tersedia.');
        }, 5);
    }
}
