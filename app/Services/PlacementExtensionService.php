<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlacementExtensionService
{
    public function request(User $u, string $ulid, array $input): void
    {
        abort_unless(app(SchedulingAccess::class)->admin($u), 403);
        $d = Validator::make($input, ['new_end_date' => 'required|date_format:Y-m-d', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000', 'supporting_file_id' => 'nullable|integer'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $p = app(PlacementService::class)->locked($ulid);
            app(AttendanceService::class)->assertCalendarMutable($p);
            abort_unless(in_array($p->status, ScheduleService::OPEN_PLACEMENTS) && $p->revision == $d['revision'] && $d['new_end_date'] > $p->end_date, 422, 'Perpanjangan tidak sah atau data berubah.');
            abort_if(DB::table('placement_extensions')->where('placement_id', $p->id)->whereIn('status', ['pending_ksm', 'pending_kordik'])->exists(), 422, 'Permohonan perpanjangan masih aktif.');
            $candidate = clone $p;
            $candidate->end_date = $d['new_end_date'];
            $candidate->revision++;
            $conflicts = $this->conflictSnapshot($candidate);
            if ($d['supporting_file_id'] ?? null) {
                $this->supportingFile($p, $d['supporting_file_id']);
            }
            $id = DB::table('placement_extensions')->insertGetId(['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'old_end_date' => $p->end_date,
                'new_end_date' => $d['new_end_date'], 'supporting_file_id' => $d['supporting_file_id'] ?? null, 'conflict_snapshot' => json_encode($conflicts, JSON_THROW_ON_ERROR),
                'placement_revision' => $p->revision, 'requested_by' => $u->id, 'reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
            $j = app(SchedulingJournal::class);
            $j->record($u, 'placement_extensions', $id, 'extension_requested', null, $d['reason']);
            $j->notify($j->roleUsers('ketua-ksm', $p->department_id), $p, 'Perpanjangan stase menunggu keputusan KSM.');
        }, 5);
    }

    public function decide(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:approve,reject,withdraw', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000', 'approve_overlap' => 'sometimes|accepted'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $e = DB::table('placement_extensions')->where('ulid', $ulid)->firstOrFail();
            $service = app(PlacementService::class);
            $p = $service->locked(DB::table('placements')->where('id', $e->placement_id)->value('ulid'));
            app(AttendanceService::class)->assertCalendarMutable($p);
            $e = DB::table('placement_extensions')->where('id', $e->id)->lockForUpdate()->first();
            abort_unless(in_array($e->status, ['pending_ksm', 'pending_kordik']) && $e->revision == $d['revision'], 422, 'Permohonan sudah berubah.');
            $a = app(SchedulingAccess::class);
            if ($d['action'] === 'withdraw') {
                abort_unless($a->admin($u), 403);
                $status = 'withdrawn';
            } else {
                abort_unless($u->id != $e->requested_by && ($e->status === 'pending_ksm' ? $a->chief($u, $p->department_id) : $a->role($u, ['tim-kordik'])), 403);
                $status = $d['action'] === 'reject' ? 'rejected' : ($e->status === 'pending_ksm' ? 'pending_kordik' : 'approved');
            }
            $values = ['status' => $status, 'revision' => $e->revision + 1, 'decision_reason' => $d['reason'], 'updated_at' => now()];
            if ($d['action'] === 'approve') {
                abort_unless(in_array($p->status, ScheduleService::OPEN_PLACEMENTS) && $p->revision == $e->placement_revision && $p->end_date === $e->old_end_date, 422, 'Periode berubah. Tarik permohonan dan ajukan ulang.');
                $candidate = clone $p;
                $candidate->end_date = $e->new_end_date;
                $candidate->revision++;
                $conflicts = $this->conflictSnapshot($candidate);
                // MySQL normalizes JSON object key order; compare the values, not PHP insertion order.
                abort_unless($conflicts == json_decode($e->conflict_snapshot, true), 422, 'Daftar benturan berubah. Tarik permohonan dan ajukan ulang.');
                if ($conflicts) {
                    $this->supportingFile($p, (int) $e->supporting_file_id);
                    abort_unless($d['approve_overlap'] ?? false, 422, 'Persetujuan eksplisit periode paralel wajib dicentang.');
                    foreach ($service->conflicts($candidate, true) as $other) {
                        abort_if($other->department_id == $candidate->department_id, 422, 'Perpanjangan bertabrakan dalam KSM yang sama.');
                        if ($status === 'approved') {
                            DB::table('overlap_exceptions')->insert(['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'conflicting_placement_id' => $other->id,
                                'fingerprint' => $service->fingerprint($candidate, $other), 'reason' => $e->reason, 'private_file_id' => $e->supporting_file_id,
                                'requested_by' => $e->requested_by, 'decided_by' => $u->id, 'status' => 'approved', 'decision_reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
                if ($status === 'approved' || ! $conflicts) {
                    $service->checkOverlap($candidate);
                }
                $service->checkDocuments($candidate);
                if ($status === 'pending_kordik') {
                    $values['ksm_approved_by'] = $u->id;
                } else {
                    $values['approved_by'] = $u->id;
                    DB::table('placements')->where('id', $p->id)->update(['end_date' => $candidate->end_date, 'revision' => $candidate->revision, 'updated_at' => now()]);
                    $service->history($u, DB::table('placements')->find($p->id), $p->status, 'period_extended', $e->reason);
                }
            }
            DB::table('placement_extensions')->where('id', $e->id)->update($values);
            $j = app(SchedulingJournal::class);
            $j->record($u, 'placement_extensions', $e->id, 'extension_'.$d['action'], $e, $d['reason']);
            $j->notify(array_merge([$e->requested_by, DB::table('participants')->where('id', $p->participant_id)->value('user_id')], $status === 'pending_kordik' ? $j->roleUsers('tim-kordik') : []), $p, 'Status permohonan perpanjangan stase berubah.');
        }, 5);
    }

    public function start(User $u, string $ulid, int $revision): void
    {
        abort_unless(app(SchedulingAccess::class)->admin($u), 403);
        DB::transaction(function () use ($u, $ulid, $revision) {
            $service = app(PlacementService::class);
            $p = $service->locked($ulid);
            abort_unless($p->status === 'dijadwalkan' && $p->revision == $revision && $p->start_date <= now()->toDateString() && $p->end_date >= now()->toDateString(), 422, 'Stase belum dapat dimulai.');
            $schedules = DB::table('schedules')->where('placement_id', $p->id)->where('status', 'published')->get();
            abort_if($schedules->isEmpty(), 422, 'Jadwal terbit belum tersedia.');
            $service->checkOverlap($p);
            $service->checkDocuments($p);
            foreach ($schedules as $s) {
                app(ScheduleService::class)->validate($p, $s);
            }
            DB::table('placements')->where('id', $p->id)->update(['status' => 'sedang_stase', 'activity_status' => 'active', 'updated_at' => now()]);
            $service->history($u, DB::table('placements')->find($p->id), $p->status, 'activity_started', null);
        }, 5);
    }

    private function conflictSnapshot(object $p): array
    {
        $service = app(PlacementService::class);

        return $service->conflicts($p, true)->sortBy('id')->map(fn ($other) => ['placement' => $other->ulid, 'fingerprint' => $service->fingerprint($p, $other),
            'department' => json_decode($other->snapshot)->department, 'start_date' => $other->start_date, 'end_date' => $other->end_date])->values()->all();
    }

    private function supportingFile(object $p, int $id): void
    {
        abort_unless(DB::table('private_files')->where('id', $id)->where('resource_type', 'placement')->where('resource_id', $p->id)
            ->where('category', 'pendukung')->where('scan_status', 'clean')->exists(), 422, 'Periode paralel memerlukan berkas pendukung bersih milik penempatan ini.');
    }
}
