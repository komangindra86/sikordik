<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const TYPES = ['placements' => 'Peserta, institusi, KSM & riwayat penempatan', 'letters' => 'Surat masuk', 'documents' => 'Kelengkapan dokumen', 'attendance' => 'Presensi harian', 'summaries' => 'Rekap presensi disahkan', 'schedules' => 'Jadwal & kegiatan pembimbing', 'logbooks' => 'Logbook peserta & pembimbing', 'grades' => 'Nilai', 'surveys' => 'Survei', 'completion' => 'Status penyelesaian', 'audit' => 'Riwayat audit'];

    public function placements(User $u, array $filters = [], string $access = SchedulingAccess::class): Builder
    {
        return app($access)->placements($u)
            ->when($filters['institution'] ?? null, fn ($q, $v) => $q->where('placements.institution_id', $v))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('placements.department_id', $v))
            ->when($filters['participant'] ?? null, fn ($q, $v) => $q->where('placements.participant_id', $v))
            ->when($filters['placement'] ?? null, fn ($q, $v) => $q->where('placements.ulid', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('placements.status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('placements.end_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('placements.start_date', '<=', $v));
    }

    // Reuse each module's row authorization for sensitive reports. A placement is
    // required so monitor access never accidentally becomes access to all rows.
    public function query(User $u, string $type, array $f): Builder
    {
        abort_unless($u->is_active && isset(self::TYPES[$type]), 403);
        if ($type === 'audit') {
            abort_unless($u->hasPermission('audit-logs.view'), 403);

            return DB::table('audit_logs')->select('id', 'created_at as Waktu', 'event as Peristiwa', 'user_id as Pengguna', 'auditable_type as Sumber', 'auditable_id as Referensi')
                ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
                ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))->orderByDesc('id');
        }
        if ($type === 'letters') {
            abort_unless(app(AdmissionsAccess::class)->role($u, ['admin-kordik', 'tim-kordik', 'super-admin']), 403);

            return DB::table('incoming_letters')->select('id', 'number as Nomor', 'letter_date as Tanggal', 'institution_id as Institusi', 'subject as Perihal')
                ->when($f['institution'] ?? null, fn ($q, $v) => $q->where('institution_id', $v))
                ->when($f['from'] ?? null, fn ($q, $v) => $q->where('letter_date', '>=', $v))
                ->when($f['to'] ?? null, fn ($q, $v) => $q->where('letter_date', '<=', $v))->orderByDesc('id');
        }
        if (in_array($type, ['logbooks', 'grades'])) {
            abort_unless(! empty($f['placement']), 422, 'Pilih satu penempatan untuk laporan logbook/nilai.');
            $a = app($type === 'grades' ? AssessmentAccess::class : LogbookAccess::class);
            $p = $a->placement($u, $f['placement']);
            $q = $a->rows($u, $p)->whereIn('placement_id', $this->placements($u, $f, $type === 'grades' ? AssessmentAccess::class : LogbookAccess::class)->select('placements.id'));
            if ($type === 'logbooks') {
                return $q->select('id', 'ulid as Referensi', 'kind as Jenis', 'type as Logbook', 'status as Status', 'current_version as Versi')->orderByDesc('id');
            }
            $version = $a->owner($u, $p) ? 'published_version' : 'current_version';

            return $q->join('assessment_versions as v', fn ($j) => $j->on('v.assessment_id', '=', 'assessments.id')->on('v.version', '=', 'assessments.'.$version))
                ->select('assessments.id', 'assessments.ulid as Referensi', 'assessments.title as Penilaian', 'assessments.date as Tanggal', 'v.version as Versi', 'v.snapshot', 'v.sha256')->orderByDesc('assessments.id');
        }
        $access = match ($type) {
            'documents', 'surveys', 'completion' => AdmissionsAccess::class,
            'attendance', 'summaries' => AttendanceAccess::class,
            default => SchedulingAccess::class,
        };
        $placements = $this->placements($u, $f, $access);
        if ($type === 'surveys') {
            $kinds = DB::query()->selectRaw("'participant' as kind")->unionAll(DB::query()->selectRaw("'patient' as kind"));

            return DB::table('placements')->whereIn('placements.id', $placements->select('placements.id'))
                ->join('participants as p', 'p.id', '=', 'placements.participant_id')->crossJoinSub($kinds, 'k')
                ->leftJoin('survey_responses as r', fn ($j) => $j->on('r.placement_id', '=', 'placements.id')->on('r.kind', '=', 'k.kind'))
                ->select('placements.id', 'placements.ulid as Penempatan', 'p.name as Peserta', 'k.kind as Jenis', 'r.verified_at as Diverifikasi')
                ->selectRaw("COALESCE(r.status, 'belum_dimulai') as Status")->orderByDesc('placements.id')->orderBy('k.kind');
        }
        if (in_array($type, ['placements', 'completion'])) {
            return DB::table('placements')->whereIn('placements.id', $placements->select('placements.id'))->join('participants as p', 'p.id', '=', 'placements.participant_id')
                ->join('institutions as i', 'i.id', '=', 'placements.institution_id')->join('departments as d', 'd.id', '=', 'placements.department_id')
                ->select('placements.id', 'placements.ulid as Referensi', 'p.number as Nomor', 'p.name as Peserta', 'i.name as Institusi', 'd.name as KSM', 'placements.start_date as Mulai', 'placements.end_date as Akhir', 'placements.status as Status', 'placements.document_status as Dokumen', 'placements.archived_at as Diarsipkan')->orderByDesc('placements.id');
        }
        [$table, $fields] = match ($type) {
            'documents' => ['placement_documents', ['label as Dokumen', 'status as Status', 'valid_until as Berlaku']],
            'attendance' => ['attendances', ['date as Tanggal', 'attendance_status as Kehadiran', 'status as Status', 'verified_at as Diverifikasi']],
            'summaries' => ['attendance_summaries', ['ulid as Referensi', 'version as Versi', 'status as Status', 'approved_at as Disahkan', 'fingerprint as Hash']],
            'schedules' => ['schedules', ['date as Tanggal', 'activity as Kegiatan', 'mentor_assignment_id as Penugasan', 'status as Status']],
            'surveys' => ['survey_responses', ['kind as Jenis', 'status as Status', 'verified_at as Diverifikasi']],
        };
        $q = DB::table($table)->whereIn($table.'.placement_id', $placements->select('placements.id'))
            ->join('placements as p', 'p.id', '=', $table.'.placement_id')->join('participants as person', 'person.id', '=', 'p.participant_id')
            ->select($table.'.id', 'p.ulid as Penempatan', 'person.name as Peserta', ...array_map(fn ($field) => $table.'.'.$field, $fields));
        if ($type === 'summaries') {
            $q->where($table.'.status', 'sealed');
        }
        if (in_array($type, ['attendance', 'schedules'])) {
            $q->when($f['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v));
        }

        return $q->orderByDesc($table.'.id');
    }

    public function present(object $row): array
    {
        $data = (array) $row;
        unset($data['id']);
        if (isset($data['snapshot'])) {
            $s = json_decode($data['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($data['sha256'], app(AssessmentService::class)->fingerprint($s)), 423, 'Integritas nilai berubah.');
            unset($data['snapshot'], $data['sha256']);
            $data['Komponen nilai'] = collect($s['scores'])->map(fn ($score) => $score['component']['name'].': '.($score['value'] ?? '—'))->implode('; ');
            $data['Total'] = $s['total'] ?? '—';
        }

        return $data;
    }
}
