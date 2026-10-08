{{-- One card per placement, linking into the given module page. --}}
<div class="grid gap-4 md:grid-cols-2">
    @forelse($placements as $p)
        <a class="card block hover:border-brand-500" href="{{ route($route, $p->ulid) }}">
            <div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold">{{ $p->participant_name }}</p><x-badge :value="$p->status" /></div>
            <p class="mt-1 text-sm text-slate-600">{{ json_decode($p->snapshot)->department }} · {{ \App\Support\Ui::period($p->start_date, $p->end_date) }}@if($p->archived_at ?? null) · Arsip @endif</p>
        </a>
    @empty
        <p class="card text-slate-600 md:col-span-2">{{ $empty ?? 'Belum ada penempatan pada tampilan ini.' }}</p>
    @endforelse
</div>
<div class="mt-4">{{ $placements->links() }}</div>
