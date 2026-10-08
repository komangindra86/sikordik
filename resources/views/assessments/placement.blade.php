<x-layouts.app title="Nilai penempatan">
    <x-placement-header :p="$p" active="grades" />
    @php($owner = $access->owner(auth()->user(), $p))
    @if(! $owner && in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']) && $access->assignments(auth()->user())->where('a.placement_id', $p->id)->exists())
        <a class="btn-primary mb-5" href="{{ route('assessments.create', $p->ulid) }}">Isi penilaian</a>
    @endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($rows as $r)
            <a class="card block hover:border-brand-500" href="{{ route('assessments.show', $r->ulid) }}"><h2 class="break-words font-semibold">{{ $r->title }}</h2><p class="my-2 text-sm text-slate-600">{{ \App\Support\Ui::date($r->date) }}</p>
                @if($p->status === 'selesai')<x-badge tone="ok" label="Dikunci" /> @endif
                @if($owner)<x-badge tone="ok" label="Dipublikasikan" />@else<x-badge kind="assessment" :value="$r->status" />@endif</a>
        @empty
            <p class="card text-slate-600 md:col-span-2">{{ $owner ? 'Belum ada nilai yang dipublikasikan pembimbing.' : 'Belum ada penilaian pada penempatan ini.' }}</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-layouts.app>
