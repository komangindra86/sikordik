<?php

namespace App\Http\Controllers;

use App\Services\AttendanceAccess;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceAccess $access)
    {
        $placements = $access->placements($request->user())->whereIn('status', ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai'])
            ->addSelect(['placements.*', 'participant_name' => DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id')])->orderByDesc('id')->paginate(20);

        return view('attendance.index', compact('placements'));
    }

    public function show(Request $request, string $ulid, AttendanceAccess $access, AttendanceService $service)
    {
        $p = $access->placement($request->user(), $ulid);
        $rows = DB::table('attendances')->where('placement_id', $p->id)->orderByDesc('date')->get();
        $summaries = DB::table('attendance_summaries')->where('placement_id', $p->id)->orderByDesc('version')->get();
        $days = $service->days($p);
        $snapshot = $service->snapshot($p);
        $locations = DB::table('clinical_locations')->where('department_id', $p->department_id)->where('is_active', true)->get();
        $assignments = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.placement_id', $p->id)->where('a.role', 'mentor')->where('a.status', 'approved')->select('a.*', 'e.name')->get();
        $participant = DB::table('participants')->find($p->participant_id);
        $histories = DB::table('scheduling_histories as h')->leftJoin('users as u', 'u.id', '=', 'h.actor_id')->where(function ($q) use ($rows, $summaries) {
            $q->where(fn ($q) => $q->where('h.resource_type', 'attendances')->whereIn('h.resource_id', $rows->pluck('id')))
                ->orWhere(fn ($q) => $q->where('h.resource_type', 'attendance_summaries')->whereIn('h.resource_id', $summaries->pluck('id')));
        })->orderByDesc('h.id')->select('h.*', 'u.name as actor_name')->paginate(30, ['*'], 'history_page');
        $schedule = DB::table('schedules')->where('placement_id', $p->id)->whereIn('status', ['published', 'completed'])->orderBy('start_time')->get()->groupBy('date');
        $names = ['locations' => DB::table('clinical_locations')->whereIn('id', $rows->pluck('clinical_location_id'))->pluck('name', 'id'),
            'mentors' => DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.placement_id', $p->id)->pluck('e.name', 'a.id')];

        return view('attendance.show', compact('p', 'rows', 'summaries', 'days', 'snapshot', 'locations', 'assignments', 'participant', 'histories', 'access', 'service', 'schedule', 'names'));
    }

    public function save(Request $request, string $ulid, AttendanceService $service)
    {
        $service->save($request->user(), $ulid, $request->all());

        return back()->with('status', 'Presensi tersimpan.');
    }

    public function quick(Request $request, string $ulid, AttendanceService $service)
    {
        $service->quick($request->user(), $ulid, $request->all());

        return back()->with('status', 'Presensi hadir tercatat dan dikirim ke pembimbing.');
    }

    public function verifyMany(Request $request, string $ulid, AttendanceService $service)
    {
        return back()->with('status', $service->verifyMany($request->user(), $ulid, $request->all()).' presensi diverifikasi.');
    }

    public function decide(Request $request, string $ulid, AttendanceService $service)
    {
        $service->decide($request->user(), $ulid, $request->all());

        return back()->with('status', 'Keputusan verifikasi tersimpan.');
    }

    public function replaceVerifier(Request $request, string $ulid, AttendanceService $service)
    {
        $service->replaceVerifier($request->user(), $ulid, $request->all());

        return back()->with('status', 'Verifikator pengganti tersimpan.');
    }

    public function summary(Request $request, string $ulid, AttendanceService $service)
    {
        $service->summary($request->user(), $ulid, $request->all());

        return back()->with('status', 'Rekap presensi diperbarui.');
    }

    public function report(Request $request, string $ulid, AttendanceAccess $access)
    {
        $s = DB::table('attendance_summaries')->where('ulid', $ulid)->firstOrFail();
        $p = $access->placement($request->user(), DB::table('placements')->where('id', $s->placement_id)->value('ulid'));
        $participant = DB::table('participants')->find($p->participant_id);
        $snapshot = json_decode($s->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $approver = $s->approved_by ? DB::table('users')->where('id', $s->approved_by)->value('name') : null;

        return response()->view('attendance.report', compact('s', 'p', 'participant', 'snapshot', 'approver'))->header('Cache-Control', 'private, no-store');
    }
}
