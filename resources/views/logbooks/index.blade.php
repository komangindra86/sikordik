<x-layouts.app title="Logbook">
    <a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Logbook</h1>
    <p class="mb-6 mt-2 text-sm text-slate-600">Logbook peserta dan catatan kegiatan pembimbing per penempatan.</p>
    @include('partials.placement-cards', ['route' => 'logbooks.placement'])
</x-layouts.app>
