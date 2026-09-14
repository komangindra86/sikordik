<?php

namespace App\Http\Controllers;

use App\Services\AdmissionsAccess;
use App\Services\AssessmentService;
use App\Services\CompletionService;
use App\Services\SchedulingAccess;
use App\Services\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompletionController extends Controller
{
    public function index(Request $request, AdmissionsAccess $access)
    {
        $request->validate(['archive' => 'nullable|in:0,1']);
        $placements = $access->placements($request->user())->whereIn('status', ['sedang_stase', 'menunggu_penyelesaian', 'selesai'])
            ->when($request->boolean('archive'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->addSelect(['placements.*', 'participant_name' => DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id')])->orderByDesc('id')->paginate(20)->withQueryString();

        return view('completion.index', compact('placements', 'access'));
    }

    public function show(Request $request, string $ulid, AdmissionsAccess $access, CompletionService $service)
    {
        $p = $access->placement($request->user(), $ulid);
        $checklist = $service->checklist($p);
        $owner = app(SchedulingAccess::class)->owner($request->user(), $p);
        $surveys = DB::table('survey_responses as r')->join('survey_forms as f', 'f.id', '=', 'r.survey_form_id')->where('r.placement_id', $p->id)->select('r.*', 'f.name', 'f.url')->get()->keyBy('kind');
        $requests = DB::table('completion_requests')->where('placement_id', $p->id)->orderByDesc('id')->get();
        foreach ($requests as $r) {
            abort_unless(hash_equals($r->sha256, app(AssessmentService::class)->fingerprint(json_decode($r->snapshot, true, flags: JSON_THROW_ON_ERROR))), 423, 'Integritas riwayat penyelesaian berubah.');
            if ($r->approval) {
                abort_unless(hash_equals($r->sha256, json_decode($r->approval)->sha256), 423, 'Pengesahan tidak sesuai snapshot.');
            }
        }
        $pending = $requests->first(fn ($r) => in_array($r->status, ['pending', 'approved']));
        $history = DB::table('placement_histories')->where('placement_id', $p->id)->where('action', 'like', 'completion_%')->orderByDesc('id')->get();

        return response()->view('completion.show', compact('p', 'checklist', 'owner', 'surveys', 'requests', 'pending', 'history', 'access'))->header('Cache-Control', 'private, no-store');
    }

    public function forms(Request $request, AdmissionsAccess $access)
    {
        abort_unless($access->admin($request->user()), 403);
        $forms = DB::table('survey_forms')->orderByDesc('id')->paginate(20);

        return view('completion.forms', compact('forms'));
    }

    public function formStore(Request $request, SurveyService $service)
    {
        $service->configure($request->user(), $request->all());

        return back()->with('status', 'Tautan tersimpan. Kewajiban baru memakai formulir aktif terbaru sesuai jenis survei.');
    }

    public function formDisable(Request $request, int $id, SurveyService $service)
    {
        $service->disable($request->user(), $id);

        return back()->with('status', 'Formulir dinonaktifkan untuk token baru. Token yang sudah terbit tetap memakai formulir asal.');
    }

    public function survey(Request $request, string $ulid, SurveyService $service)
    {
        $service->act($request->user(), $ulid, $request->all());

        return back()->with('status', 'Status survei tersimpan.');
    }

    public function transition(Request $request, string $ulid, CompletionService $service)
    {
        $service->act($request->user(), $ulid, $request->all());

        return back()->with('status', 'Tindakan penyelesaian tercatat beserta riwayatnya.');
    }
}
