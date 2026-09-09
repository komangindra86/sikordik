<?php

namespace App\Http\Controllers;

use App\Services\ClinicalGroupService;
use App\Services\EducatorAssignmentService;
use App\Services\PlacementExtensionService;
use App\Services\ScheduleService;
use App\Services\SchedulingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchedulingController extends Controller
{
    public function index(Request $r, SchedulingAccess $a)
    {
        $placements = $a->placements($r->user())->whereIn('status', ['terverifikasi', 'dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai', 'dibatalkan'])
            ->select('placements.*')->selectSub(DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id'), 'participant_name')
            ->orderByDesc('id')->paginate(20);
        $groups = DB::table('clinical_groups')->whereIn('department_id', $a->placements($r->user())->select('department_id'))->orderBy('name')->get();
        $departments = DB::table('departments')->where('is_active', true)->when(! $a->admin($r->user()), fn ($q) => $q->whereIn('id', $r->user()->departmentScopeIds()))->get();

        return view('scheduling.index', compact('placements', 'groups', 'departments', 'a'));
    }

    public function show(Request $r, string $ulid, SchedulingAccess $a)
    {
        $p = $a->placement($r->user(), $ulid);
        $participant = DB::table('participants')->where('id', $p->participant_id)->first(['name', 'number']);
        $assignments = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.placement_id', $p->id)->select('a.*', 'e.name as educator_name')->orderByDesc('a.id')->get();
        $schedules = DB::table('schedules')->where('placement_id', $p->id)->select('schedules.*')
            ->selectSub(DB::table('clinical_locations')->whereColumn('clinical_locations.id', 'schedules.clinical_location_id')->select('name'), 'location_name')
            ->selectSub(DB::table('clinical_groups')->whereColumn('clinical_groups.id', 'schedules.clinical_group_id')->select('name'), 'group_name')
            ->orderByDesc('date')->orderByDesc('id')->paginate(20);
        $groups = DB::table('clinical_groups')->where('department_id', $p->department_id)->get();
        $memberships = DB::table('group_memberships as m')->join('clinical_groups as g', 'g.id', '=', 'm.clinical_group_id')->where('m.placement_id', $p->id)->select('m.*', 'g.name')->get();
        $educators = DB::table('educators')->where('department_id', $p->department_id)->where('is_active', true)->get(['id', 'name']);
        $extensions = DB::table('placement_extensions')->where('placement_id', $p->id)
            ->select('placement_extensions.*')->selectSub(DB::table('private_files')->whereColumn('private_files.id', 'placement_extensions.supporting_file_id')->select('ulid'), 'supporting_file_ulid')->orderByDesc('id')->get();
        $supportingFiles = $a->admin($r->user()) ? DB::table('private_files')->where('resource_type', 'placement')->where('resource_id', $p->id)->where('category', 'pendukung')->where('scan_status', 'clean')->get(['id', 'original_name']) : collect();
        $histories = DB::table('scheduling_histories')->join('users as actors', 'actors.id', '=', 'scheduling_histories.actor_id')->select('scheduling_histories.*', 'actors.name as actor_name')->where(function ($q) use ($p) {
            foreach (['educator_assignments', 'schedules', 'placement_extensions', 'group_memberships'] as $table) {
                $q->orWhere(fn ($q) => $q->where('resource_type', $table)->whereIn('resource_id', DB::table($table)->where('placement_id', $p->id)->select('id')));
            }
        })->orderByDesc('scheduling_histories.id')->limit(100)->get();

        return view('scheduling.show', compact('p', 'participant', 'assignments', 'schedules', 'groups', 'memberships', 'educators', 'extensions', 'supportingFiles', 'histories', 'a'));
    }

    public function form(Request $r, string $ulid, SchedulingAccess $a, ?string $schedule = null)
    {
        $p = $a->placement($r->user(), $ulid);
        abort_unless($a->owner($r->user(), $p) || $a->manage($r->user(), $p->department_id), 403);
        $row = $schedule ? DB::table('schedules')->where('placement_id', $p->id)->where('ulid', $schedule)->firstOrFail() : null;
        $change = $row && $row->status === 'published';
        abort_if($row && ! in_array($row->status, ['draft', 'revision', 'published']), 422);
        $assignments = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->where('a.placement_id', $p->id)->where('a.status', 'approved')->select('a.*', 'e.name')->get();
        $groups = DB::table('clinical_groups')->whereIn('id', DB::table('group_memberships')->where('placement_id', $p->id)->select('clinical_group_id'))->get();
        $locations = DB::table('clinical_locations')->where('is_active', true)->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $p->department_id))->get();

        return view('scheduling.form', compact('p', 'row', 'change', 'assignments', 'groups', 'locations'));
    }

    public function save(Request $r, string $ulid, ScheduleService $s, ?string $schedule = null)
    {
        $s->save($r->user(), $ulid, $r->all(), $schedule);

        return redirect()->route('scheduling.show', $ulid)->with('status', 'Draft jadwal tersimpan. Ajukan untuk persetujuan pembimbing.');
    }

    public function transition(Request $r, string $ulid, ScheduleService $s)
    {
        $s->transition($r->user(), $ulid, $r->all());

        return back()->with('status', 'Status jadwal diperbarui.');
    }

    public function assignment(Request $r, string $ulid, EducatorAssignmentService $s)
    {
        $s->request($r->user(), $ulid, $r->all());

        return back()->with('status', 'Penugasan diajukan kepada KSM.');
    }

    public function decideAssignment(Request $r, string $ulid, EducatorAssignmentService $s)
    {
        $s->decide($r->user(), $ulid, $r->all());

        return back()->with('status', 'Keputusan penugasan tersimpan.');
    }

    public function group(Request $r, ClinicalGroupService $s)
    {
        $s->create($r->user(), $r->all());

        return back()->with('status', 'Kelompok dibuat.');
    }

    public function join(Request $r, string $ulid, ClinicalGroupService $s)
    {
        $s->join($r->user(), $ulid, $r->all());

        return back()->with('status', 'Keanggotaan kelompok tersimpan.');
    }

    public function endMembership(Request $r, int $id, ClinicalGroupService $s)
    {
        $s->end($r->user(), $id, $r->all());

        return back()->with('status', 'Keanggotaan diakhiri dengan histori.');
    }

    public function extension(Request $r, string $ulid, PlacementExtensionService $s)
    {
        $s->request($r->user(), $ulid, $r->all());

        return back()->with('status', 'Perpanjangan diajukan kepada KSM.');
    }

    public function decideExtension(Request $r, string $ulid, PlacementExtensionService $s)
    {
        $s->decide($r->user(), $ulid, $r->all());

        return back()->with('status', 'Keputusan perpanjangan tersimpan.');
    }

    public function start(Request $r, string $ulid, PlacementExtensionService $s)
    {
        $r->validate(['revision' => 'required|integer|min:1']);
        $s->start($r->user(), $ulid, (int) $r->input('revision'));

        return back()->with('status', 'Kegiatan stase dimulai.');
    }

    public function licenses(Request $r, SchedulingAccess $a)
    {
        abort_unless($a->admin($r->user()), 403);
        $educators = DB::table('educators')->orderBy('name')->get(['id', 'name']);
        $licenses = DB::table('educator_licenses as l')->join('educators as e', 'e.id', '=', 'l.educator_id')->select('l.*', 'e.name')->orderByDesc('l.id')->paginate(20);

        return view('scheduling.licenses', compact('educators', 'licenses'));
    }

    public function license(Request $r, EducatorAssignmentService $s)
    {
        $r->validate(['educator_id' => 'required|integer', 'id' => 'nullable|integer']);
        $s->license($r->user(), (int) $r->input('educator_id'), $r->all(), $r->filled('id') ? (int) $r->input('id') : null);

        return back()->with('status', 'Lisensi tersimpan beserta riwayat.');
    }

    public function notifications(Request $r)
    {
        $notifications = DB::table('scheduling_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->paginate(20);

        return view('scheduling.notifications', compact('notifications'));
    }

    public function readNotification(Request $r, string $ulid)
    {
        $n = DB::table('scheduling_notifications')->where('ulid', $ulid)->where('user_id', $r->user()->id)->firstOrFail();
        DB::table('scheduling_notifications')->where('id', $n->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notifikasi ditandai dibaca.');
    }
}
