<x-layouts.app title="Dokumen penempatan">
    <x-placement-header :p="$p" active="documents" />
    @php
        $u = auth()->user();
        $isAdmin = $access->admin($u);
        $isAdminKordik = $access->role($u, ['admin-kordik']);
        $isKordik = $access->role($u, ['tim-kordik']);
        $isChief = $access->role($u, ['ketua-ksm']) && in_array((int) $p->department_id, $u->departmentScopeIds());
        $reviewable = in_array($p->status, ['menunggu_dokumen', 'terverifikasi', 'dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']);
        $canUpload = $p->status !== 'selesai' && $isAdminKordik;
        $ownUpload = in_array($p->status, \App\Services\ParticipantService::ACTIVATABLE) && app(\App\Services\SchedulingAccess::class)->owner($u, $p);
        // [action => label] the current user may take now; "soft" ones need a written reason.
        $go = [];
        $stop = [];
        if ($isAdmin && $p->status === 'draft') $go['submit'] = 'Ajukan ke KSM';
        if ($isAdminKordik && $p->status === 'menunggu_dokumen') $go['verify'] = 'Semua dokumen lengkap — lanjutkan';
        if ($isChief && $p->status === 'menunggu_konfirmasi_ksm') { $go['ksm_accept'] = 'Terima peserta'; $stop['ksm_reject'] = 'Tolak'; }
        if ($isKordik && $p->status === 'menunggu_persetujuan_kordik') { $go['kordik_accept'] = 'Setujui penerimaan'; $stop['kordik_reject'] = 'Tolak penerimaan'; }
        if ($isAdmin && in_array($p->status, ['ditolak_ksm', 'ditolak_kordik', 'dibatalkan'])) $stop['revise'] = 'Kembalikan ke draf untuk direvisi';
        if ($isAdmin && in_array($p->status, ['draft', 'menunggu_konfirmasi_ksm', 'menunggu_persetujuan_kordik', 'menunggu_dokumen', 'terverifikasi', 'dijadwalkan'])) $stop['cancel'] = 'Batalkan penempatan';
        if ($isKordik && in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian'])) $stop['cancel'] = 'Batalkan penempatan yang sudah berjalan';
        $rejection = in_array($p->status, ['ditolak_ksm', 'ditolak_kordik', 'dibatalkan']) ? $histories->first(fn ($h) => in_array($h->action, ['ksm_reject', 'kordik_reject', 'cancel'])) : null;
    @endphp

    @if($rejection)<div class="card mb-5 border-red-300"><h2 class="font-bold text-red-800">{{ \App\Support\Ui::event($rejection->action) }}</h2><p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $rejection->reason }}</p><p class="mt-1 text-xs text-slate-500">{{ $rejection->actor_name }} · {{ \App\Support\Ui::dateTime($rejection->created_at) }}</p></div>@endif

    @if($conflicts->isNotEmpty())<div class="card mb-5 border-amber-300"><h2 class="font-bold">Peserta ini punya penempatan lain pada periode yang sama</h2>@foreach($conflicts as $c)<p class="mt-2 text-sm"><a class="text-brand-700 underline" href="{{ route('admissions.show', $c->ulid) }}">{{ json_decode($c->snapshot, true)['department'] }}</a> · {{ \App\Support\Ui::period($c->start_date, $c->actual_end_date ?? $c->end_date) }}</p>@endforeach<p class="mt-3 text-sm">Benturan di KSM yang sama diselesaikan dengan mengubah penempatan lama. Benturan lintas KSM memerlukan pengecualian Tim Kordik (di bagian bawah halaman).</p></div>@endif

    @if($isAdmin && ! $participant->user_id && in_array($p->status, \App\Services\ParticipantService::ACTIVATABLE))
        <section class="card mb-5 border-amber-300"><h2 class="font-bold">Peserta belum punya akun</h2><p class="mb-3 mt-1 text-sm text-slate-600">Aktifkan agar peserta bisa masuk, mengunggah dokumennya sendiri, dan mengisi kegiatan stase.</p>
            <form class="flex flex-wrap items-end gap-3" method="POST" action="{{ route('admissions.activate', $participant->ulid) }}">@csrf<label class="min-w-56 flex-1">Email peserta<input name="email" type="email" value="{{ old('email', $participant->email) }}" required></label><label class="flex items-center gap-2 font-normal"><input type="checkbox" name="ownership_confirmed" value="1" required>Email ini benar milik peserta</label><button class="btn-primary">Aktifkan akun</button></form>
        </section>
    @endif

    @if($go || $stop)
        <section class="card mb-5"><h2 class="mb-3 font-bold">Tindakan Anda</h2>
            <div class="flex flex-wrap gap-3">
                @foreach($go as $action => $label)
                    <form method="POST" action="{{ route('admissions.transition', $p->ulid) }}">@csrf<input type="hidden" name="expected_status" value="{{ $p->status }}"><input type="hidden" name="revision" value="{{ $p->revision }}"><input type="hidden" name="action" value="{{ $action }}"><button class="btn-primary">{{ $label }}</button></form>
                @endforeach
            </div>
            @foreach($stop as $action => $label)
                <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-red-700">{{ $label }}…</summary>
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('admissions.transition', $p->ulid) }}">@csrf<input type="hidden" name="expected_status" value="{{ $p->status }}"><input type="hidden" name="revision" value="{{ $p->revision }}"><input type="hidden" name="action" value="{{ $action }}">
                        <label class="block">Alasan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">{{ $label }}</button></form>
                </details>
            @endforeach
        </section>
    @endif

    <section class="card"><h2 class="mb-1 text-lg font-bold">Dokumen persyaratan</h2><p class="mb-2 text-sm text-slate-500">Setiap berkas diperiksa keamanannya dulu. Setelah berstatus Aman, Admin Kordik menyatakan valid.</p>
        @foreach($documents as $doc)
            @php
                $category = in_array($doc->code, ['surat', 'ijazah', 'bhd', 'sip', 'str', 'kompetensi']) ? $doc->code : 'administrasi';
                $docFiles = $files->where('category', $category)->where('resource_type', $doc->code === 'surat' ? 'letter' : 'placement');
                $clean = $docFiles->firstWhere('scan_status', 'clean');
            @endphp
            <div class="border-t py-4">
                <div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold">{{ $doc->label }}</p><x-badge kind="document" :value="$doc->status" /></div>
                @if($doc->valid_until)<p class="text-xs text-slate-500">Berlaku sampai {{ \App\Support\Ui::date($doc->valid_until) }}</p>@endif
                @if($staff && $doc->reason && $doc->status !== 'pending')<p class="mt-1 text-sm text-slate-600">Catatan: {{ $doc->reason }}</p>@endif
                @forelse($docFiles as $file)
                    <p class="mt-2 text-sm">Versi {{ $file->version }} · {{ $file->original_name }} · <x-badge kind="scan" :value="$file->scan_status" /> @if($file->scan_status === 'clean')<a class="ml-1 text-brand-700 underline" href="{{ route('admissions.download', $file->ulid) }}">Unduh</a>@endif</p>
                @empty
                    <p class="mt-2 text-sm text-slate-500">Belum ada berkas.</p>
                @endforelse
                @if(($canUpload && $letter) || ($ownUpload && $doc->code !== 'surat'))
                    <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-brand-700">Unggah berkas{{ $docFiles->isNotEmpty() ? ' pengganti' : '' }}</summary>
                        <form class="mt-3 space-y-3" method="POST" enctype="multipart/form-data" action="{{ $doc->code === 'surat' ? route('admissions.upload', ['letter', $letter->ulid]) : route('admissions.upload', ['placement', $p->ulid]) }}">@csrf<input type="hidden" name="category" value="{{ $category }}">
                            <label class="block">{{ $doc->code === 'surat' ? 'PDF' : 'PDF, JPG, atau PNG' }}, maksimal 10 MB<input type="file" name="file" accept="{{ $doc->code === 'surat' ? '.pdf' : '.pdf,.jpg,.jpeg,.png' }}" required></label>
                            <label class="flex items-start gap-2 text-sm font-normal"><input type="checkbox" name="deidentified" value="1" required><span>Berkas tidak memuat identitas pasien.</span></label><button class="btn-secondary">Unggah</button></form>
                    </details>
                @endif
                @if($reviewable && $isAdminKordik && $doc->status !== 'valid')
                    @if($clean)
                        <form class="mt-3 flex flex-wrap items-end gap-3" method="POST" action="{{ route('admissions.review', $p->ulid) }}">@csrf<input type="hidden" name="code" value="{{ $doc->code }}"><input type="hidden" name="status" value="valid"><input type="hidden" name="file_ulid" value="{{ $clean->ulid }}">
                            <label>Berlaku sampai (kosongkan bila tidak ada)<input type="date" name="valid_until"></label><button class="btn-primary">Nyatakan valid (versi {{ $clean->version }})</button></form>
                    @endif
                    @if($doc->status !== 'rejected' && $docFiles->isNotEmpty())
                        <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-red-700">Minta perbaikan…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('admissions.review', $p->ulid) }}">@csrf<input type="hidden" name="code" value="{{ $doc->code }}"><input type="hidden" name="status" value="rejected"><label class="block">Apa yang harus diperbaiki (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">Tolak dokumen</button></form></details>
                    @endif
                @endif
                @if($reviewable && $isKordik && ! in_array($doc->status, ['valid', 'exception']))
                    <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-brand-700">Kecualikan persyaratan ini…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('admissions.review', $p->ulid) }}">@csrf<input type="hidden" name="code" value="{{ $doc->code }}"><input type="hidden" name="status" value="exception"><label class="block">Alasan pengecualian (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-secondary">Setujui pengecualian</button></form></details>
                @endif
            </div>
        @endforeach
    </section>

    @php($supporting = $files->where('category', 'pendukung'))
    @if($isAdmin && ($supporting->isNotEmpty() || $canUpload))
        <details class="card mt-5"><summary class="cursor-pointer font-bold">Berkas pendukung lain ({{ $supporting->count() }})</summary>
            @foreach($supporting as $file)<p class="mt-2 text-sm">Versi {{ $file->version }} · {{ $file->original_name }} · <x-badge kind="scan" :value="$file->scan_status" /> @if($file->scan_status === 'clean')<a class="ml-1 text-brand-700 underline" href="{{ route('admissions.download', $file->ulid) }}">Unduh</a>@endif</p>@endforeach
            @if($canUpload)<div class="mt-3">@include('admissions.upload', ['resource' => 'placement', 'resourceUlid' => $p->ulid, 'categories' => ['pendukung' => 'Berkas pendukung']])</div>@endif
        </details>
    @endif

    @if($isAdmin && in_array($p->status, ['draft', 'menunggu_konfirmasi_ksm', 'menunggu_persetujuan_kordik', 'menunggu_dokumen', 'terverifikasi']))
        <details class="card mt-5"><summary class="cursor-pointer font-bold">Ubah periode atau KSM</summary><p class="my-3 text-sm">Perubahan mengembalikan penempatan ke draf dan persetujuan diulang dari awal.</p>
            <form class="grid gap-3 md:grid-cols-2" method="POST" action="{{ route('admissions.period', $p->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $p->revision }}"><label>Mulai<input type="date" name="start_date" value="{{ $p->start_date }}" required></label><label>Selesai<input type="date" name="end_date" value="{{ $p->end_date }}" required></label><label>KSM<select name="department_id">@foreach($departments as $d)<option value="{{ $d->id }}" @selected($p->department_id == $d->id)>{{ $d->name }}</option>@endforeach</select></label><label>Alasan (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><div><button class="btn-secondary">Simpan perubahan</button></div></form>
        </details>
    @endif

    @if($isAdmin && $p->status === 'draft' && $conflicts->where('department_id', '!=', $p->department_id)->isNotEmpty())
        <details class="card mt-5"><summary class="cursor-pointer font-bold">Minta pengecualian periode paralel lintas KSM</summary>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('admissions.exception', $p->ulid) }}">@csrf<label class="block">Penempatan yang berbenturan<select name="conflict_ulid">@foreach($conflicts->where('department_id', '!=', $p->department_id) as $c)<option value="{{ $c->ulid }}">{{ json_decode($c->snapshot, true)['department'] }} · {{ \App\Support\Ui::period($c->start_date, $c->end_date) }}</option>@endforeach</select></label><label class="block">Berkas pendukung (sudah berstatus Aman)<select name="file_ulid" required><option value="">Pilih berkas</option>@foreach($supporting->where('scan_status', 'clean') as $f)<option value="{{ $f->ulid }}">{{ $f->original_name }} · versi {{ $f->version }}</option>@endforeach</select></label><label class="block">Alasan program paralel<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-secondary">Kirim permohonan</button></form>
        </details>
    @endif

    @if($exceptions->isNotEmpty())
        <section class="card mt-5"><h2 class="font-bold">Pengecualian periode paralel</h2>
            @foreach($exceptions as $e)<div class="mt-3 border-t pt-3"><p class="text-sm">{{ $e->reason }} <x-badge class="ml-1" kind="request" :value="$e->status" /></p>
                @if($e->status === 'pending' && $isKordik)<div class="mt-3 flex flex-wrap gap-3"><form method="POST" action="{{ route('admissions.exception-decide', $e->ulid) }}">@csrf<input type="hidden" name="approved" value="1"><input type="hidden" name="reason" value="Disetujui Tim Kordik"><button class="btn-primary">Setujui</button></form></div>
                    <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tolak…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('admissions.exception-decide', $e->ulid) }}">@csrf<input type="hidden" name="approved" value="0"><label class="block">Alasan<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">Tolak pengecualian</button></form></details>@endif
            </div>@endforeach
        </section>
    @endif

    @if($staff)
        <details class="card mt-5"><summary class="cursor-pointer font-bold">Riwayat penempatan ({{ $histories->count() }})</summary>
            <ol class="mt-3 space-y-3">@foreach($histories as $h)<li class="border-l-2 border-brand-100 pl-4 text-sm"><p class="font-semibold">{{ \App\Support\Ui::event($h->action) }}</p><p class="text-xs text-slate-500">{{ $h->actor_name ?? 'Sistem' }} · {{ \App\Support\Ui::dateTime($h->created_at) }}</p>@if($h->reason)<p class="mt-1 whitespace-pre-wrap break-words">{{ $h->reason }}</p>@endif</li>@endforeach</ol>
        </details>
    @endif
</x-layouts.app>
