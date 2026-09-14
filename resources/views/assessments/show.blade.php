<x-layouts.app :title="$r->title">
    <a class="text-brand-700" href="{{ route('assessments.placement', $p->ulid) }}">← Penilaian penempatan</a>
    <h1 class="my-3 text-2xl font-bold">{{ $r->title }}</h1>
    <p class="mb-5 text-sm">{{ \Carbon\Carbon::parse($r->date)->format('d-m-Y') }} · <span class="badge-muted">{{ $p->status === 'selesai' ? 'Dikunci · ' : '' }}{{ $owner ? 'Dipublikasikan' : \App\Services\AssessmentService::LABELS[$r->status] }}</span></p>
    @php
        $writable = in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']);
        $appeal = $appeals->firstWhere('version', $r->published_version);
        $mentor = !$owner && $access->mentor(auth()->user(), $r);
        $editable = !$owner && ($access->author(auth()->user(), $r) || $mentor) && ($r->status === 'draft' || ($r->status === 'published' && $appeal?->status === 'accepted')) && (!$r->published_version || $mentor);
    @endphp
    <h2 class="mb-3 text-lg font-semibold">Riwayat nilai</h2>
    <div class="space-y-4">
        @foreach($versions as $v)
            @php($s = json_decode($v->snapshot, true))
            <section class="card space-y-3 p-5">
                <h3 class="font-semibold">Versi {{ $v->version }} · {{ $s['template']['name'] }}</h3><p class="text-sm">{{ $s['participant'] }} · {{ $s['placement']['institution'] }} · {{ $s['placement']['department'] }}</p>
                <p class="text-sm">Dicatat {{ \Carbon\Carbon::parse($v->created_at)->format('d-m-Y H:i') }} oleh {{ $s['author'] }}</p>
                @foreach($s['scores'] as $score)<div class="rounded-lg bg-slate-50 p-3"><p class="text-sm font-semibold">{{ $score['component']['name'] }}</p><p class="mt-1 whitespace-pre-wrap break-words">{{ $score['value'] ?? 'Tidak diisi' }}</p>@if($score['passed'] !== null)<p class="text-sm">{{ $score['passed'] ? 'Memenuhi batas lulus komponen' : 'Belum memenuhi batas lulus komponen' }}</p>@endif</div>@endforeach
                @if($s['total'] !== null)<p class="font-semibold">Total berbobot: {{ $s['total'] }} / 100 @if($s['passed'] !== null)· {{ $s['passed'] ? 'Lulus' : 'Belum lulus' }}@endif</p>@endif
                <p class="whitespace-pre-wrap break-words text-sm">{{ $s['notes'] }}</p>
                @if($v->file_ulid)<p class="text-sm">PDF: {{ $v->scan_status === 'clean' ? 'Lolos pemeriksaan' : 'Tertahan — pemeriksaan belum lolos' }}</p>@if($v->scan_status === 'clean')<a class="btn-secondary" href="{{ route('assessments.download', $v->file_ulid) }}">Unduh formulir versi {{ $v->version }}</a>@endif @endif
                <p class="break-all text-xs text-slate-500">Hash nilai: {{ $v->sha256 }}</p>
            </section>
        @endforeach
    </div>
<div class="mt-5">
    @if($writable && $editable)<a class="btn-secondary mb-5" href="{{ route('assessments.edit', [$p->ulid, $r->ulid]) }}">Simpan versi perbaikan</a>@endif
    @if($writable && $mentor && in_array($r->status, ['draft', 'approved']))
        <form class="card mb-5 space-y-4 p-5" method="POST" action="{{ route('assessments.transition', $r->ulid) }}">@csrf
            <input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="{{ $r->status === 'draft' ? 'approve' : 'publish' }}">
            <h2 class="font-semibold">{{ $r->status === 'draft' ? 'Pengesahan pembimbing' : 'Publikasi langsung kepada peserta' }}</h2>
            <label class="label" for="decision_note">Catatan keputusan</label><textarea class="input" id="decision_note" name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirm" value="1" required><span>Saya telah memeriksa versi {{ $r->current_version }} dan menyetujui {{ $r->status === 'draft' ? 'pengesahan atas nama akun saya. Versi ini tidak dapat diedit setelah disahkan.' : 'publikasi langsung kepada peserta. Koreksi setelah publikasi wajib melalui keberatan.' }}</span></label>
            <button class="btn-primary">{{ $r->status === 'draft' ? 'Sahkan nilai' : 'Publikasikan nilai' }}</button>
        </form>
    @endif
    @if($writable && $owner && $r->status === 'published' && !$appeal)
        <form class="card mb-5 space-y-4 p-5" method="POST" enctype="multipart/form-data" action="{{ route('assessments.transition', $r->ulid) }}">@csrf
            <input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="appeal">
            <h2 class="font-semibold">Ajukan keberatan nilai versi {{ $r->published_version }}</h2>
            <div><label class="label" for="appeal_note">Komponen yang dipermasalahkan dan alasan</label><textarea class="input" id="appeal_note" name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea></div>
            <div><label class="label" for="appeal_file">Lampiran PDF opsional, maksimal 10 MB</label><input class="input" type="file" id="appeal_file" name="file" accept="application/pdf,.pdf"></div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="deidentified" value="1" required><span>Alasan dan lampiran bebas identitas serta informasi medis sensitif pasien.</span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirm" value="1" required><span>Saya mengonfirmasi keberatan untuk versi nilai ini.</span></label>
            <button class="btn-primary">Ajukan keberatan</button>
        </form>
    @endif
    @if($writable && $mentor && $r->status === 'published' && in_array($appeal?->status, ['submitted', 'reviewing']))
        <form class="card mb-5 space-y-4 p-5" method="POST" action="{{ route('assessments.transition', $r->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $r->revision }}">
            <h2 class="font-semibold">Tanggapi keberatan</h2>
            <div><label class="label" for="action">Keputusan</label><select class="input" id="action" name="action">@if($appeal->status === 'submitted')<option value="review">Mulai tinjauan</option>@else<option value="accept">Terima, lanjutkan dengan versi koreksi</option><option value="reject">Tolak keberatan</option>@endif</select></div>
            <div><label class="label" for="response">Tanggapan dan alasan</label><textarea class="input" id="response" name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea></div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirm" value="1" required><span>Saya telah memeriksa keberatan dan lampirannya serta mengonfirmasi keputusan.</span></label><button class="btn-primary">Simpan tanggapan</button>
        </form>
    @endif
</div>
    <h2 class="mb-3 mt-7 text-lg font-semibold">Keberatan</h2>
    <div class="space-y-4">@forelse($appeals as $a)<section class="card space-y-2 p-5"><h3 class="font-semibold">Versi {{ $a->version }} · {{ \App\Services\AssessmentService::LABELS[$a->status] }}</h3><p class="text-sm">{{ \Carbon\Carbon::parse($a->created_at)->format('d-m-Y H:i') }}</p><p class="whitespace-pre-wrap break-words">{{ $a->reason }}</p><p class="whitespace-pre-wrap break-words text-sm">Tanggapan: {{ $a->response ?? 'Menunggu pembimbing' }}</p>@if($a->corrected_version)<p class="text-sm">Diselesaikan dengan publikasi versi {{ $a->corrected_version }}. Nilai versi {{ $a->version }} tetap tersedia di riwayat.</p>@endif @if($a->file_ulid)@if($a->scan_status === 'clean')<a class="btn-secondary" href="{{ route('assessments.download', $a->file_ulid) }}">Unduh lampiran keberatan</a>@else<p class="text-sm">Lampiran tertahan sampai pemeriksaan lolos.</p>@endif @endif</section>@empty<p class="text-sm">Belum ada keberatan.</p>@endforelse</div>
    <h2 class="mb-3 mt-7 text-lg font-semibold">Riwayat keputusan dan pengesahan</h2>
    <ol class="space-y-3">@foreach($events as $e)<li class="card space-y-2 p-4"><p class="text-sm font-semibold">{{ ['saved' => 'Draft tersimpan', 'approve' => 'Disahkan', 'publish' => 'Dipublikasikan', 'appeal' => 'Keberatan diajukan', 'review' => 'Keberatan ditinjau', 'accept' => 'Keberatan diterima', 'reject' => 'Keberatan ditolak'][$e->action] }} · Versi {{ $e->version }}</p><p class="text-sm">{{ $e->name }} · {{ \Carbon\Carbon::parse($e->created_at)->format('d-m-Y H:i') }}</p><p class="whitespace-pre-wrap break-words text-sm">{{ $e->note }}</p>@if($e->approval)@php($approval = json_decode($e->approval))<p class="break-words text-sm">{{ $approval->document_number }} · {{ $approval->name }} · {{ $approval->position }} ({{ $approval->role }})</p><p class="break-all text-xs text-slate-500">{{ $approval->sha256 }}</p>@endif</li>@endforeach</ol>
</x-layouts.app>
