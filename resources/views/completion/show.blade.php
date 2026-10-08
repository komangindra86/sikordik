<x-layouts.app title="Survei & penyelesaian">
    <x-placement-header :p="$p" active="completion" />
    @php
        $u = auth()->user();
        $isAdminKordik = $access->role($u, ['admin-kordik']);
        $isKordik = $access->role($u, ['tim-kordik']);
        $running = in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian']) && ! $p->archived_at;
        $links = ['documents' => route('admissions.show', $p->ulid), 'attendance' => route('attendance.show', $p->ulid), 'logbooks' => route('logbooks.placement', $p->ulid), 'grades' => route('assessments.placement', $p->ulid), 'appeals' => route('assessments.placement', $p->ulid), 'obligations' => route('scheduling.show', $p->ulid)];
        $hidden = function (string $action) use ($p, $pending) {
            return '<input type="hidden" name="_token" value="'.csrf_token().'"><input type="hidden" name="revision" value="'.$p->revision.'"><input type="hidden" name="confirm" value="1"><input type="hidden" name="action" value="'.$action.'">'
                .($pending ? '<input type="hidden" name="request_id" value="'.$pending->id.'"><input type="hidden" name="request_revision" value="'.$pending->revision.'">' : '');
        };
    @endphp
    @if($p->status === 'selesai')<p class="card mb-5">Penempatan dikunci. Data yang diizinkan tetap dapat dibaca dan diunduh melalui tab di atas. {{ $p->archived_at ? 'Arsip tidak menghapus data.' : '' }}</p>@endif

    <section class="card"><h2 class="mb-1 text-lg font-bold">Kelengkapan untuk menutup stase</h2><p class="mb-3 text-sm text-slate-500">Semua baris harus Lengkap sebelum Admin Kordik dapat mengajukan penyelesaian.</p>
        <ul class="space-y-3">@foreach($checklist['checks'] as $key => $c)<li class="flex items-start gap-3"><x-badge class="shrink-0" :tone="$c['ok'] ? 'ok' : 'wait'" :label="$c['ok'] ? 'Lengkap' : 'Belum'" /><span class="text-sm">{{ $c['label'] }} @if(! $c['ok'] && isset($links[$key]))<a class="ml-1 whitespace-nowrap font-semibold text-brand-700" href="{{ $links[$key] }}">Buka →</a>@endif</span></li>@endforeach</ul>
    </section>

    <section class="mt-5"><h2 class="mb-1 text-lg font-bold">Dua survei wajib</h2><p class="mb-4 text-sm text-slate-600">Survei diisi di Google Form memakai kode dari sini. Jangan menulis nama pasien, NIK, nomor rekam medis, atau diagnosis.</p>
        <div class="grid gap-4 lg:grid-cols-2">
        @foreach(\App\Services\SurveyService::KINDS as $kind => $label)
            @php $r = $surveys->get($kind); @endphp
            <article class="card min-w-0"><div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-semibold">{{ $label }}</h3><x-badge kind="survey" :value="$r->status ?? ''" /></div>
                @if($r && ($owner || $access->role($u, ['admin-kordik', 'tim-kordik'])))
                    <p class="mt-3 text-sm text-slate-600">Kode respons:</p><p class="mt-1 select-all break-all rounded bg-slate-100 p-3 font-mono text-sm">{{ $r->token }}</p>
                    <a class="btn-secondary mt-3" href="{{ $r->url }}" target="_blank" rel="noopener noreferrer">Buka Google Form ↗</a>
                @endif
                @if($running)
                    @if($owner && ! $r)
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('completion.survey', $p->ulid) }}">@csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="revision" value="0"><input type="hidden" name="action" value="start">
                            <p class="text-sm"><strong>Langkah 1 dari 3.</strong> Ambil kode respons.</p><label class="flex items-start gap-2 text-sm font-normal"><input type="checkbox" name="confirm" value="1" required><span>Saya tidak akan menulis identitas atau data medis pasien di survei.</span></label><button class="btn-primary">Ambil kode</button></form>
                    @elseif($owner && in_array($r->status, ['issued', 'rejected']))
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('completion.survey', $p->ulid) }}">@csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="action" value="submit"><input type="hidden" name="confirm" value="1">
                            <p class="text-sm"><strong>Langkah 2.</strong> Salin kode di atas, buka Google Form, tempel kodenya, lalu kirim. <strong>Langkah 3.</strong> Kembali ke sini dan tekan tombol ini.</p>@if($r->status === 'rejected')<p class="text-sm text-red-800">Admin belum menemukan respons dengan kode ini. Kirim ulang formulir dengan kode yang sama.</p>@endif<button class="btn-primary">Saya sudah mengirim formulir</button></form>
                    @elseif($owner && $r->status === 'submitted')
                        <p class="mt-4 text-sm text-slate-600">Menunggu Admin Kordik mencocokkan kode dengan respons yang masuk.</p>
                    @endif
                    @if(! $owner && $isAdminKordik && $r?->status === 'submitted')
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('completion.survey', $p->ulid) }}">@csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="revision" value="{{ $r->revision }}"><input type="hidden" name="confirm" value="1">
                            <p class="text-sm">Cari kode di atas pada lembar respons Google Form, lalu pilih hasilnya.</p><div class="flex flex-wrap gap-3"><button class="btn-primary" name="action" value="verify">Ditemukan & lengkap</button><button class="btn-danger" name="action" value="reject">Belum ditemukan</button></div></form>
                    @endif
                @endif
            </article>
        @endforeach
        </div>
    </section>

    @if(! $owner && ! $p->archived_at && ($isAdminKordik || $isKordik))
        @php
            $canSubmit = $isAdminKordik && ! $pending && $running;
            $canDecide = $isKordik && $pending?->status === 'pending' && $pending->requested_by != $u->id;
            $canWithdraw = $isAdminKordik && $pending;
            $canExecute = $isAdminKordik && $pending?->kind === 'reopen' && $pending->status === 'approved' && $pending->decided_by != $u->id;
            $canReopen = $isAdminKordik && ! $pending && $p->status === 'selesai';
            $canArchive = $canReopen && $p->completed_at && \Carbon\Carbon::parse(max($p->completed_at, $p->actual_end_date ?? $p->end_date))->addYears(3)->lte(now());
        @endphp
        @if($canSubmit || $canDecide || $canWithdraw || $canExecute || $canReopen)
        <section class="card mt-5"><h2 class="mb-3 text-lg font-bold">Tindakan Anda</h2>
            @if($pending)<p class="mb-3 text-sm">Permohonan {{ $pending->kind === 'completion' ? 'penyelesaian' : 'pembukaan kembali' }}: <x-badge kind="request" :value="$pending->status" /> · {{ $pending->reason }}</p>@endif
            @if($canSubmit)
                @if($checklist['ready'])<form method="POST" action="{{ route('completion.transition', $p->ulid) }}" onsubmit="return confirm('Ajukan penyelesaian stase ini kepada Tim Kordik?')">{!! $hidden('submit') !!}<button class="btn-primary">Ajukan penyelesaian ke Tim Kordik</button></form>
                @else<p class="text-sm text-slate-600">Penyelesaian dapat diajukan setelah semua baris kelengkapan berstatus Lengkap.</p>@endif
            @endif
            @if($canDecide)
                <form method="POST" action="{{ route('completion.transition', $p->ulid) }}" onsubmit="return confirm('Setujui permohonan ini? Keputusan tercatat atas nama akun Anda.')">{!! $hidden('approve') !!}<button class="btn-primary">Setujui {{ $pending->kind === 'completion' ? 'penyelesaian' : 'pembukaan kembali' }}</button></form>
                <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tolak…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('completion.transition', $p->ulid) }}">{!! $hidden('reject') !!}<label class="block">Alasan penolakan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">Tolak permohonan</button></form></details>
            @endif
            @if($canExecute)
                <form class="mt-3 space-y-3" method="POST" action="{{ route('completion.transition', $p->ulid) }}">{!! $hidden('reopen_execute') !!}<label class="block">Catatan pelaksanaan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-primary">Laksanakan pembukaan kembali</button></form>
            @endif
            @if($canWithdraw)
                <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tarik permohonan untuk diperiksa ulang…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('completion.transition', $p->ulid) }}">{!! $hidden('withdraw') !!}<label class="block">Alasan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">Tarik permohonan</button></form></details>
            @endif
            @if($canReopen)
                <details><summary class="cursor-pointer text-sm font-semibold text-brand-700">Ajukan pembukaan kembali…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('completion.transition', $p->ulid) }}">{!! $hidden('reopen_request') !!}<label class="block">Data apa yang perlu diperbaiki dan mengapa (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-secondary">Ajukan ke Tim Kordik</button></form></details>
                @if($canArchive)<details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-brand-700">Arsipkan (retensi tiga tahun terpenuhi)…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('completion.transition', $p->ulid) }}">{!! $hidden('archive') !!}<label class="block">Alasan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-secondary">Arsipkan</button></form></details>@endif
            @endif
        </section>
        @endif
    @endif

    <details class="card mt-5"><summary class="cursor-pointer text-lg font-bold">Riwayat permohonan dan pengesahan</summary>
        <div class="mt-3 space-y-4">@forelse($requests as $r)<article class="min-w-0 border-t pt-3"><p class="font-semibold">{{ $r->kind === 'completion' ? 'Penyelesaian' : 'Pembukaan kembali' }} <x-badge class="ml-1" kind="request" :value="$r->status" /></p><p class="mt-2 text-sm">{{ \App\Support\Ui::dateTime($r->created_at) }} · {{ $r->reason }}</p>@if($r->decision_reason)<p class="mt-2 text-sm">Keputusan: {{ $r->decision_reason }}</p>@endif @if($r->approval)@php($seal = json_decode($r->approval))<p class="mt-2 break-all text-sm">{{ $seal->number }} · {{ $seal->name }} · {{ $seal->job_title }} · {{ \App\Support\Ui::dateTime($seal->at) }}</p><p class="mt-2 break-all font-mono text-xs">SHA-256: {{ $seal->sha256 }}</p>@endif</article>@empty<p class="text-sm">Belum ada permohonan.</p>@endforelse
            @foreach($history as $h)<p class="border-t pt-3 text-sm">{{ \App\Support\Ui::dateTime($h->created_at) }} · {{ \App\Support\Ui::event($h->action) }} · {{ $h->reason }}</p>@endforeach
        </div>
    </details>
</x-layouts.app>
