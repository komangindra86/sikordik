<x-layouts.app title="Presensi">
    <a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Presensi</h1>
    <p class="mb-6 mt-2 text-sm text-slate-600">Penempatan yang presensinya dapat Anda buka. Pilih satu untuk mengisi, memverifikasi, atau mengesahkan rekap.</p>
    @include('partials.placement-cards', ['route' => 'attendance.show'])
</x-layouts.app>
