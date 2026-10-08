<x-layouts.app title="Penilaian">
    <a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Penilaian</h1>
    <p class="mb-6 mt-2 text-sm text-slate-600">Nilai mengikuti formulir institusi. Pembimbing mengesahkan dan memublikasikan langsung kepada peserta.</p>
    @include('partials.placement-cards', ['route' => 'assessments.placement'])
</x-layouts.app>
