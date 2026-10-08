<?php

namespace App\Http\Controllers;

use App\Services\CompletionService;
use App\Services\PlacementHub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlacementController extends Controller
{
    private const FILTERS = [
        'aktif' => ['draft', 'menunggu_konfirmasi_ksm', 'diterima_ksm', 'menunggu_persetujuan_kordik', 'menunggu_dokumen', 'terverifikasi', 'dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian'],
        'selesai' => ['selesai'],
        'berhenti' => ['ditolak_ksm', 'ditolak_kordik', 'dibatalkan'],
    ];

    public function index(Request $request, PlacementHub $hub)
    {
        $data = $request->validate(['tampil' => 'nullable|in:aktif,selesai,berhenti', 'q' => 'nullable|string|max:100']);
        $filter = $data['tampil'] ?? 'aktif';
        $placements = $hub->placements($request->user())->whereIn('status', self::FILTERS[$filter])
            ->when($data['q'] ?? null, fn ($q, $search) => $q->whereIn('participant_id', DB::table('participants')->where('name', 'like', '%'.$search.'%')->orWhere('number', 'like', '%'.$search.'%')->select('id')))
            ->select('placements.*')->selectSub(DB::table('participants')->select('name')->whereColumn('participants.id', 'placements.participant_id'), 'participant_name')
            ->orderByDesc('start_date')->orderByDesc('id')->paginate(20)->withQueryString();
        foreach ($placements as $p) {
            $p->next = $hub->next($p);
        }

        return view('placements.index', compact('placements', 'filter'));
    }

    public function show(Request $request, string $ulid, PlacementHub $hub)
    {
        $p = $hub->placement($request->user(), $ulid);
        $tabs = $hub->tabs($request->user(), $p);
        $cards = $hub->overview($request->user(), $p, $tabs);
        $checklist = isset($tabs['completion']) && in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian', 'selesai']) ? app(CompletionService::class)->checklist($p)['checks'] : [];

        return response()->view('placements.show', compact('p', 'tabs', 'cards', 'checklist'))->header('Cache-Control', 'private, no-store');
    }
}
