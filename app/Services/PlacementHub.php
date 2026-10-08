<?php

namespace App\Services;

use App\Models\User;
use App\Support\Ui;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One entry point per placement. It never grants access by itself: every tab is shown
 * only when the module behind it already lets this user open the placement.
 */
class PlacementHub
{
    public const STEPS = ['Penerimaan', 'Dokumen', 'Jadwal', 'Stase', 'Selesai'];

    private function modules(): array
    {
        return [
            'documents' => ['Dokumen', 'admissions.show', AdmissionsAccess::class],
            'schedule' => ['Pembimbing & jadwal', 'scheduling.show', SchedulingAccess::class],
            'attendance' => ['Presensi', 'attendance.show', AttendanceAccess::class],
            'logbook' => ['Logbook', 'logbooks.placement', LogbookAccess::class],
            'grades' => ['Nilai', 'assessments.placement', AssessmentAccess::class],
            'completion' => ['Survei & penyelesaian', 'completion.show', AdmissionsAccess::class],
        ];
    }

    public function placements(User $u): Builder
    {
        return DB::table('placements')->where(function ($q) use ($u) {
            foreach ([AdmissionsAccess::class, SchedulingAccess::class, AttendanceAccess::class, LogbookAccess::class, AssessmentAccess::class] as $access) {
                $q->orWhereIn('placements.id', app($access)->placements($u)->select('placements.id'));
            }
        });
    }

    public function placement(User $u, string $ulid): object
    {
        return $this->placements($u)->where('ulid', $ulid)->firstOrFail();
    }

    /** @return array<string, array{label: string, url: string}> */
    public function tabs(User $u, object $p): array
    {
        $tabs = [];
        if ($this->placements($u)->where('placements.id', $p->id)->exists()) {
            $tabs['overview'] = ['label' => 'Ringkasan', 'url' => route('placements.show', $p->ulid)];
        }
        foreach ($this->modules() as $key => [$label, $route, $access]) {
            if (app($access)->placements($u)->where('placements.id', $p->id)->exists()) {
                $tabs[$key] = ['label' => $label, 'url' => route($route, $p->ulid)];
            }
        }

        return $tabs;
    }

    /** Index of the step the placement is in now; count(STEPS) means everything is done, -1 means stopped. */
    public function step(object $p): int
    {
        return match ($p->status) {
            'draft', 'menunggu_konfirmasi_ksm', 'diterima_ksm', 'menunggu_persetujuan_kordik' => 0,
            'menunggu_dokumen' => 1,
            'terverifikasi' => 2,
            'dijadwalkan', 'sedang_stase' => 3,
            'menunggu_penyelesaian' => 4,
            'selesai' => 5,
            default => -1,
        };
    }

    /** Plain sentence saying who has to do what next. */
    public function next(object $p): string
    {
        $today = now()->toDateString();
        $count = fn (string $table, array $where = [], array $in = []) => DB::table($table)->where('placement_id', $p->id)->where($where)
            ->when($in, fn ($q) => $q->whereIn('status', $in))->count();

        return match ($p->status) {
            'draft' => 'Admin Kordik memeriksa data lalu mengajukan ke KSM.',
            'menunggu_konfirmasi_ksm' => 'Ketua KSM mengonfirmasi kesediaan menerima peserta.',
            'diterima_ksm', 'menunggu_persetujuan_kordik' => 'Tim Kordik memutuskan penerimaan.',
            'menunggu_dokumen' => 'Admin Kordik memeriksa dokumen persyaratan ('.$count('placement_documents', [], ['valid', 'exception']).' dari '.$count('placement_documents').' lengkap).',
            'terverifikasi' => match (true) {
                $count('educator_assignments', ['role' => 'mentor'], ['approved']) === 0 && $count('educator_assignments', ['role' => 'mentor'], ['pending']) > 0 => 'Ketua KSM menyetujui pembimbing yang diajukan.',
                $count('educator_assignments', ['role' => 'mentor'], ['approved']) === 0 => 'Admin Kordik atau Sekretariat KSM mengajukan pembimbing.',
                $count('schedules', [], ['submitted']) > 0 => 'Pembimbing memeriksa jadwal yang diajukan peserta.',
                $count('schedules', [], ['approved']) > 0 => 'Peserta menerbitkan jadwal yang sudah disetujui.',
                $count('schedules', [], ['draft', 'revision']) > 0 => 'Peserta melengkapi dan mengajukan jadwal ke pembimbing.',
                default => 'Peserta menyusun jadwal stase bersama pembimbing.',
            },
            'dijadwalkan' => match (true) {
                $p->start_date > $today => 'Stase dimulai pada '.Ui::date($p->start_date).'.',
                $p->end_date < $today => 'Periode sudah berakhir tetapi stase belum dimulai di sistem. Admin Kordik menindaklanjuti.',
                default => 'Admin Kordik memulai stase.',
            },
            'sedang_stase' => $p->end_date >= $today ? 'Stase berjalan: isi presensi harian, logbook, penilaian, dan kedua survei.'
                : 'Periode berakhir. Lengkapi daftar kelengkapan, lalu Admin Kordik mengajukan penyelesaian.',
            'menunggu_penyelesaian' => DB::table('completion_requests')->where('placement_id', $p->id)->where('status', 'pending')->exists()
                ? 'Tim Kordik memutuskan penyelesaian stase.' : 'Admin Kordik memeriksa ulang kelengkapan dan mengajukan penyelesaian.',
            'selesai' => 'Stase selesai. Data dikunci dan tetap dapat dibaca.',
            'ditolak_ksm', 'ditolak_kordik' => 'Admin Kordik membaca alasan penolakan dan merevisi bila akan diajukan ulang.',
            default => 'Penempatan dibatalkan. Riwayat tetap tersimpan.',
        };
    }

    /** Progress per module, limited to what the user may already see in that module. */
    public function overview(User $u, object $p, array $tabs): array
    {
        $cards = [];
        if (isset($tabs['documents'])) {
            $docs = DB::table('placement_documents')->where('placement_id', $p->id)->pluck('status');
            $cards['documents'] = ['value' => $docs->filter(fn ($s) => in_array($s, ['valid', 'exception']))->count().' / '.$docs->count(), 'hint' => 'dokumen lengkap'];
        }
        if (isset($tabs['schedule'])) {
            $mentors = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.placement_id', $p->id)->where('a.role', 'mentor')->where('a.status', 'approved')->pluck('e.name');
            $schedules = DB::table('schedules')->where('placement_id', $p->id)->pluck('status');
            $cards['schedule'] = ['value' => $schedules->filter(fn ($s) => in_array($s, ['published', 'completed']))->count().' jadwal terbit',
                'hint' => $mentors->isEmpty() ? 'Belum ada pembimbing' : 'Pembimbing: '.$mentors->implode(', ')];
        }
        if (isset($tabs['attendance'])) {
            $snapshot = app(AttendanceService::class)->snapshot($p);
            $verified = count($snapshot['rows']) - $snapshot['pending'];
            $cards['attendance'] = ['value' => $verified.' / '.count($snapshot['days']), 'hint' => 'hari terverifikasi'.(count($snapshot['missing']) ? ', '.count($snapshot['missing']).' belum diisi' : '')];
        }
        if (isset($tabs['logbook'])) {
            $books = app(LogbookAccess::class)->rows($u, $p)->pluck('status');
            $cards['logbook'] = ['value' => $books->filter(fn ($s) => in_array($s, ['approved', 'locked']))->count().' / '.$books->count(), 'hint' => 'logbook disetujui'];
        }
        if (isset($tabs['grades'])) {
            $grades = app(AssessmentAccess::class)->rows($u, $p)->get(['status', 'published_version']);
            $cards['grades'] = ['value' => $grades->whereNotNull('published_version')->count().' / '.$grades->count(), 'hint' => 'nilai dipublikasikan'];
        }
        if (isset($tabs['completion'])) {
            $cards['completion'] = ['value' => DB::table('survey_responses')->where('placement_id', $p->id)->where('status', 'verified')->count().' / '.count(SurveyService::KINDS), 'hint' => 'survei terverifikasi'];
        }

        return $cards;
    }
}
