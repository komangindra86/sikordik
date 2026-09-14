<x-layouts.app title="Penilaian">
    <h1 class="text-2xl font-bold">Penilaian</h1>
    <p class="my-3 text-sm text-slate-600">Nilai mengikuti formulir institusi. Pembimbing mengesahkan dan memublikasikan langsung kepada peserta.</p>
    @if(app(\App\Services\AdmissionsAccess::class)->admin(auth()->user()))<a class="btn-secondary mb-5" href="{{ route('assessments.templates') }}">Kelola template penilaian</a>@endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($placements as $p)
            <a class="card block p-5" href="{{ route('assessments.placement', $p->ulid) }}"><p class="font-semibold">{{ $p->participant_name }}</p><p class="mt-2 text-sm">{{ json_decode($p->snapshot)->institution }} · {{ json_decode($p->snapshot)->department }}</p><p class="mt-2 text-sm">{{ \Carbon\Carbon::parse($p->start_date)->format('d-m-Y') }} – {{ \Carbon\Carbon::parse($p->end_date)->format('d-m-Y') }}</p></a>
        @empty<p class="card p-5">Belum ada penempatan dalam akses Anda.</p>@endforelse
    </div>
    <div class="mt-4">{{ $placements->links() }}</div>
</x-layouts.app>
