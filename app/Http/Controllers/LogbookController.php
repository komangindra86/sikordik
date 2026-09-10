<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\LogbookAccess;
use App\Services\LogbookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LogbookController extends Controller
{
    public function index(Request $request, LogbookAccess $access)
    {
        $placements = $access->placements($request->user())->whereIn('status', ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai'])
            ->addSelect(['placements.*', 'participant_name' => DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id')])->orderByDesc('id')->paginate(20);

        return view('logbooks.index', compact('placements'));
    }

    private function context(Request $request, string $ulid): array
    {
        $access = app(LogbookAccess::class);
        $ref = DB::table('logbooks')->where('ulid', $ulid)->firstOrFail();
        $p = $access->placement($request->user(), DB::table('placements')->where('id', $ref->placement_id)->value('ulid'));
        $r = $access->rows($request->user(), $p)->where('id', $ref->id)->firstOrFail();

        return compact('p', 'r', 'access');
    }

    public function placement(Request $request, string $ulid, LogbookAccess $access)
    {
        $p = $access->placement($request->user(), $ulid);
        $rows = $access->rows($request->user(), $p)->orderByDesc('id')->paginate(20);

        return view('logbooks.placement', compact('p', 'rows', 'access'));
    }

    public function form(Request $request, string $ulid, LogbookAccess $access, ?string $logbook = null)
    {
        $p = $access->placement($request->user(), $ulid);
        $r = $logbook ? $access->rows($request->user(), $p)->where('ulid', $logbook)->firstOrFail() : null;
        $kind = $r->kind ?? $request->query('kind', 'participant');
        abort_unless(in_array($kind, ['participant', 'educator']), 404);
        abort_unless($r ? $access->author($request->user(), $p, $r) : ($kind === 'participant' ? $access->owner($request->user(), $p) : $access->assignments($request->user(), 'mentor')->where('a.placement_id', $p->id)->exists()), 403);
        abort_unless(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']) && (! $r || in_array($r->status, ['draft', 'revision', 'rejected'])), 422, 'Logbook tidak dapat diubah pada status ini.');
        $v = $r ? DB::table('logbook_versions')->where('logbook_id', $r->id)->where('version', $r->current_version)->first() : null;
        $s = $v ? json_decode($v->snapshot, true) : [];
        $assignments = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->join('users as u', 'u.id', '=', 'a.educator_user_id')
            ->whereColumn('e.user_id', 'a.educator_user_id')->where('e.is_active', true)->where('u.is_active', true)
            ->where('a.placement_id', $p->id)->where('a.status', 'approved')->select('a.*', 'e.name')->get();
        $locations = DB::table('clinical_locations')->where('department_id', $p->department_id)->where('is_active', true)->get();

        return view('logbooks.form', compact('p', 'r', 'kind', 's', 'assignments', 'locations'));
    }

    public function save(Request $request, string $ulid, LogbookService $service, ?string $logbook = null)
    {
        $r = $service->save($request->user(), $ulid, $request->all(), $request->file('file'), $logbook);

        return redirect()->route('logbooks.show', $r->ulid)->with('status', 'Versi draft tersimpan. Periksa status berkas sebelum mengajukan.');
    }

    public function show(Request $request, string $ulid)
    {
        $context = $this->context($request, $ulid);
        $versions = DB::table('logbook_versions as v')->leftJoin('private_files as f', 'f.id', '=', 'v.private_file_id')->where('v.logbook_id', $context['r']->id)
            ->select('v.*', 'f.ulid as file_ulid', 'f.scan_status')->orderByDesc('v.version')->get();
        $reviews = DB::table('logbook_reviews as r')->join('users as u', 'u.id', '=', 'r.actor_id')->where('r.logbook_id', $context['r']->id)->select('r.*', 'u.name')->orderByDesc('r.id')->get();

        return view('logbooks.show', $context + compact('versions', 'reviews'));
    }

    public function transition(Request $request, string $ulid, LogbookService $service)
    {
        $service->transition($request->user(), $ulid, $request->all());

        return back()->with('status', 'Keputusan logbook tersimpan.');
    }

    public function download(Request $request, string $ulid)
    {
        $file = DB::table('private_files')->where('ulid', $ulid)->where('resource_type', 'logbook')->firstOrFail();
        $r = DB::table('logbooks')->find($file->resource_id);
        $this->context($request, $r->ulid);
        $v = DB::table('logbook_versions')->where('logbook_id', $r->id)->where('private_file_id', $file->id)->firstOrFail();
        app(LogbookService::class)->cleanFile($v);
        app(AuditLogger::class)->log('file.downloaded', 'private_file', $file->ulid);

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function report(Request $request, string $ulid, LogbookAccess $access)
    {
        $p = $access->placement($request->user(), $ulid);
        $rows = $access->rows($request->user(), $p)->join('logbook_versions as v', function ($q) {
            $q->on('v.logbook_id', '=', 'logbooks.id')->on('v.version', '=', 'logbooks.current_version');
        })->select('logbooks.*', 'v.snapshot')->orderBy('logbooks.id')->get();
        $minutes = $rows->where('kind', 'educator')->whereIn('status', ['approved', 'locked'])->sum(fn ($r) => json_decode($r->snapshot)->duration_minutes);

        return response()->view('logbooks.report', compact('p', 'rows', 'minutes'))->header('Cache-Control', 'private, no-store');
    }
}
