<x-layouts.app title="Logbook">
    <h1 class="text-2xl font-bold">Logbook</h1>
    <p class="mb-6 mt-2 text-sm text-slate-600">Dokumen institusi peserta dan catatan kegiatan pembimbing beserta pemeriksaan dan pengesahannya.</p>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($placements as $p)
            <a class="card block p-5" href="{{ route('logbooks.placement', $p->ulid) }}"><p class="font-semibold">{{ $p->participant_name }}</p><p class="mt-1 text-sm text-slate-600">{{ json_decode($p->snapshot)->department }} · {{ \Carbon\Carbon::parse($p->start_date)->format('d-m-Y') }} – {{ \Carbon\Carbon::parse($p->end_date)->format('d-m-Y') }}</p><span class="badge-muted mt-3">{{ str_replace('_', ' ', $p->status) }}</span></a>
        @empty
            <p class="card p-6">Belum ada penempatan dalam akses Anda.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $placements->links() }}</div>
</x-layouts.app>
