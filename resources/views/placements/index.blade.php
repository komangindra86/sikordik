@php($onlyParticipant = auth()->user()->roleCodes() === ['peserta'])
<x-layouts.app :title="$onlyParticipant ? 'Stase saya' : 'Penempatan'">
    <h1 class="text-2xl font-bold">{{ $onlyParticipant ? 'Stase saya' : 'Penempatan' }}</h1>
    <p class="mb-5 mt-2 text-sm text-slate-600">Pilih satu penempatan untuk melihat seluruh kegiatannya: dokumen, jadwal, presensi, logbook, nilai, dan penyelesaian.</p>
    <form class="card mb-5 flex flex-wrap items-end gap-3" method="GET">
        <input type="hidden" name="tampil" value="{{ $filter }}">
        <label class="min-w-48 flex-1">Cari nama atau nomor peserta<input name="q" value="{{ request('q') }}" placeholder="Contoh: Ayu atau PDK-2026-000001"></label>
        <button class="btn-primary">Cari</button>
    </form>
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach(['aktif' => 'Sedang berjalan', 'selesai' => 'Selesai', 'berhenti' => 'Ditolak / dibatalkan'] as $key => $label)
            <a class="{{ $filter === $key ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('placements.index', ['tampil' => $key, 'q' => request('q')]) }}">{{ $label }}</a>
        @endforeach
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($placements as $p)
            <a class="card block hover:border-brand-500" href="{{ route('placements.show', $p->ulid) }}">
                <div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold">{{ $p->participant_name }}</p><x-badge :value="$p->status" /></div>
                <p class="mt-1 text-sm text-slate-600">{{ json_decode($p->snapshot)->department }} · {{ \App\Support\Ui::period($p->start_date, $p->end_date) }}</p>
                <p class="mt-3 text-sm"><span class="font-semibold">Berikutnya:</span> {{ $p->next }}</p>
            </a>
        @empty
            <p class="card text-slate-600 md:col-span-2">Belum ada penempatan pada tampilan ini.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $placements->links() }}</div>
</x-layouts.app>
