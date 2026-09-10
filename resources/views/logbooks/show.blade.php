<x-layouts.app title="Detail logbook">
    <a class="text-sm text-brand-700" href="{{ route('logbooks.placement', $p->ulid) }}">← Logbook penempatan</a>
    <h1 class="mt-3 break-words text-2xl font-bold">{{ $r->type }}</h1>
    <p class="mb-5 mt-2 text-sm">{{ $r->kind === 'participant' ? 'Logbook peserta' : 'Logbook pembimbing' }} · Versi {{ $r->current_version }} · <span class="badge-muted">{{ $p->status === 'selesai' && $r->status === 'approved' ? 'Dikunci — disetujui' : \App\Services\LogbookService::LABELS[$r->status] }}</span></p>
    @if(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']))
        @if($access->author(auth()->user(), $p, $r) && in_array($r->status, ['draft', 'revision', 'rejected']))
            <a class="btn-secondary mb-4" href="{{ route('logbooks.edit', [$p->ulid, $r->ulid]) }}">Tambahkan versi perbaikan</a>
            @if($r->status === 'draft')
                <form class="card mb-5 space-y-3 p-5" method="POST" action="{{ route('logbooks.transition', $r->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="submit"><label class="flex items-start gap-3 text-sm"><input class="mt-1" type="checkbox" name="confirm" value="1" required><span>Saya mengajukan versi {{ $r->current_version }} untuk diperiksa. Draft akan dikunci selama pemeriksaan.</span></label><button class="btn-primary">Ajukan verifikasi</button></form>
            @endif
        @endif
        @if($r->status === 'submitted' && $access->reviewer(auth()->user(), $r))
            <form class="card mb-5 space-y-4 p-5" method="POST" action="{{ route('logbooks.transition', $r->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}"><h2 class="font-semibold">Pemeriksaan versi {{ $r->current_version }}</h2><div><label class="label" for="note">Catatan pemeriksaan</label><textarea class="input" id="note" name="note" minlength="5" maxlength="2000" required>{{ old('note') }}</textarea></div><div><label class="label" for="action">Keputusan</label><select class="input" id="action" name="action"><option value="revision">Minta perbaikan</option><option value="approve">Setujui dan sahkan elektronik</option><option value="reject">Tolak</option></select></div><label class="flex items-start gap-3 text-sm"><input class="mt-1" type="checkbox" name="confirm" value="1" required><span>Saya telah memeriksa versi ini dan mengonfirmasi keputusan. Persetujuan menyimpan pengesahan atas nama akun saya.</span></label><button class="btn-primary">Simpan keputusan</button></form>
        @endif
    @else
        <p class="card mb-5 p-5">Perubahan logbook dikunci pada status penempatan ini.</p>
    @endif
    <div class="grid items-start gap-5 xl:grid-cols-2">
        <section class="min-w-0 space-y-4"><h2 class="text-lg font-semibold">Riwayat versi</h2>
            @foreach($versions as $v)
                @php($s = json_decode($v->snapshot, true))
                <article class="card min-w-0 space-y-3 p-5"><h3 class="font-semibold">Versi {{ $v->version }} {{ $v->version === $r->current_version ? '· Terkini' : '· Riwayat' }}</h3><p class="text-sm">{{ $s['author'] }} · {{ \Carbon\Carbon::parse($v->created_at)->format('d-m-Y H:i') }} WITA</p><p class="text-sm">{{ $s['institution'] }} · {{ $s['department'] }} · {{ $s['participant'] }}</p>
                    @if($r->kind === 'educator')<p class="text-sm">{{ \Carbon\Carbon::parse($s['date'])->format('d-m-Y') }} · {{ $s['start_time'] }}–{{ $s['end_time'] }} · {{ $s['duration_minutes'] }} menit · {{ $s['location'] }}</p><p class="whitespace-pre-line break-words text-sm">{{ $s['material'] }}</p>@endif
                    <p class="whitespace-pre-line break-words text-sm">{{ $s['notes'] }}</p>
                    @if($v->file_ulid)<p class="text-sm">Pemeriksaan PDF: <strong>{{ ['clean' => 'Lolos', 'pending' => 'Menunggu', 'held' => 'Tertahan', 'infected' => 'Ditolak', 'invalid' => 'Tidak valid', 'unavailable' => 'Pemeriksa tidak tersedia'][$v->scan_status] ?? 'Tertahan' }}</strong></p>@if($v->scan_status === 'clean')<a class="btn-secondary" href="{{ route('logbooks.download', $v->file_ulid) }}">Unduh PDF versi {{ $v->version }}</a>@else<p class="text-xs text-slate-600">Berkas belum dapat diunduh atau diajukan. Hubungi admin untuk pemeriksaan ulang berkas.</p>@endif @endif
                    <p class="break-all text-xs text-slate-500">SHA-256 versi: {{ $v->sha256 }}</p>
                </article>
            @endforeach
        </section>
        <section class="min-w-0 space-y-4"><h2 class="text-lg font-semibold">Pengajuan dan pemeriksaan</h2>
            @forelse($reviews as $review)
                <article class="card space-y-2 p-5"><h3 class="font-semibold">{{ ['submit' => 'Diajukan', 'approve' => 'Disetujui', 'revision' => 'Perlu revisi', 'reject' => 'Ditolak'][$review->action] }} · Versi {{ $review->version }}</h3><p class="text-sm">{{ $review->name }} · {{ \Carbon\Carbon::parse($review->created_at)->format('d-m-Y H:i') }} WITA</p><p class="whitespace-pre-line break-words text-sm">{{ $review->note }}</p>
                    @if($review->approval) @php($approval = json_decode($review->approval))<p class="text-sm font-semibold">Pengesahan elektronik internal</p><p class="text-sm">{{ $approval->name }} · {{ $approval->position }}</p><p class="break-all text-xs">{{ $approval->document_number }}</p><p class="break-all text-xs text-slate-500">SHA-256: {{ $approval->sha256 }}</p>@endif
                </article>
            @empty<p class="card p-5 text-sm">Belum ada pengajuan atau keputusan.</p>@endforelse
        </section>
    </div>
</x-layouts.app>
