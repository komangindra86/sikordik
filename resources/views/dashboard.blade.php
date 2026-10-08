<x-layouts.app title="Beranda">
<div class="mb-6"><p class="text-sm font-semibold text-brand-600">Pendidikan klinis RSBM</p><h1 class="text-2xl font-bold">Beranda</h1><p class="mt-2 text-sm text-slate-600">Kerjakan daftar di bawah dari atas ke bawah. Yang tidak ada di sini sedang menunggu orang lain.</p></div>

<section class="mb-8">
    <h2 class="mb-3 text-lg font-bold">Tugas saya @if($tasks)<span class="badge badge-wait ml-1">{{ collect($tasks)->sum(fn ($g) => count($g['items'])) }}</span>@endif</h2>
    @forelse($tasks as $group)
        <div class="card mb-4 p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3"><h3 class="font-semibold">{{ $group['title'] }} <span class="text-sm font-normal text-slate-500">({{ count($group['items']) }})</span></h3>@if($group['bulk'])<a class="btn-secondary" href="{{ $group['bulk'] }}">Putuskan sekaligus</a>@endif</div>
            <ul class="divide-y divide-slate-100">
                @foreach($group['items'] as $item)
                    <li><a class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 hover:bg-brand-50" href="{{ $item['url'] }}">
                        <span class="min-w-0"><span class="block font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block text-sm text-slate-500">{{ $item['info'] }}@if($item['detail']) · {{ $item['detail'] }}@endif</span></span>
                        <span class="btn-primary shrink-0">{{ $group['cta'] }}</span>
                    </a></li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="card text-slate-600"><p class="font-semibold text-slate-900">Tidak ada yang menunggu Anda saat ini.</p><p class="mt-1 text-sm">Tugas baru akan muncul di sini dan di Notifikasi.</p></div>
    @endforelse
</section>

@if($mine->isNotEmpty())
<section class="mb-8"><h2 class="mb-3 text-lg font-bold">{{ $mineTitle }}</h2>
    <div class="grid gap-4 md:grid-cols-2">@foreach($mine as $p)<a class="card block hover:border-brand-500" href="{{ route('placements.show', $p->ulid) }}"><p class="font-semibold">{{ $p->participant_name }}</p><p class="mt-1 text-sm text-slate-600">{{ json_decode($p->snapshot)->department }} · {{ \App\Support\Ui::period($p->start_date, $p->end_date) }}</p><x-badge class="mt-3" :value="$p->status" /></a>@endforeach</div>
</section>
@endif

<div class="grid gap-5 xl:grid-cols-2"><section class="card"><h2 class="text-lg font-bold">Jadwal hari ini</h2><ul class="mt-3 space-y-3">@forelse($today as $s)<li class="border-t pt-2"><p>{{ $s->activity }}</p><p class="text-xs text-slate-500">{{ $s->start_time ? substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5).' WITA' : 'Sepanjang hari' }} · {{ \App\Support\Ui::label('schedule', $s->status) }}</p></li>@empty<li class="text-sm text-slate-500">Belum ada jadwal terbit hari ini.</li>@endforelse</ul></section>
@if($staff)<section class="card overflow-x-auto"><h2 class="text-lg font-bold">Peserta aktif menurut institusi dan KSM</h2><table class="mt-3 w-full text-sm"><thead><tr><th class="p-2 text-left">Institusi</th><th class="p-2 text-left">KSM</th><th class="p-2">Jumlah</th></tr></thead><tbody>@forelse($groups as $g)<tr class="border-t"><td class="p-2">{{ $g->institution }}</td><td class="p-2">{{ $g->department }}</td><td class="p-2 text-center">{{ $g->total }}</td></tr>@empty<tr><td colspan="3" class="p-2 text-slate-500">Belum ada peserta aktif.</td></tr>@endforelse</tbody></table></section>@endif</div>

@if($staff)
<details class="mt-8" open><summary class="mb-3 cursor-pointer text-lg font-bold">Ringkasan angka</summary>
<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">@foreach($cards as $card)<a href="{{ $card['url'] }}" class="card p-4"><p class="text-sm text-slate-600">{{ $card['label'] }}</p><p class="mt-1 text-2xl font-bold text-brand-700">{{ number_format($card['value']) }}</p></a>@endforeach</section>
</details>
@endif
</x-layouts.app>
