<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function data(User $u): array
    {
        $a = app(AdmissionsAccess::class);
        $p = app(SchedulingAccess::class)->placements($u);
        $counts = (clone $p)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $cards = [];
        if ($a->role($u, ['ketua-ksm', 'sekretariat-ksm'])) {
            $cards[] = ['label' => 'KSM dalam cakupan', 'value' => count($u->departmentScopeIds()), 'url' => route('scheduling.index')];
        }
        foreach (['sedang_stase' => 'Peserta aktif', 'menunggu_konfirmasi_ksm' => 'Menunggu KSM', 'menunggu_persetujuan_kordik' => 'Menunggu Kordik', 'menunggu_dokumen' => 'Menunggu dokumen', 'menunggu_penyelesaian' => 'Menunggu penyelesaian', 'selesai' => 'Penempatan selesai'] as $status => $label) {
            $cards[] = ['label' => $label, 'value' => $counts[$status] ?? 0, 'url' => route('reports', ['status' => $status])];
        }
        $attendance = app(AttendanceAccess::class)->placements($u)->select('id');
        $cards[] = ['label' => 'Presensi menunggu verifikasi', 'value' => DB::table('attendances')->whereIn('placement_id', clone $attendance)->whereIn('status', ['waiting', 'corrected'])->count(), 'url' => route('attendance.index')];
        $cards[] = ['label' => 'Rekap menunggu pengesahan', 'value' => DB::table('attendance_summaries')->whereIn('placement_id', clone $attendance)->where('status', 'draft')->count(), 'url' => route('attendance.index')];
        $admissions = $a->placements($u)->select('id');
        $cards[] = ['label' => 'Dokumen belum lengkap', 'value' => DB::table('placement_documents')->whereIn('placement_id', clone $admissions)->whereNotIn('status', ['valid', 'exception'])->count(), 'url' => route('admissions.index')];
        $cards[] = ['label' => 'Penempatan dengan survei belum lengkap', 'value' => $a->placements($u)->whereIn('status', ['sedang_stase', 'menunggu_penyelesaian'])->whereRaw('(select count(*) from survey_responses where survey_responses.placement_id = placements.id and survey_responses.status = ?) < 2', ['verified'])->count(), 'url' => route('completion.index')];
        if ($a->role($u, ['admin-kordik', 'tim-kordik', 'super-admin'])) {
            $cards[] = ['label' => 'Surat masuk', 'value' => DB::table('incoming_letters')->count(), 'url' => route('reports', ['type' => 'letters'])];
        }
        $monitor = $a->placements($u)->where(function ($q) use ($u, $a) {
            if (! $a->role($u, ['admin-kordik', 'tim-kordik', 'super-admin'])) {
                $q->whereIn('department_id', $a->role($u, ['ketua-ksm', 'sekretariat-ksm']) ? $u->departmentScopeIds() : []);
            }
        })->select('id');
        $own = DB::table('placements')->whereIn('participant_id', DB::table('participants')->where('user_id', $u->id)->select('id'))->select('id');
        $books = DB::table('logbooks')->where(function ($q) use ($u, $monitor, $own, $a) {
            $la = app(LogbookAccess::class);
            $q->whereIn('placement_id', clone $monitor)
                ->orWhere(fn ($q) => $q->where('kind', 'participant')->where('author_id', $u->id)->whereIn('placement_id', clone $own)->whereRaw($a->role($u, ['peserta']) ? '1=1' : '1=0'))
                ->orWhere(fn ($q) => $q->where('kind', 'educator')->where('author_id', $u->id)->whereIn('author_assignment_id', $la->assignments($u, 'mentor')->select('a.id')))
                ->orWhereIn('reviewer_assignment_id', $la->assignments($u, 'mentor')->select('a.id'))
                ->orWhereIn('reviewer_assignment_id', $la->assignments($u, 'supervisor')->select('a.id'));
        });
        $cards[] = ['label' => 'Logbook menunggu pemeriksaan', 'value' => (clone $books)->where('status', 'submitted')->count(), 'url' => route('logbooks.index')];
        $cards[] = ['label' => 'Logbook dalam cakupan', 'value' => $books->count(), 'url' => route('logbooks.index')];
        $ga = app(AssessmentAccess::class);
        $grades = DB::table('assessments')->where(function ($q) use ($monitor, $ga, $u, $own, $a) {
            $q->whereIn('placement_id', clone $monitor)->orWhereIn('author_assignment_id', $ga->assignments($u)->select('a.id'))
                ->orWhereIn('mentor_assignment_id', $ga->assignments($u)->where('a.role', 'mentor')->select('a.id'));
            if ($a->role($u, ['peserta'])) {
                $q->orWhere(fn ($q) => $q->whereIn('placement_id', clone $own)->whereNotNull('published_version'));
            }
        });
        if ($a->role($u, ['peserta'])) {
            $grades->where(fn ($q) => $q->whereNotIn('placement_id', clone $own)->orWhereNotNull('published_version'));
        }
        $pendingGrades = (clone $grades)->where('status', '!=', 'published');
        if ($a->role($u, ['peserta'])) {
            $pendingGrades->whereNotIn('placement_id', clone $own);
        }
        $cards[] = ['label' => 'Nilai belum dipublikasikan', 'value' => $pendingGrades->count(), 'url' => route('assessments.index')];
        $cards[] = ['label' => 'Keberatan nilai terbuka', 'value' => DB::table('grade_appeals')->whereIn('assessment_id', $grades->select('id'))->whereIn('status', ['submitted', 'reviewing', 'accepted'])->count(), 'url' => route('assessments.index')];
        $today = DB::table('schedules')->whereIn('placement_id', (clone $p)->select('id'))->where('date', now()->toDateString())->whereIn('status', ['published', 'completed'])->orderBy('id')->limit(20)->get(['date', 'activity', 'status']);
        $groups = DB::table('placements')->whereIn('placements.id', (clone $p)->select('placements.id'))->join('institutions as i', 'i.id', '=', 'placements.institution_id')->join('departments as d', 'd.id', '=', 'placements.department_id')->where('placements.status', 'sedang_stase')
            ->groupBy('i.id', 'i.name', 'd.id', 'd.name')->select('i.name as institution', 'd.name as department')->selectRaw('COUNT(*) as total')->orderBy('i.name')->limit(50)->get();

        return compact('cards', 'today', 'groups');
    }
}
