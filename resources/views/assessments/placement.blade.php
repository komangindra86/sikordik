<x-layouts.app title="Penilaian penempatan">
    <a class="text-brand-700" href="{{ route('assessments.index') }}">← Semua penempatan</a>
    <h1 class="my-3 text-2xl font-bold">Penilaian penempatan</h1>
    <p class="mb-5 text-sm">{{ json_decode($p->snapshot)->institution }} · {{ json_decode($p->snapshot)->department }}</p>
    @if(!$access->owner(auth()->user(), $p) && in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']) && $access->assignments(auth()->user())->where('a.placement_id', $p->id)->exists())
        <a class="btn-primary mb-5" href="{{ route('assessments.create', $p->ulid) }}">Isi penilaian</a>
    @endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($rows as $r)
            <a class="card block p-5" href="{{ route('assessments.show', $r->ulid) }}"><h2 class="font-semibold">{{ $r->title }}</h2><p class="my-2 text-sm">{{ \Carbon\Carbon::parse($r->date)->format('d-m-Y') }}</p><span class="badge-muted">{{ $p->status === 'selesai' ? 'Dikunci · ' : '' }}{{ $access->owner(auth()->user(), $p) ? 'Dipublikasikan' : \App\Services\AssessmentService::LABELS[$r->status] }}</span></a>
        @empty<p class="card p-5">Belum ada nilai yang dapat Anda lihat.</p>@endforelse
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-layouts.app>
