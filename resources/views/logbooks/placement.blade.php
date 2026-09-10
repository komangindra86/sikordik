<x-layouts.app title="Logbook penempatan">
    <a class="text-sm text-brand-700" href="{{ route('logbooks.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Logbook penempatan</h1>
    <p class="mt-2 text-sm text-slate-600">{{ json_decode($p->snapshot)->institution }} · {{ json_decode($p->snapshot)->department }}</p>
    <div class="my-5 flex flex-wrap gap-3">
        @if(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']))
            @if($access->owner(auth()->user(), $p))<a class="btn-primary" href="{{ route('logbooks.create', [$p->ulid, 'kind' => 'participant']) }}">Unggah logbook peserta</a>@endif
            @if($access->assignments(auth()->user(), 'mentor')->where('a.placement_id', $p->id)->exists())<a class="btn-primary" href="{{ route('logbooks.create', [$p->ulid, 'kind' => 'educator']) }}">Catat kegiatan pembimbing</a>@endif
        @else
            <p class="badge-muted">Penempatan {{ str_replace('_', ' ', $p->status) }}: perubahan logbook dikunci.</p>
        @endif
        <a class="btn-secondary" href="{{ route('logbooks.report', $p->ulid) }}">Rekap kegiatan</a>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($rows as $r)
            <a class="card block p-5" href="{{ route('logbooks.show', $r->ulid) }}"><p class="text-xs text-slate-500">{{ $r->kind === 'participant' ? 'Logbook peserta' : 'Kegiatan pembimbing' }}</p><h2 class="mt-1 font-semibold">{{ $r->type }}</h2><p class="mt-3 text-sm">Versi {{ $r->current_version }} · <span class="badge-muted">{{ $p->status === 'selesai' && $r->status === 'approved' ? 'Dikunci — disetujui' : \App\Services\LogbookService::LABELS[$r->status] }}</span></p></a>
        @empty
            <p class="card p-6">Belum ada logbook yang dapat Anda akses pada penempatan ini.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-layouts.app>
