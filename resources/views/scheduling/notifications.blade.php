<x-layouts.app title="Notifikasi">
    <h1 class="mb-2 text-2xl font-bold">Notifikasi</h1>
    <p class="mb-5 text-sm text-slate-600">Pemberitahuan tentang penempatan Anda. Pekerjaan yang harus dilakukan selalu ada di Beranda.</p>
    <div class="space-y-3">
        @forelse($notifications as $n)
            <article class="card flex flex-wrap items-center justify-between gap-3 {{ $n->read_at ? '' : 'border-amber-300' }}">
                <div class="min-w-0"><p class="font-semibold">{{ $n->message }}</p><p class="text-sm text-slate-500">@if($n->participant_name){{ $n->participant_name }} · @endif{{ \App\Support\Ui::dateTime($n->created_at) }} WITA · {{ $n->read_at ? 'Sudah dibaca' : 'Belum dibaca' }}</p></div>
                <div class="flex flex-wrap gap-2">@if($n->placement_ulid && in_array($n->placement_id, $open))<a class="btn-primary" href="{{ route('placements.show', $n->placement_ulid) }}">Buka</a>@endif @if(! $n->read_at)<form method="POST" action="{{ route('scheduling.notification-read', $n->ulid) }}">@csrf<button class="btn-secondary">Tandai dibaca</button></form>@endif</div>
            </article>
        @empty
            <div class="card text-slate-500">Belum ada notifikasi.</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $notifications->links() }}</div>
</x-layouts.app>
