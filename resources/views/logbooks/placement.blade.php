<x-layouts.app title="Logbook penempatan">
    <x-placement-header :p="$p" active="logbook" />
    <div class="mb-5 flex flex-wrap gap-3">
        @if(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']))
            @if($access->owner(auth()->user(), $p))<a class="btn-primary" href="{{ route('logbooks.create', [$p->ulid, 'kind' => 'participant']) }}">Unggah logbook peserta</a>@endif
            @if($access->assignments(auth()->user(), 'mentor')->where('a.placement_id', $p->id)->exists())<a class="btn-primary" href="{{ route('logbooks.create', [$p->ulid, 'kind' => 'educator']) }}">Catat kegiatan pembimbing</a>@endif
        @else
            <p class="badge badge-muted">Perubahan logbook dikunci pada tahap ini.</p>
        @endif
        <a class="btn-secondary" href="{{ route('logbooks.report', $p->ulid) }}">Rekap kegiatan</a>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($rows as $r)
            <a class="card block hover:border-brand-500" href="{{ route('logbooks.show', $r->ulid) }}"><p class="text-xs text-slate-500">{{ $r->kind === 'participant' ? 'Logbook peserta' : 'Kegiatan pembimbing' }} · versi {{ $r->current_version }}</p><h2 class="mt-1 break-words font-semibold">{{ $r->type }}</h2>
                <p class="mt-3">@if($p->status === 'selesai' && $r->status === 'approved')<x-badge tone="ok" label="Dikunci — disetujui" />@else<x-badge kind="logbook" :value="$r->status" />@endif</p></a>
        @empty
            <p class="card text-slate-600 md:col-span-2">Belum ada logbook pada penempatan ini.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-layouts.app>
