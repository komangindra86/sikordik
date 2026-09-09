<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttendanceService
{
    public const LABELS = ['draft' => 'Draft', 'waiting' => 'Menunggu verifikasi', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak', 'corrected' => 'Dikoreksi — menunggu verifikasi'];

    public const STATUSES = ['hadir', 'terlambat', 'izin', 'sakit', 'tidak_hadir'];

    public function days(object $p): array
    {
        return DB::table('schedules')->where('placement_id', $p->id)->whereIn('status', ['published', 'completed'])->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->distinct()->orderBy('date')->pluck('date')->all();
    }

    public function sealed(object $p): bool
    {
        return DB::table('attendance_summaries')->where('placement_id', $p->id)->where('status', 'sealed')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->exists();
    }

    public function assertCalendarMutable(object $p): void
    {
        abort_if($this->sealed($p), 422, 'Rekap presensi sudah disahkan; kalender penempatan terkunci.');
    }

    private function assignment(object $p, int $id, string $date): object
    {
        $a = DB::table('educator_assignments')->where('id', $id)->where('placement_id', $p->id)->where('role', 'mentor')->where('status', 'approved')
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
        abort_unless($a, 422, 'Verifikator harus memiliki penugasan pembimbing resmi pada tanggal presensi.');
        $u = User::find($a->educator_user_id);
        abort_unless($u && $u->is_active && app(SchedulingAccess::class)->mentor($u, (object) ['mentor_assignment_id' => $id, 'date' => $date]), 422, 'Akun pembimbing tidak lagi memenuhi penugasan.');

        return $a;
    }

    public function save(User $u, string $placement, array $input): void
    {
        $d = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'clinical_location_id' => 'required|integer', 'mentor_assignment_id' => 'required|integer',
            'attendance_status' => 'required|in:'.implode(',', self::STATUSES), 'activity' => 'required|string|max:2000', 'notes' => 'nullable|string|max:2000',
            'revision' => 'required|integer|min:0', 'action' => 'required|in:draft,submit,correct', 'reason' => 'nullable|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $placement, $d) {
            $p = app(PlacementService::class)->locked($placement);
            $access = app(SchedulingAccess::class);
            $correction = $d['action'] === 'correct';
            abort_unless($correction ? $access->admin($u) : $access->owner($u, $p), 403);
            abort_unless(in_array($p->status, $correction ? ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai'] : ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']), 422, 'Penempatan tidak terbuka untuk presensi.');
            abort_unless($d['date'] >= $p->start_date && $d['date'] <= $p->end_date && $d['date'] <= now()->toDateString() && in_array($d['date'], $this->days($p), true), 422, 'Tanggal harus merupakan hari pendidikan yang sudah berlangsung dalam periode stase.');
            $old = DB::table('attendances')->where('placement_id', $p->id)->where('date', $d['date'])->lockForUpdate()->first();
            abort_unless((int) ($old->revision ?? 0) === (int) $d['revision'], 422, 'Versi presensi berubah; muat ulang halaman.');
            if ($correction) {
                abort_unless($old && mb_strlen(trim($d['reason'] ?? '')) >= 10, 422, 'Koreksi admin memerlukan presensi lama dan alasan.');
            } else {
                abort_if($this->sealed($p) || ($old && ($old->admin_only || ! in_array($old->status, ['draft', 'rejected']))), 422, 'Presensi terkunci.');
            }
            abort_unless(DB::table('clinical_locations')->where('id', $d['clinical_location_id'])->where('department_id', $p->department_id)->where('is_active', true)->exists(), 422, 'Lokasi tidak sesuai KSM.');
            $a = $this->assignment($p, $d['mentor_assignment_id'], $d['date']);
            $status = $correction ? 'corrected' : ($d['action'] === 'submit' ? 'waiting' : 'draft');
            $values = array_intersect_key($d, array_flip(['date', 'clinical_location_id', 'mentor_assignment_id', 'attendance_status', 'activity', 'notes']));
            $values += ['notes' => null, 'status' => $status, 'revision' => ($old->revision ?? 0) + 1, 'submitted_at' => $status === 'draft' ? null : now(),
                'admin_only' => ($old->admin_only ?? false) || ($correction && $this->sealed($p)),
                'verified_by' => null, 'verified_at' => null, 'decision_note' => null, 'updated_at' => now()];
            if ($old) {
                DB::table('attendances')->where('id', $old->id)->update($values);
                $id = $old->id;
            } else {
                $id = DB::table('attendances')->insertGetId($values + ['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'original_assignment_id' => $a->id, 'created_at' => now()]);
            }
            $this->invalidate($u, $p, $d['reason'] ?? 'Presensi berubah');
            app(SchedulingJournal::class)->record($u, 'attendances', $id, 'attendance_'.$d['action'], $old, $d['reason'] ?? null);
            if ($status !== 'draft') {
                app(SchedulingJournal::class)->notify([$a->educator_user_id], $p, 'Presensi harian menunggu verifikasi. Buka menu Presensi.');
            }
        });
    }

    public function decide(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:verify,reject', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $a = DB::table('attendances')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $a->placement_id)->value('ulid'));
            $a = DB::table('attendances')->where('id', $a->id)->lockForUpdate()->first();
            abort_unless(app(SchedulingAccess::class)->mentor($u, $a) && ! app(SchedulingAccess::class)->owner($u, $p), 403);
            $this->assignment($p, $a->mentor_assignment_id, $a->date);
            abort_if($this->sealed($p) || ! in_array($a->status, ['waiting', 'corrected']) || $a->revision != $d['revision'], 422, 'Presensi terkunci atau versi berubah.');
            DB::table('attendances')->where('id', $a->id)->update(['status' => $d['action'] === 'verify' ? 'verified' : 'rejected', 'revision' => $a->revision + 1,
                'verified_by' => $u->id, 'verified_at' => now(), 'decision_note' => $d['reason'], 'updated_at' => now()]);
            $this->invalidate($u, $p, $d['reason']);
            app(SchedulingJournal::class)->record($u, 'attendances', $a->id, 'attendance_'.$d['action'], $a, $d['reason']);
            app(SchedulingJournal::class)->notify([DB::table('participants')->where('id', $p->participant_id)->value('user_id')], $p, 'Keputusan verifikasi presensi tersedia di menu Presensi.');
        });
    }

    public function replaceVerifier(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['mentor_assignment_id' => 'required|integer', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $old = DB::table('attendances')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $old->placement_id)->value('ulid'));
            abort_unless(app(SchedulingAccess::class)->admin($u), 403);
            $old = DB::table('attendances')->where('id', $old->id)->lockForUpdate()->first();
            abort_if($this->sealed($p) || ! in_array($old->status, ['waiting', 'corrected', 'rejected']) || $old->revision != $d['revision'], 422, 'Presensi terkunci atau versi berubah.');
            $a = $this->assignment($p, $d['mentor_assignment_id'], $old->date);
            abort_if($a->id === $old->mentor_assignment_id, 422, 'Pilih pembimbing pengganti.');
            DB::table('attendances')->where('id', $old->id)->update(['mentor_assignment_id' => $a->id, 'revision' => $old->revision + 1, 'status' => 'corrected', 'verified_by' => null, 'verified_at' => null, 'decision_note' => null, 'updated_at' => now()]);
            $this->invalidate($u, $p, $d['reason']);
            app(SchedulingJournal::class)->record($u, 'attendances', $old->id, 'attendance_verifier_replaced', $old, $d['reason']);
            app(SchedulingJournal::class)->notify([$a->educator_user_id], $p, 'Anda ditunjuk sebagai verifikator pengganti presensi.');
        });
    }

    private function invalidate(User $u, object $p, string $reason): void
    {
        foreach (DB::table('attendance_summaries')->where('placement_id', $p->id)->whereIn('status', ['draft', 'sealed'])->lockForUpdate()->get() as $s) {
            DB::table('attendance_summaries')->where('id', $s->id)->update(['status' => 'superseded', 'reason' => $reason, 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'attendance_summaries', $s->id, 'attendance_summary_superseded', $s, $reason);
        }
    }

    public function snapshot(object $p): array
    {
        $rows = DB::table('attendances as a')->join('clinical_locations as l', 'l.id', '=', 'a.clinical_location_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.verified_by')->where('a.placement_id', $p->id)->orderBy('a.date')
            ->select('a.*', 'l.name as location_name', 'u.name as verifier_name')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get()->map(fn ($r) => (array) $r)->all();
        $days = $this->days($p);
        $verified = array_filter($rows, fn ($r) => $r['status'] === 'verified');
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($verified as $r) {
            $counts[$r['attendance_status']]++;
        }

        return ['placement_id' => $p->id, 'participant_name' => DB::table('participants')->where('id', $p->participant_id)->value('name'), 'department_name' => json_decode($p->snapshot)->department, 'start_date' => $p->start_date, 'end_date' => $p->end_date, 'days' => $days,
            'rows' => $rows, 'counts' => $counts, 'missing' => array_values(array_diff($days, array_column($rows, 'date'))), 'pending' => count($rows) - count($verified)];
    }

    public function summary(User $u, string $placement, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:generate,approve', 'version' => 'required|integer|min:0'])->validate();
        DB::transaction(function () use ($u, $placement, $d) {
            $p = app(PlacementService::class)->locked($placement);
            $a = app(SchedulingAccess::class);
            abort_unless($d['action'] === 'approve' ? ($a->chief($u, $p->department_id) && ! $a->owner($u, $p)) : ($a->manage($u, $p->department_id) || $a->chief($u, $p->department_id)), 403);
            abort_unless($p->end_date < now()->toDateString(), 422, 'Rekap akhir dibuat setelah hari terakhir stase berakhir.');
            $s = DB::table('attendance_summaries')->where('placement_id', $p->id)->orderByDesc('version')->lockForUpdate()->first();
            abort_if($this->sealed($p) || ($s->version ?? 0) != $d['version'], 422, 'Rekap terkunci atau versi berubah.');
            $snapshot = $this->snapshot($p);
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($d['action'] === 'generate') {
                $this->invalidate($u, $p, 'Rekap dibuat ulang');
                $id = DB::table('attendance_summaries')->insertGetId(['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'version' => ($s->version ?? 0) + 1,
                    'status' => 'draft', 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'fingerprint' => $fingerprint, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                app(SchedulingJournal::class)->record($u, 'attendance_summaries', $id, 'attendance_summary_generated', null);
                app(SchedulingJournal::class)->notify(app(SchedulingJournal::class)->roleUsers('ketua-ksm', $p->department_id), $p, 'Rekap akhir presensi tersedia untuk pemeriksaan Ketua KSM.');
            } else {
                abort_unless($s && $s->status === 'draft' && hash_equals($s->fingerprint, $fingerprint), 422, 'Data berubah; buat ulang rekap.');
                abort_if(! count($snapshot['days']) || count($snapshot['missing']) || $snapshot['pending'] || array_diff(array_column($snapshot['rows'], 'date'), $snapshot['days']), 422, 'Lengkapi verifikasi semua hari pendidikan sebelum pengesahan.');
                DB::table('attendance_summaries')->where('id', $s->id)->update(['status' => 'sealed', 'approved_by' => $u->id, 'approved_at' => now(), 'updated_at' => now()]);
                app(SchedulingJournal::class)->record($u, 'attendance_summaries', $s->id, 'attendance_summary_sealed', $s);
                app(SchedulingJournal::class)->notify([DB::table('participants')->where('id', $p->participant_id)->value('user_id')], $p, 'Rekap presensi telah disahkan Ketua KSM dan dikunci.');
            }
        });
    }

    public function remind(): int
    {
        $count = 0;
        DB::table('attendances')->whereIn('status', ['waiting', 'corrected'])->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $count += DB::transaction(function () use ($row) {
                    $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $row->placement_id)->value('ulid'));
                    $r = DB::table('attendances')->where('id', $row->id)->lockForUpdate()->first();
                    if (! in_array($r->status, ['waiting', 'corrected'])) {
                        return 0;
                    }
                    $assignment = DB::table('educator_assignments')->find($r->mentor_assignment_id);
                    $u = User::find($assignment->educator_user_id);
                    $recipients = $u && $u->is_active && app(SchedulingAccess::class)->mentor($u, $r) ? [$u->id] : app(SchedulingJournal::class)->roleUsers('admin-kordik');
                    $sent = 0;
                    foreach ($recipients as $id) {
                        if (DB::table('attendance_reminders')->insertOrIgnore(['attendance_id' => $r->id, 'user_id' => $id, 'date' => now()->toDateString()])) {
                            app(SchedulingJournal::class)->notify([$id], $p, 'Pengingat: presensi belum diverifikasi. Periksa presensi atau penugasan verifikator.');
                            $sent++;
                        }
                    }

                    return $sent;
                });
            }
        });

        return $count;
    }
}
