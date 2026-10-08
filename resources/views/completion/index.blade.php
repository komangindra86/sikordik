<x-layouts.app title="Survei & penyelesaian">
    <a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">{{ request()->boolean('archive') ? 'Arsip' : 'Survei & penyelesaian' }}</h1>
    <p class="my-3 text-sm text-slate-600">{{ request()->boolean('archive') ? 'Penempatan selesai yang sudah diarsipkan. Arsip tidak menghapus data.' : 'Penempatan yang sedang berjalan atau sudah selesai.' }}</p>
    <div class="mb-5 flex flex-wrap gap-2">
        <a class="{{ request()->boolean('archive') ? 'btn-secondary' : 'btn-primary' }}" href="{{ route('completion.index') }}">Data aktif</a>
        <a class="{{ request()->boolean('archive') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('completion.index', ['archive' => 1]) }}">Arsip</a>
    </div>
    @include('partials.placement-cards', ['route' => 'completion.show'])
</x-layouts.app>
