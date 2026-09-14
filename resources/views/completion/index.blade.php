<x-layouts.app title="Survei & penyelesaian">
    <h1 class="text-2xl font-bold">Survei & penyelesaian</h1>
    <p class="my-3 text-sm text-slate-600">Periksa kewajiban setiap penempatan sebelum persetujuan Tim Kordik.</p>
    <div class="mb-5 flex flex-wrap gap-3">
        <a class="btn-secondary" href="{{ route('completion.index') }}">Data aktif</a>
        <a class="btn-secondary" href="{{ route('completion.index', ['archive' => 1]) }}">Arsip</a>
        @if($access->admin(auth()->user()))<a class="btn-secondary" href="{{ route('completion.forms') }}">Kelola tautan survei</a>@endif
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($placements as $p)
            <a class="card block p-5" href="{{ route('completion.show', $p->ulid) }}"><p class="font-semibold">{{ $p->participant_name }}</p><p class="mt-2 text-sm">{{ json_decode($p->snapshot)->institution }} · {{ json_decode($p->snapshot)->department }}</p><p class="mt-2 text-sm">{{ $p->start_date }} – {{ $p->end_date }}</p><p class="mt-2 text-sm font-semibold">{{ str_replace('_', ' ', $p->status) }}{{ $p->archived_at ? ' · Arsip' : '' }}</p></a>
        @empty<p class="card p-5">Belum ada penempatan dalam tampilan ini.</p>@endforelse
    </div>
    <div class="mt-4">{{ $placements->links() }}</div>
</x-layouts.app>
