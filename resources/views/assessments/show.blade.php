<x-layouts.app :title="$r->title">
    <a class="text-sm text-brand-700" href="{{ route('assessments.placement', $p->ulid) }}">← Nilai penempatan</a>
    <h1 class="my-3 break-words text-2xl font-bold">{{ $r->title }}</h1>
    <p class="mb-5 text-sm">{{ \App\Support\Ui::date($r->date) }} · @if($p->status === 'selesai')<x-badge tone="ok" label="Dikunci" /> @endif @if($owner)<x-badge tone="ok" label="Dipublikasikan" />@else<x-badge kind="assessment" :value="$r->status" />@endif</p>
    @php
        $writable = in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']);
        $appeal = $appeals->firstWhere('version', $r->published_version);
        $mentor = !$owner && $access->mentor(auth()->user(), $r);
        $editable = !$owner && ($access->author(auth()->user(), $r) || $mentor) && ($r->status === 'draft' || ($r->status === 'published' && $appeal?->status === 'accepted')) && (!$r->published_version || $mentor);
        $current = $versions->firstWhere('version', $r->current_version);
        $held = $current?->file_ulid && $current->scan_status !== 'clean';
    @endphp

    @if($writable && $mentor && in_array($r->status, ['draft', 'approved']))
        <section class="card mb-5"><h2 class="mb-1 font-bold">Tindakan Anda</h2>
            @if($held)<p class="text-sm text-amber-900">Formulir PDF masih diperiksa keamanannya. Nilai dapat disahkan setelah berstatus Aman.</p>
            @else
                <p class="mb-3 text-sm text-slate-600">Setelah dipublikasikan, peserta langsung melihat nilai ini dan koreksi hanya melalui keberatan.</p>
                <form class="space-y-3" method="POST" action="{{ route('assessments.transition', $r->ulid) }}" onsubmit="return confirm('Sahkan dan publikasikan versi {{ $r->current_version }} kepada peserta?')">@csrf
                    <input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="confirm" value="1"><input type="hidden" name="action" value="{{ $r->status === 'draft' ? 'release' : 'publish' }}">
                    <label class="block">Catatan (opsional)<textarea name="note" minlength="5" maxlength="2000">{{ old('note') }}</textarea></label>
                    <button class="btn-primary">{{ $r->status === 'draft' ? 'Sahkan & publikasikan' : 'Publikasikan nilai' }}</button>
                </form>
            @endif
        </section>
    @endif
    @if($writable && $mentor && $r->status === 'published' && in_array($appeal?->status, ['submitted', 'reviewing']))
        <section class="card mb-5"><h2 class="mb-1 font-bold">Tanggapi keberatan peserta</h2><p class="mb-3 text-sm text-slate-600">Jika diterima, Anda membuat versi koreksi lalu memublikasikannya. Nilai lama tetap tersimpan di riwayat.</p>
            @if($appeal->file_ulid && $appeal->scan_status !== 'clean')<p class="text-sm text-amber-900">Lampiran keberatan masih diperiksa keamanannya. Tanggapan dapat diberikan setelah berstatus Aman.</p>
            @else
                <form class="space-y-3" method="POST" action="{{ route('assessments.transition', $r->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="confirm" value="1">
                    <label class="block">Tanggapan dan alasan (minimal 5 karakter)<textarea name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea></label>
                    <div class="flex flex-wrap gap-3"><button class="btn-primary" name="action" value="accept">Terima keberatan</button><button class="btn-danger" name="action" value="reject">Tolak keberatan</button></div>
                </form>
            @endif
        </section>
    @endif
    @if($writable && $editable)<a class="btn-secondary mb-5" href="{{ route('assessments.edit', [$p->ulid, $r->ulid]) }}">{{ $r->status === 'draft' ? 'Ubah isi nilai' : 'Buat versi koreksi' }}</a>@endif

    <h2 class="mb-3 text-lg font-semibold">{{ $owner ? 'Nilai' : 'Isi dan versi nilai' }}</h2>
    <div class="space-y-4">
        @foreach($versions as $v)
            @php($s = json_decode($v->snapshot, true))
            <section class="card space-y-3">
                <h3 class="font-semibold">Versi {{ $v->version }} · {{ $s['template']['name'] }}</h3><p class="text-sm">{{ $s['participant'] }} · {{ $s['placement']['institution'] }} · {{ $s['placement']['department'] }}</p>
                <p class="text-sm">Dicatat {{ \App\Support\Ui::dateTime($v->created_at) }} oleh {{ $s['author'] }}</p>
                @foreach($s['scores'] as $score)<div class="rounded-lg bg-slate-50 p-3"><p class="text-sm font-semibold">{{ $score['component']['name'] }}</p><p class="mt-1 whitespace-pre-wrap break-words">{{ $score['value'] ?? 'Tidak diisi' }}</p>@if($score['passed'] !== null)<p class="text-sm">{{ $score['passed'] ? 'Memenuhi batas lulus komponen' : 'Belum memenuhi batas lulus komponen' }}</p>@endif</div>@endforeach
                @if($s['total'] !== null)<p class="font-semibold">Total berbobot: {{ $s['total'] }} / 100 @if($s['passed'] !== null)· {{ $s['passed'] ? 'Lulus' : 'Belum lulus' }}@endif</p>@endif
                <p class="whitespace-pre-wrap break-words text-sm">{{ $s['notes'] }}</p>
                @if($v->file_ulid)<p class="text-sm">Formulir PDF: <x-badge kind="scan" :value="$v->scan_status" /></p>@if($v->scan_status === 'clean')<a class="btn-secondary" href="{{ route('assessments.download', $v->file_ulid) }}">Unduh formulir versi {{ $v->version }}</a>@endif @endif
                <details class="text-xs text-slate-500"><summary class="cursor-pointer">Sidik digital versi</summary><p class="mt-1 break-all">Hash nilai: {{ $v->sha256 }}</p></details>
            </section>
        @endforeach
    </div>

    @if($writable && $owner && $r->status === 'published' && !$appeal)
        <details class="card mt-5"><summary class="cursor-pointer font-bold">Ajukan keberatan atas nilai ini</summary>
            <form class="mt-4 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('assessments.transition', $r->ulid) }}">@csrf
                <input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="appeal"><input type="hidden" name="confirm" value="1">
                <label class="block">Komponen yang dipermasalahkan dan alasannya<textarea name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea></label>
                <label class="block">Lampiran PDF (opsional, maksimal 10 MB)<input type="file" name="file" accept="application/pdf,.pdf"></label>
                <label class="flex items-start gap-3 text-sm font-normal"><input type="checkbox" name="deidentified" value="1" required><span>Alasan dan lampiran bebas identitas serta informasi medis sensitif pasien.</span></label>
                <button class="btn-primary">Ajukan keberatan</button>
            </form>
        </details>
    @endif

    @if($appeals->isNotEmpty())
        <h2 class="mb-3 mt-7 text-lg font-semibold">Keberatan</h2>
        <div class="space-y-4">@foreach($appeals as $a)<section class="card space-y-2"><h3 class="font-semibold">Versi {{ $a->version }} <x-badge class="ml-1" kind="appeal" :value="$a->status" /></h3><p class="text-sm">{{ \App\Support\Ui::dateTime($a->created_at) }}</p><p class="whitespace-pre-wrap break-words">{{ $a->reason }}</p><p class="whitespace-pre-wrap break-words text-sm">Tanggapan: {{ $a->response ?? 'Menunggu pembimbing' }}</p>@if($a->corrected_version)<p class="text-sm">Diselesaikan dengan publikasi versi {{ $a->corrected_version }}. Nilai versi {{ $a->version }} tetap tersedia di riwayat.</p>@endif @if($a->file_ulid)@if($a->scan_status === 'clean')<a class="btn-secondary" href="{{ route('assessments.download', $a->file_ulid) }}">Unduh lampiran keberatan</a>@else<p class="text-sm">Lampiran tertahan sampai pemeriksaan lolos.</p>@endif @endif</section>@endforeach</div>
    @endif

    <details class="mt-7"><summary class="mb-3 cursor-pointer text-lg font-semibold">Riwayat keputusan dan pengesahan</summary>
        <ol class="space-y-3">@foreach($events as $e)<li class="card space-y-2 p-4"><p class="text-sm font-semibold">{{ ['saved' => 'Draf tersimpan', 'approve' => 'Disahkan', 'publish' => 'Dipublikasikan', 'appeal' => 'Keberatan diajukan', 'review' => 'Keberatan ditinjau', 'accept' => 'Keberatan diterima', 'reject' => 'Keberatan ditolak'][$e->action] }} · Versi {{ $e->version }}</p><p class="text-sm">{{ $e->name }} · {{ \App\Support\Ui::dateTime($e->created_at) }}</p><p class="whitespace-pre-wrap break-words text-sm">{{ $e->note }}</p>@if($e->approval)@php($approval = json_decode($e->approval))<p class="break-words text-sm">{{ $approval->document_number }} · {{ $approval->name }} · {{ $approval->position }} ({{ $approval->role }})</p><p class="break-all text-xs text-slate-500">{{ $approval->sha256 }}</p>@endif</li>@endforeach</ol>
    </details>
</x-layouts.app>
