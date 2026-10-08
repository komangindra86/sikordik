<x-layouts.app title="Detail logbook">
    <a class="text-sm text-brand-700" href="{{ route('logbooks.placement', $p->ulid) }}">← Logbook penempatan</a>
    <h1 class="mt-3 break-words text-2xl font-bold">{{ $r->type }}</h1>
    <p class="mb-5 mt-2 text-sm">{{ $r->kind === 'participant' ? 'Logbook peserta' : 'Kegiatan pembimbing' }} · versi {{ $r->current_version }} · @if($p->status === 'selesai' && $r->status === 'approved')<x-badge tone="ok" label="Dikunci — disetujui" />@else<x-badge kind="logbook" :value="$r->status" />@endif</p>
    @php($current = $versions->firstWhere('version', $r->current_version))
    @if(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']))
        @if($access->author(auth()->user(), $p, $r) && in_array($r->status, ['draft', 'revision', 'rejected']))
            <section class="card mb-5"><h2 class="mb-3 font-bold">Tindakan Anda</h2>
                @if($r->status !== 'draft')<p class="mb-3 text-sm">Pemeriksa meminta perbaikan. Baca catatannya di bawah, lalu unggah versi baru.</p>@endif
                <div class="flex flex-wrap gap-3">
                    @if($r->status === 'draft')
                        @if(! $current?->file_ulid || $current->scan_status === 'clean')
                            <form method="POST" action="{{ route('logbooks.transition', $r->ulid) }}" onsubmit="return confirm('Ajukan versi {{ $r->current_version }} untuk diperiksa? Draf dikunci selama pemeriksaan.')">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="submit"><input type="hidden" name="confirm" value="1"><button class="btn-primary">Ajukan ke {{ $r->kind === 'participant' ? 'pembimbing' : 'supervisor' }}</button></form>
                        @else
                            <p class="text-sm text-amber-900">Berkas masih diperiksa keamanannya. Muat ulang halaman ini sebentar lagi untuk mengajukan.</p>
                        @endif
                    @endif
                    <a class="btn-secondary" href="{{ route('logbooks.edit', [$p->ulid, $r->ulid]) }}">{{ $r->status === 'draft' ? 'Ganti isi / berkas' : 'Unggah versi perbaikan' }}</a>
                </div>
            </section>
        @endif
        @if($r->status === 'submitted' && $access->reviewer(auth()->user(), $r))
            <section class="card mb-5"><h2 class="mb-1 font-bold">Periksa versi {{ $r->current_version }}</h2><p class="mb-3 text-sm text-slate-600">Persetujuan disimpan sebagai pengesahan elektronik atas nama akun Anda.</p>
                <form class="space-y-3" method="POST" action="{{ route('logbooks.transition', $r->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="confirm" value="1">
                    <label class="block">Catatan untuk penulis (wajib bila meminta perbaikan atau menolak)<textarea name="note" minlength="5" maxlength="2000">{{ old('note') }}</textarea></label>
                    <div class="flex flex-wrap gap-3"><button class="btn-primary" name="action" value="approve">Setujui</button><button class="btn-secondary" name="action" value="revision">Minta perbaikan</button><button class="btn-danger" name="action" value="reject">Tolak</button></div>
                </form>
            </section>
        @endif
    @else
        <p class="card mb-5">Perubahan logbook dikunci pada tahap ini.</p>
    @endif
    <div class="grid items-start gap-5 xl:grid-cols-2">
        <section class="min-w-0 space-y-4"><h2 class="text-lg font-semibold">Isi dan versi</h2>
            @foreach($versions as $v)
                @php($s = json_decode($v->snapshot, true))
                <article class="card min-w-0 space-y-3"><h3 class="font-semibold">Versi {{ $v->version }} {{ $v->version === $r->current_version ? '· Terkini' : '· Riwayat' }}</h3><p class="text-sm">{{ $s['author'] }} · {{ \App\Support\Ui::dateTime($v->created_at) }} WITA</p><p class="text-sm">{{ $s['institution'] }} · {{ $s['department'] }} · {{ $s['participant'] }}</p>
                    @if($r->kind === 'educator')<p class="text-sm">{{ \App\Support\Ui::date($s['date']) }} · {{ $s['start_time'] }}–{{ $s['end_time'] }} · {{ $s['duration_minutes'] }} menit · {{ $s['location'] }}</p><p class="whitespace-pre-line break-words text-sm">{{ $s['material'] }}</p>@endif
                    <p class="whitespace-pre-line break-words text-sm">{{ $s['notes'] }}</p>
                    @if($v->file_ulid)<p class="text-sm">Berkas PDF: <x-badge kind="scan" :value="$v->scan_status" /></p>@if($v->scan_status === 'clean')<a class="btn-secondary" href="{{ route('logbooks.download', $v->file_ulid) }}">Unduh PDF versi {{ $v->version }}</a>@else<p class="text-xs text-slate-600">Berkas belum dapat diunduh atau diajukan. Hubungi Admin bila status ini tidak berubah.</p>@endif @endif
                    <details class="text-xs text-slate-500"><summary class="cursor-pointer">Sidik digital versi</summary><p class="mt-1 break-all">SHA-256: {{ $v->sha256 }}</p></details>
                </article>
            @endforeach
        </section>
        <section class="min-w-0 space-y-4"><h2 class="text-lg font-semibold">Pengajuan dan pemeriksaan</h2>
            @forelse($reviews as $review)
                <article class="card space-y-2"><h3 class="font-semibold">{{ ['submit' => 'Diajukan', 'approve' => 'Disetujui', 'revision' => 'Perlu revisi', 'reject' => 'Ditolak'][$review->action] }} · Versi {{ $review->version }}</h3><p class="text-sm">{{ $review->name }} · {{ \App\Support\Ui::dateTime($review->created_at) }} WITA</p><p class="whitespace-pre-line break-words text-sm">{{ $review->note }}</p>
                    @if($review->approval) @php($approval = json_decode($review->approval))<p class="text-sm font-semibold">Pengesahan elektronik internal</p><p class="text-sm">{{ $approval->name }} · {{ $approval->position }}</p><p class="break-all text-xs">{{ $approval->document_number }}</p><p class="break-all text-xs text-slate-500">SHA-256: {{ $approval->sha256 }}</p>@endif
                </article>
            @empty<p class="card text-sm">Belum ada pengajuan atau keputusan.</p>@endforelse
        </section>
    </div>
</x-layouts.app>
