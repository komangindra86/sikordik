<?php

namespace App\Services;

use App\Models\User;
use App\Support\Ui;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Tugas saya": everything that is waiting for this user, derived from current data.
 * It only lists work; every action is still authorized by the module that performs it.
 */
class TaskService
{
    private const LIMIT = 30;

    private array $groups = [];

    public function for(User $u): array
    {
        $this->groups = [];
        $roles = $u->roleCodes();
        $today = now()->toDateString();
        $scope = $u->departmentScopeIds();
        $has = fn (string ...$codes) => $u->is_active && (bool) array_intersect($codes, $roles);

        if ($has('peserta')) {
            $this->participant($u, $today);
        }
        if ($has('pembimbing')) {
            $this->mentor($u);
        }
        if ($has('supervisor')) {
            $mine = app(LogbookAccess::class)->assignments($u, 'supervisor')->select('a.id');
            $this->add('Periksa logbook pembimbing', 'Periksa', 'logbooks.show', DB::table('logbooks')->where('kind', 'educator')->where('status', 'submitted')
                ->where('author_id', '!=', $u->id)->whereIn('reviewer_assignment_id', $mine)->get(['placement_id', 'ulid as param', 'type as detail']));
        }
        if ($has('ketua-ksm')) {
            $this->add('Konfirmasi kesediaan KSM', 'Putuskan', 'admissions.show', $this->placements(['menunggu_konfirmasi_ksm'], $scope)->get(['id as placement_id']), 'admissions.decisions');
            $this->add('Setujui penugasan pendidik', 'Putuskan', 'scheduling.show', DB::table('educator_assignments as a')->join('placements as p', 'p.id', '=', 'a.placement_id')
                ->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.status', 'pending')->where('a.requested_by', '!=', $u->id)->whereIn('p.department_id', $scope)
                ->limit(self::LIMIT)->get(['a.placement_id', 'e.name as detail']));
            $this->add('Putuskan perpanjangan stase', 'Putuskan', 'scheduling.show', DB::table('placement_extensions as x')->join('placements as p', 'p.id', '=', 'x.placement_id')
                ->where('x.status', 'pending_ksm')->whereIn('p.department_id', $scope)->limit(self::LIMIT)->get(['x.placement_id']));
            $this->add('Sahkan rekap presensi', 'Periksa & sahkan', 'attendance.show', DB::table('attendance_summaries as s')->join('placements as p', 'p.id', '=', 's.placement_id')
                ->where('s.status', 'draft')->whereIn('p.department_id', $scope)->whereIn('p.status', ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian'])->limit(self::LIMIT)->get(['s.placement_id']));
        }
        if ($has('ketua-ksm', 'sekretariat-ksm', 'admin-kordik')) {
            $all = $has('admin-kordik');
            if ($has('sekretariat-ksm', 'admin-kordik')) {
                $this->add('Ajukan pembimbing', 'Atur penugasan', 'scheduling.show', $this->placements(['terverifikasi', 'dijadwalkan', 'sedang_stase'], $all ? null : $scope)
                    ->whereNotExists(fn ($q) => $q->from('educator_assignments')->whereColumn('placement_id', 'placements.id')->where('role', 'mentor')->whereIn('status', ['pending', 'approved']))->get(['id as placement_id']));
            }
            $this->add('Buat rekap presensi akhir', 'Buat rekap', 'attendance.show', $this->placements(['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian'], $all ? null : $scope)->where('end_date', '<', $today)
                ->whereNotExists(fn ($q) => $q->from('attendance_summaries')->whereColumn('placement_id', 'placements.id')->whereIn('status', ['draft', 'sealed']))->get(['id as placement_id']));
        }
        if ($has('tim-kordik')) {
            $this->add('Putuskan penerimaan peserta', 'Putuskan', 'admissions.show', $this->placements(['menunggu_persetujuan_kordik'])->get(['id as placement_id']), 'admissions.decisions');
            $this->add('Putuskan penyelesaian / pembukaan kembali', 'Putuskan', 'completion.show', DB::table('completion_requests')->where('status', 'pending')
                ->where('requested_by', '!=', $u->id)->limit(self::LIMIT)->get(['placement_id']));
            $this->add('Putuskan perpanjangan stase', 'Putuskan', 'scheduling.show', DB::table('placement_extensions')->where('status', 'pending_kordik')->limit(self::LIMIT)->get(['placement_id']));
            $this->add('Putuskan pengecualian periode paralel', 'Putuskan', 'admissions.show', DB::table('overlap_exceptions')->where('status', 'pending')
                ->where('requested_by', '!=', $u->id)->limit(self::LIMIT)->get(['placement_id']));
        }
        if ($has('admin-kordik')) {
            $this->admin($u, $today);
        }

        return array_values(array_filter($this->hydrate(), fn ($g) => $g['items']));
    }

    public function count(User $u): int
    {
        return array_sum(array_map(fn ($g) => count($g['items']), $this->for($u)));
    }

    private function placements(array $statuses, ?array $departments = null): Builder
    {
        return DB::table('placements')->whereNull('archived_at')->whereIn('status', $statuses)
            ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))->orderBy('start_date')->limit(self::LIMIT);
    }

    private function admin(User $u, string $today): void
    {
        $this->add('Ajukan penempatan ke KSM', 'Periksa & ajukan', 'admissions.show', $this->placements(['draft'])->get(['id as placement_id']));
        $this->add('Tinjau penerimaan yang ditolak', 'Baca alasan', 'admissions.show', $this->placements(['ditolak_ksm', 'ditolak_kordik'])->get(['id as placement_id']));
        $this->add('Periksa dokumen peserta', 'Periksa dokumen', 'admissions.show', $this->placements(['menunggu_dokumen'])->get(['id as placement_id']));
        $this->add('Aktifkan akun peserta', 'Aktifkan akun', 'admissions.participants', DB::table('placements as p')->join('participants as s', 's.id', '=', 'p.participant_id')
            ->whereNull('s.user_id')->where('p.status', 'terverifikasi')->limit(self::LIMIT)->get(['p.id as placement_id', 's.number as query']));
        $this->add('Mulai stase', 'Mulai', 'scheduling.show', $this->placements(['dijadwalkan'])->where('start_date', '<=', $today)->where('end_date', '>=', $today)->get(['id as placement_id']));
        $this->add('Cocokkan respons survei', 'Cocokkan kode', 'completion.show', DB::table('survey_responses as r')->join('placements as p', 'p.id', '=', 'r.placement_id')
            ->where('r.status', 'submitted')->whereIn('p.status', ['sedang_stase', 'menunggu_penyelesaian'])->limit(self::LIMIT)
            ->get(['r.placement_id', 'r.kind as detail'])->each(fn ($r) => $r->detail = SurveyService::KINDS[$r->detail] ?? $r->detail));
        $this->add('Periksa kelengkapan & ajukan penyelesaian', 'Buka checklist', 'completion.show', $this->placements(['sedang_stase'])->where('end_date', '<', $today)
            ->whereExists(fn ($q) => $q->from('attendance_summaries')->whereColumn('placement_id', 'placements.id')->where('status', 'sealed'))
            ->whereNotExists(fn ($q) => $q->from('completion_requests')->whereColumn('placement_id', 'placements.id')->whereIn('status', ['pending', 'approved']))->get(['id as placement_id']));
        $this->add('Laksanakan pembukaan kembali', 'Laksanakan', 'completion.show', DB::table('completion_requests')->where('kind', 'reopen')->where('status', 'approved')
            ->where('decided_by', '!=', $u->id)->limit(self::LIMIT)->get(['placement_id']));
    }

    private function mentor(User $u): void
    {
        $mine = fn () => app(LogbookAccess::class)->assignments($u, 'mentor')->select('a.id');
        $perPlacement = fn (Builder $q, string $unit) => $q->groupBy('placement_id')->selectRaw('placement_id, COUNT(*) as total')->limit(self::LIMIT)->get()
            ->each(fn ($r) => $r->detail = $r->total.' '.$unit);
        $this->add('Setujui jadwal peserta', 'Periksa jadwal', 'scheduling.show', $perPlacement(DB::table('schedules')->where('status', 'submitted')->whereIn('mentor_assignment_id', $mine()), 'jadwal'));
        $this->add('Verifikasi presensi', 'Verifikasi', 'attendance.show', $perPlacement(DB::table('attendances')->whereIn('status', ['waiting', 'corrected'])->whereIn('mentor_assignment_id', $mine()), 'hari'));
        $this->add('Periksa logbook peserta', 'Periksa', 'logbooks.show', DB::table('logbooks')->where('kind', 'participant')->where('status', 'submitted')
            ->whereIn('reviewer_assignment_id', $mine())->limit(self::LIMIT)->get(['placement_id', 'ulid as param', 'type as detail']));
        $grades = fn (string $status) => DB::table('assessments')->where('status', $status)->whereIn('mentor_assignment_id', $mine())->limit(self::LIMIT)->get(['placement_id', 'ulid as param', 'title as detail']);
        $this->add('Sahkan & publikasikan nilai', 'Buka nilai', 'assessments.show', $grades('draft')->merge($grades('approved')));
        $this->add('Tanggapi keberatan nilai', 'Tanggapi', 'assessments.show', DB::table('grade_appeals as g')->join('assessments as s', 's.id', '=', 'g.assessment_id')
            ->whereIn('g.status', ['submitted', 'reviewing'])->whereColumn('g.version', 's.published_version')->whereIn('s.mentor_assignment_id', $mine())->limit(self::LIMIT)
            ->get(['s.placement_id', 's.ulid as param', 's.title as detail']));
    }

    private function participant(User $u, string $today): void
    {
        $own = DB::table('placements')->whereNull('archived_at')->whereIn('participant_id', DB::table('participants')->where('user_id', $u->id)->select('id'))->get();
        $one = fn (object $p, ?string $detail = null, ?string $param = null) => [(object) ['placement_id' => $p->id, 'detail' => $detail, 'param' => $param]];
        foreach ($own as $p) {
            $schedules = DB::table('schedules')->where('placement_id', $p->id)->pluck('status', 'date');
            $counts = DB::table('schedules')->where('placement_id', $p->id)->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
            if (in_array($p->status, ScheduleService::OPEN_PLACEMENTS)) {
                $hasMentor = DB::table('educator_assignments')->where('placement_id', $p->id)->where('role', 'mentor')->where('status', 'approved')->exists();
                if ($hasMentor && $schedules->isEmpty()) {
                    $this->add('Susun jadwal stase', 'Susun jadwal', 'scheduling.show', $one($p, Ui::period($p->start_date, $p->end_date)));
                }
                foreach (['draft' => ['Ajukan jadwal ke pembimbing', 'Ajukan'], 'revision' => ['Perbaiki jadwal yang diminta revisi', 'Perbaiki'], 'approved' => ['Terbitkan jadwal yang disetujui', 'Terbitkan']] as $status => [$title, $cta]) {
                    if ($counts[$status] ?? 0) {
                        $this->add($title, $cta, 'scheduling.show', $one($p, $counts[$status].' jadwal'));
                    }
                }
            }
            if (in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']) && ! app(AttendanceService::class)->sealed($p)) {
                $rows = DB::table('attendances')->where('placement_id', $p->id)->pluck('status', 'date');
                $days = array_filter(app(AttendanceService::class)->days($p), fn ($d) => $d <= $today);
                $missing = array_diff($days, $rows->keys()->all());
                if ($missing) {
                    $this->add('Isi presensi', in_array($today, $missing, true) ? 'Isi presensi hari ini' : 'Isi presensi', 'attendance.show', $one($p, count($missing).' hari belum diisi'));
                }
                $redo = $rows->filter(fn ($s) => in_array($s, ['draft', 'rejected']))->count();
                if ($redo) {
                    $this->add('Perbaiki / ajukan presensi', 'Buka presensi', 'attendance.show', $one($p, $redo.' hari'));
                }
            }
            if (in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian'])) {
                $books = DB::table('logbooks')->where('placement_id', $p->id)->where('kind', 'participant')->where('author_id', $u->id)->get();
                if ($books->isEmpty()) {
                    $this->add('Unggah logbook', 'Unggah', 'logbooks.placement', $one($p));
                }
                foreach ($books->whereIn('status', ['draft', 'revision', 'rejected']) as $b) {
                    $this->add($b->status === 'draft' ? 'Ajukan logbook ke pembimbing' : 'Perbaiki logbook', 'Buka logbook', 'logbooks.show', $one($p, $b->type, $b->ulid));
                }
                $surveys = DB::table('survey_responses')->where('placement_id', $p->id)->pluck('status', 'kind');
                foreach (SurveyService::KINDS as $kind => $label) {
                    if (in_array($surveys[$kind] ?? '', ['', 'issued', 'rejected'], true)) {
                        $this->add('Isi survei', 'Buka survei', 'completion.show', $one($p, $label));
                    }
                }
            }
        }
    }

    private function add(string $title, string $cta, string $route, iterable $rows, ?string $bulk = null): void
    {
        foreach ($rows as $row) {
            $this->groups[$title]['bulk'] = $bulk;
            $this->groups[$title]['cta'] = $cta;
            $this->groups[$title]['route'] = $route;
            $this->groups[$title]['rows'][] = $row;
        }
    }

    private function hydrate(): array
    {
        $ids = collect($this->groups)->flatMap(fn ($g) => array_map(fn ($r) => $r->placement_id, $g['rows']))->unique();
        $placements = DB::table('placements')->whereIn('id', $ids)->get(['id', 'ulid', 'participant_id', 'snapshot', 'start_date', 'end_date'])->keyBy('id');
        $names = DB::table('participants')->whereIn('id', $placements->pluck('participant_id'))->pluck('name', 'id');
        $out = [];
        foreach ($this->groups as $title => $g) {
            $items = [];
            foreach ($g['rows'] as $row) {
                $p = $placements[$row->placement_id] ?? null;
                if (! $p) {
                    continue;
                }
                $items[] = [
                    'name' => $names[$p->participant_id] ?? 'Peserta',
                    'info' => json_decode($p->snapshot)->department.' · '.Ui::period($p->start_date, $p->end_date),
                    'detail' => $row->detail ?? null,
                    'url' => isset($row->query) ? route($g['route'], ['q' => $row->query]) : route($g['route'], $row->param ?? $p->ulid),
                ];
            }
            $out[] = ['title' => $title, 'cta' => $g['cta'], 'items' => $items, 'bulk' => $g['bulk'] && count($items) > 1 ? route($g['bulk']) : null];
        }

        return $out;
    }
}
