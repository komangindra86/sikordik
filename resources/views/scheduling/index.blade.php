<x-layouts.app title="Kelompok & jadwal">
    <a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Kelompok & jadwal</h1>
    <p class="mb-6 mt-2 text-sm text-slate-600">Buat kelompok KSM di sini; pembimbing dan jadwal diatur dari halaman tiap penempatan.</p>
    @if($a->admin(auth()->user()) || $a->role(auth()->user(), ['sekretariat-ksm']))
        <section class="card mb-6"><h2 class="mb-3 text-lg font-bold">Kelompok KSM</h2>
            @if($groups->isNotEmpty())<p class="mb-3 text-sm">Kelompok yang ada: {{ $groups->pluck('name')->implode(', ') }}</p>@endif
            <form class="flex flex-wrap items-end gap-3" method="POST" action="{{ route('scheduling.group') }}">@csrf<label class="min-w-48 flex-1">Nama kelompok baru<input name="name" required maxlength="150"></label><label>KSM<select name="department_id" required>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label><button class="btn-primary">Buat kelompok</button></form>
            <p class="mt-3 text-sm text-slate-500">Peserta dimasukkan ke kelompok dari tab Pembimbing & jadwal pada penempatannya.</p>
        </section>
    @endif
    @include('partials.placement-cards', ['route' => 'scheduling.show', 'empty' => 'Belum ada penempatan yang siap dijadwalkan dalam akses Anda.'])
</x-layouts.app>
