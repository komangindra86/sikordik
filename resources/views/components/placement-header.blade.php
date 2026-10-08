@props(['p', 'active' => 'overview'])
@php
    $hub = app(\App\Services\PlacementHub::class);
    $tabs = $hub->tabs(auth()->user(), $p);
    $step = $hub->step($p);
    $person = \Illuminate\Support\Facades\DB::table('participants')->where('id', $p->participant_id)->first(['name', 'number']);
    $snapshot = json_decode($p->snapshot);
@endphp
<a class="text-sm text-brand-700" href="{{ route('placements.index') }}">← Semua penempatan</a>
<div class="card mb-5 mt-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0"><h1 class="text-2xl font-bold">{{ $person->name }}</h1><p class="mt-1 text-sm text-slate-600">{{ $person->number }} · {{ $snapshot->department }} · {{ $snapshot->institution }}</p><p class="text-sm text-slate-600">{{ \App\Support\Ui::period($p->start_date, $p->end_date) }}</p></div>
        <x-badge :value="$p->status" />
    </div>
    @if($step >= 0)
        <ol class="mt-4 grid grid-cols-5 gap-1 text-center text-xs" aria-label="Tahap penempatan">
            @foreach(\App\Services\PlacementHub::STEPS as $i => $label)
                <li class="{{ $i < $step ? 'text-emerald-700' : ($i === $step ? 'font-bold text-brand-700' : 'text-slate-400') }}" @if($i === $step) aria-current="step" @endif>
                    <span class="mb-1 block h-1.5 rounded-full {{ $i < $step ? 'bg-emerald-500' : ($i === $step ? 'bg-brand-600' : 'bg-slate-200') }}"></span>{{ $i < $step ? '✓ ' : '' }}{{ $label }}
                </li>
            @endforeach
        </ol>
    @endif
    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm"><span class="font-semibold">Langkah berikutnya:</span> {{ $hub->next($p) }}</p>
</div>
@if(count($tabs) > 1)
<nav class="mb-6 flex gap-2 overflow-x-auto pb-1" aria-label="Bagian penempatan">
    @foreach($tabs as $key => $tab)<a class="whitespace-nowrap rounded-xl px-3 py-2 text-sm font-semibold {{ $key === $active ? 'bg-brand-600 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}" @if($key === $active) aria-current="page" @endif href="{{ $tab['url'] }}">{{ $tab['label'] }}</a>@endforeach
</nav>
@endif
