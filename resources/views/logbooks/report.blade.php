<x-layouts.app title="Rekap kegiatan logbook">
    <a class="text-sm text-brand-700 print:hidden" href="{{ route('logbooks.placement', $p->ulid) }}">← Logbook penempatan</a>
    <h1 class="mt-3 text-2xl font-bold">Rekap kegiatan logbook</h1>
    <p class="mt-2 text-sm">{{ json_decode($p->snapshot)->institution }} · {{ json_decode($p->snapshot)->department }}</p>
    <p class="my-4 text-sm">Total kegiatan pembimbing disetujui dalam akses Anda: <strong>{{ $minutes }} menit</strong>. Draft, pengajuan, penolakan, dan revisi tidak dihitung.</p>
    <p class="mb-4 text-xs text-slate-500">Rekap terkini per {{ now()->format('d-m-Y H:i') }} WITA. Gunakan menu cetak browser untuk menyimpan PDF. Pengesahan setiap logbook tersedia pada halaman detail.</p>
    <div class="card overflow-x-auto"><table class="w-full min-w-[720px] text-left text-sm"><thead><tr class="border-b bg-slate-50"><th class="p-3">Logbook / versi</th><th class="p-3">Penulis</th><th class="p-3">Tanggal / kegiatan</th><th class="p-3">Durasi</th><th class="p-3">Status</th></tr></thead><tbody>
        @forelse($rows as $r) @php($s = json_decode($r->snapshot))
            <tr class="border-b"><td class="p-3"><a class="text-brand-700" href="{{ route('logbooks.show', $r->ulid) }}">{{ $r->type }}</a><p class="text-xs">{{ $r->kind === 'participant' ? 'Peserta' : 'Pembimbing' }} · v{{ $r->current_version }}</p></td><td class="p-3">{{ $s->author }}</td><td class="p-3">{{ isset($s->date) ? \Carbon\Carbon::parse($s->date)->format('d-m-Y') : 'Dokumen institusi' }}<p>{{ $s->location ?? '' }}</p></td><td class="p-3">{{ isset($s->duration_minutes) ? $s->duration_minutes.' menit' : '—' }}</td><td class="p-3">{{ $p->status === 'selesai' && $r->status === 'approved' ? 'Dikunci — disetujui' : \App\Services\LogbookService::LABELS[$r->status] }}</td></tr>
        @empty<tr><td colspan="5" class="p-5">Belum ada logbook dalam akses Anda.</td></tr>@endforelse
    </tbody></table></div>
</x-layouts.app>
