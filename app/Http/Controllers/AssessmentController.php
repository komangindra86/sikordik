<?php

namespace App\Http\Controllers;

use App\Services\AdmissionsAccess;
use App\Services\AssessmentAccess;
use App\Services\AssessmentFileService;
use App\Services\AssessmentService;
use App\Services\AssessmentTemplateService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssessmentController extends Controller
{
    public function index(Request $request, AssessmentAccess $access)
    {
        $placements = $access->placements($request->user())->whereIn('status', ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian', 'selesai'])
            ->addSelect(['placements.*', 'participant_name' => DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id')])->orderByDesc('id')->paginate(20);

        return view('assessments.index', compact('placements'));
    }

    public function templates(Request $request)
    {
        abort_unless(app(AdmissionsAccess::class)->admin($request->user()), 403);
        $templates = DB::table('assessment_templates')->orderByDesc('id')->paginate(20);
        $components = DB::table('assessment_components')->whereIn('assessment_template_id', $templates->pluck('id'))->orderBy('position')->get()->groupBy('assessment_template_id');
        $masters = [];
        foreach (['institutions', 'study_programs', 'participant_types', 'departments'] as $table) {
            $masters[$table] = DB::table($table)->where('is_active', true)->orderBy('name')->get();
        }

        return view('assessments.templates', compact('templates', 'masters', 'components'));
    }

    public function templateStore(Request $request, AssessmentTemplateService $service)
    {
        $service->create($request->user(), $request->all());

        return back()->with('status', 'Template tetap tersimpan. Buat template baru untuk perubahan berikutnya.');
    }

    public function templateDisable(Request $request, int $id, AssessmentTemplateService $service)
    {
        $service->disable($request->user(), $id);

        return back()->with('status', 'Template dinonaktifkan untuk penilaian baru.');
    }

    public function placement(Request $request, string $ulid, AssessmentAccess $access)
    {
        $p = $access->placement($request->user(), $ulid);
        $rows = $access->rows($request->user(), $p)->orderByDesc('id')->paginate(20);

        return view('assessments.placement', compact('p', 'rows', 'access'));
    }

    private function context(Request $request, string $ulid): array
    {
        $access = app(AssessmentAccess::class);
        $ref = DB::table('assessments')->where('ulid', $ulid)->firstOrFail();
        $p = $access->placement($request->user(), DB::table('placements')->where('id', $ref->placement_id)->value('ulid'));
        $r = $access->rows($request->user(), $p)->where('id', $ref->id)->firstOrFail();
        $owner = $access->owner($request->user(), $p);

        return compact('p', 'r', 'access', 'owner');
    }

    public function form(Request $request, string $ulid, AssessmentAccess $access, ?string $assessment = null)
    {
        $p = $access->placement($request->user(), $ulid);
        abort_if($access->owner($request->user(), $p), 403);
        $r = $assessment ? $access->rows($request->user(), $p)->where('ulid', $assessment)->firstOrFail() : null;
        abort_unless(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']), 422);
        abort_unless($r ? ($access->author($request->user(), $r) || $access->mentor($request->user(), $r)) : $access->assignments($request->user())->where('a.placement_id', $p->id)->exists(), 403);
        if ($r) {
            $accepted = DB::table('grade_appeals')->where('assessment_id', $r->id)->where('version', $r->published_version)->where('status', 'accepted')->exists();
            abort_unless($r->status === 'draft' || ($r->status === 'published' && $accepted), 422);
            abort_if($r->published_version && ! $access->mentor($request->user(), $r), 403);
        }
        $date = $r->date ?? $request->query('date', now()->toDateString());
        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $templates = app(AssessmentTemplateService::class)->available($p, $date)->orderBy('name')->get();
        $template = $r ? DB::table('assessment_templates')->find($r->assessment_template_id) : $templates->firstWhere('id', $request->query('template_id'));
        $components = $template ? DB::table('assessment_components')->where('assessment_template_id', $template->id)->orderBy('position')->get() : collect();
        $v = $r ? DB::table('assessment_versions')->where('assessment_id', $r->id)->where('version', $r->current_version)->first() : null;
        $s = $v ? app(AssessmentService::class)->snapshot($v) : [];
        $assignments = DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')->join('users as u', 'u.id', '=', 'a.educator_user_id')
            ->where('a.placement_id', $p->id)->whereIn('a.role', ['mentor', 'examiner'])->where('a.status', 'approved')
            ->whereColumn('e.user_id', 'a.educator_user_id')->where('e.is_active', true)->where('u.is_active', true)->select('a.*', 'e.name')->get();

        return view('assessments.form', compact('p', 'r', 'date', 'templates', 'template', 'components', 's', 'assignments'));
    }

    public function save(Request $request, string $ulid, AssessmentService $service, ?string $assessment = null)
    {
        $r = $service->save($request->user(), $ulid, $request->all(), $request->file('file'), $assessment);

        return redirect()->route('assessments.show', $r->ulid)->with('status', 'Versi draft tersimpan. Pembimbing dapat memeriksa dan mengesahkan nilai.');
    }

    public function show(Request $request, string $ulid)
    {
        $context = $this->context($request, $ulid);
        $r = $context['r'];
        $published = DB::table('assessment_events')->where('assessment_id', $r->id)->where('action', 'publish')->pluck('version');
        $versions = DB::table('assessment_versions as v')->leftJoin('private_files as f', 'f.id', '=', 'v.private_file_id')->where('v.assessment_id', $r->id)
            ->when($context['owner'], fn ($q) => $q->whereIn('v.version', $published))->select('v.*', 'f.ulid as file_ulid', 'f.scan_status')->orderByDesc('v.version')->get();
        foreach ($versions as $v) {
            app(AssessmentService::class)->snapshot($v);
        }
        $events = DB::table('assessment_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')->where('e.assessment_id', $r->id)
            ->when($context['owner'], fn ($q) => $q->whereIn('e.version', $published)->where('action', '!=', 'saved'))->select('e.*', 'u.name')->orderByDesc('e.id')->get();
        $appeals = DB::table('grade_appeals as a')->leftJoin('private_files as f', 'f.id', '=', 'a.private_file_id')->where('a.assessment_id', $r->id)->select('a.*', 'f.ulid as file_ulid', 'f.scan_status')->orderByDesc('a.id')->get();

        return response()->view('assessments.show', $context + compact('versions', 'events', 'appeals'))->header('Cache-Control', 'private, no-store');
    }

    public function transition(Request $request, string $ulid, AssessmentService $service)
    {
        $service->transition($request->user(), $ulid, $request->all(), $request->file('file'));

        return back()->with('status', 'Keputusan penilaian tersimpan.');
    }

    public function download(Request $request, string $ulid)
    {
        $file = DB::table('private_files')->where('ulid', $ulid)->whereIn('resource_type', ['assessment', 'grade_appeal'])->firstOrFail();
        if ($file->resource_type === 'assessment') {
            $r = DB::table('assessments')->find($file->resource_id);
            $context = $this->context($request, $r->ulid);
            $v = DB::table('assessment_versions')->where('assessment_id', $r->id)->where('private_file_id', $file->id)->firstOrFail();
            abort_if($context['owner'] && ! DB::table('assessment_events')->where('assessment_id', $r->id)->where('version', $v->version)->where('action', 'publish')->exists(), 404);
            app(AssessmentService::class)->integrity($v);
        } else {
            $a = DB::table('grade_appeals')->where('id', $file->resource_id)->where('private_file_id', $file->id)->firstOrFail();
            $this->context($request, DB::table('assessments')->where('id', $a->assessment_id)->value('ulid'));
            app(AssessmentFileService::class)->clean($file->id, $a->file_hash);
        }
        app(AuditLogger::class)->log('file.downloaded', 'private_file', $file->ulid);

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
