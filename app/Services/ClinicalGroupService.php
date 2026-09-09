<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClinicalGroupService
{
    public function create(User $u, array $input): void
    {
        $d = Validator::make($input, ['department_id' => 'required|integer|exists:departments,id,is_active,1', 'name' => 'required|string|max:150'])->validate();
        abort_unless(app(SchedulingAccess::class)->manage($u, $d['department_id']), 403);
        DB::transaction(function () use ($u, $d) {
            DB::table('departments')->where('id', $d['department_id'])->lockForUpdate()->firstOrFail();
            abort_if(DB::table('clinical_groups')->where($d)->exists(), 422, 'Nama kelompok sudah dipakai dalam KSM.');
            $id = DB::table('clinical_groups')->insertGetId($d + ['ulid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'clinical_groups', $id, 'group_created', null);
        });
    }

    public function join(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['clinical_group_id' => 'required|integer|exists:clinical_groups,id', 'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $p = app(PlacementService::class)->locked($ulid);
            abort_unless(app(SchedulingAccess::class)->manage($u, $p->department_id), 403);
            abort_unless(in_array($p->status, ['terverifikasi', 'dijadwalkan', 'sedang_stase']), 422);
            $group = DB::table('clinical_groups')->find($d['clinical_group_id']);
            abort_if($group->department_id != $p->department_id || $d['start_date'] < $p->start_date || $d['end_date'] > $p->end_date, 422, 'Kelompok atau periode tidak sesuai penempatan.');
            abort_if(DB::table('group_memberships')->where('placement_id', $p->id)->where('start_date', '<=', $d['end_date'])->where('end_date', '>=', $d['start_date'])->exists(), 422, 'Keanggotaan bertumpang tindih. Akhiri keanggotaan lama sebelum pindah.');
            $id = DB::table('group_memberships')->insertGetId($d + ['placement_id' => $p->id, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'group_memberships', $id, 'group_joined', null, $d['reason']);
        }, 5);
    }

    public function end(User $u, int $id, array $input): void
    {
        $d = Validator::make($input, ['end_date' => 'required|date_format:Y-m-d', 'expected_end_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $id, $d) {
            $m = DB::table('group_memberships')->find($id);
            abort_unless($m, 404);
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $m->placement_id)->value('ulid'));
            $m = DB::table('group_memberships')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(app(SchedulingAccess::class)->manage($u, $p->department_id), 403);
            abort_unless(in_array($p->status, ['terverifikasi', 'dijadwalkan', 'sedang_stase']), 422);
            abort_if($m->end_date !== $d['expected_end_date'] || $d['end_date'] < max($m->start_date, now()->toDateString()) || $d['end_date'] >= $m->end_date, 422, 'Tanggal akhir tidak sah atau data berubah.');
            abort_if(DB::table('schedules')->where('placement_id', $p->id)->where('clinical_group_id', $m->clinical_group_id)->whereIn('status', ['draft', 'submitted', 'revision', 'approved', 'published'])->where('date', '>', $d['end_date'])->where('date', '<=', $m->end_date)->exists(), 422, 'Revisi jadwal kelompok setelah tanggal akhir terlebih dahulu.');
            abort_if(DB::table('educator_assignments')->where('placement_id', $p->id)->where('clinical_group_id', $m->clinical_group_id)->whereIn('status', ['pending', 'approved'])->where('end_date', '>', $d['end_date'])->where('start_date', '<=', $m->end_date)->exists(), 422, 'Ganti penugasan kelompok yang melampaui tanggal akhir terlebih dahulu.');
            DB::table('group_memberships')->where('id', $id)->update(['end_date' => $d['end_date'], 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'group_memberships', $id, 'group_ended', $m, $d['reason']);
        }, 5);
    }
}
