<x-layouts.app title="Ringkasan penempatan">
    <x-placement-header :p="$p" active="overview" />
    @php($titles = ['documents' => 'Dokumen persyaratan', 'schedule' => 'Pembimbing & jadwal', 'attendance' => 'Presensi', 'logbook' => 'Logbook', 'grades' => 'Nilai', 'completion' => 'Survei'])
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($cards as $key => $card)
            <a class="card block hover:border-brand-500" href="{{ $tabs[$key]['url'] }}">
                <p class="text-sm text-slate-600">{{ $titles[$key] }}</p>
                <p class="mt-1 text-2xl font-bold text-brand-700">{{ $card['value'] }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $card['hint'] }}</p>
                <p class="mt-3 text-sm font-semibold text-brand-700">Buka →</p>
            </a>
        @endforeach
    </div>
    @if($checklist)
        <section class="card mt-6"><h2 class="mb-1 text-lg font-bold">Kelengkapan untuk menutup stase</h2><p class="mb-3 text-sm text-slate-500">Stase baru dapat diselesaikan setelah semua baris berstatus Lengkap.</p>
            <ul class="space-y-2">@foreach($checklist as $c)<li class="flex items-start gap-3"><x-badge class="shrink-0" :tone="$c['ok'] ? 'ok' : 'wait'" :label="$c['ok'] ? 'Lengkap' : 'Belum'" /><span class="text-sm">{{ $c['label'] }}</span></li>@endforeach</ul>
            <a class="mt-4 inline-block text-sm font-semibold text-brand-700" href="{{ $tabs['completion']['url'] }}">Buka survei & penyelesaian →</a>
        </section>
    @endif
</x-layouts.app>
