<x-layouts.app title="Presensi">
    @php
        $user = auth()->user();
        $today = now()->toDateString();
        $open = in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']);
        $sealed = $service->sealed($p);
        $owner = $access->owner($user, $p);
        $admin = $access->admin($user);
        $chief = $access->chief($user, $p->department_id);
        $due = array_values(array_filter($snapshot['missing'], fn ($d) => $d <= $today));
        $waiting = $rows->filter(fn ($r) => in_array($r->status, ['waiting', 'corrected']) && $access->mentor($user, $r));
        $defaults = fn (string $date) => ($schedule[$date] ?? collect())->first();
    @endphp
    <x-placement-header :p="$p" active="attendance" />

    <div class="mb-6 grid gap-3 sm:grid-cols-3"><div class="card p-4"><p class="text-sm text-slate-600">Hari kegiatan terjadwal</p><strong class="block text-2xl">{{ count($days) }}</strong></div><div class="card p-4"><p class="text-sm text-slate-600">Sudah terverifikasi</p><strong class="block text-2xl">{{ count($snapshot['rows']) - $snapshot['pending'] }}</strong></div><div class="card p-4"><p class="text-sm text-slate-600">Belum diisi (sampai hari ini)</p><strong class="block text-2xl">{{ count($due) }}</strong></div></div>

    @if($sealed)<div class="card mb-6 border-emerald-300 font-semibold text-emerald-800">Rekap disahkan dan dikunci. Koreksi hanya melalui Admin Kordik dan memerlukan verifikasi serta pengesahan ulang.</div>@endif

    @if($open && $owner && ! $sealed)
        @if(! count($days))
            <p class="card mb-6">Presensi dapat diisi setelah jadwal Anda disetujui pembimbing.</p>
        @elseif($due)
            <section class="card mb-6 border-amber-300"><h2 class="text-lg font-bold">Isi presensi</h2><p class="mb-3 text-sm text-slate-600">Tekan <strong>Hadir</strong> bila Anda mengikuti kegiatan sesuai jadwal. Untuk izin, sakit, terlambat, atau kegiatan berbeda, pilih "Lainnya".</p>
                <ul class="divide-y divide-slate-100">
                    @foreach(array_reverse($due) as $date)
                        @php $plan = $defaults($date); @endphp
                        <li class="py-3"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-semibold">{{ $date === $today ? 'Hari ini' : \App\Support\Ui::date($date) }}</p><p class="text-sm text-slate-500">{{ $plan?->activity }}</p></div>
                            <form method="POST" action="{{ route('attendance.quick', $p->ulid) }}">@csrf<input type="hidden" name="date" value="{{ $date }}"><button class="btn-primary">Hadir</button></form></div>
                            <details class="mt-2"><summary class="cursor-pointer text-sm text-brand-700">Lainnya (izin, sakit, terlambat, tidak hadir)…</summary><form class="mt-3 grid gap-4 md:grid-cols-2" method="POST" action="{{ route('attendance.save', $p->ulid) }}">@csrf @include('attendance.fields', ['row' => null, 'date' => $date, 'plan' => $plan])<div class="md:col-span-2"><button class="btn-primary" name="action" value="submit">Kirim ke pembimbing</button></div></form></details>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <p class="card mb-6 text-slate-600">Semua hari kegiatan sampai hari ini sudah diisi.</p>
        @endif
    @endif

    @if($open && ! $sealed && $waiting->isNotEmpty() && ! $owner)
        <section class="card mb-6 border-amber-300"><h2 class="text-lg font-bold">Menunggu verifikasi Anda ({{ $waiting->count() }})</h2>
            <form method="POST" action="{{ route('attendance.verify-many', $p->ulid) }}">@csrf
                <ul class="mt-3 divide-y divide-slate-100">@foreach($waiting->sortBy('date') as $row)<li class="py-3"><label class="flex items-start gap-3 font-normal"><input class="mt-1" type="checkbox" name="ids[]" value="{{ $row->ulid }}" checked><span class="min-w-0"><span class="block font-semibold">{{ \App\Support\Ui::date($row->date) }} · {{ \App\Support\Ui::label('presence', $row->attendance_status) }}</span><span class="block whitespace-pre-wrap break-words text-sm text-slate-600">{{ $row->activity }}</span>@if($row->notes)<span class="block whitespace-pre-wrap break-words text-sm text-slate-500">{{ $row->notes }}</span>@endif</span></label></li>@endforeach</ul>
                <button class="btn-primary mt-3">Verifikasi yang dicentang</button>
            </form>
            <p class="mt-3 text-sm text-slate-500">Untuk menolak satu hari, buka barisnya di daftar bawah dan tulis alasannya.</p>
        </section>
    @endif

    <h2 class="mb-3 text-lg font-bold">Daftar presensi</h2>
    <div class="space-y-3">@forelse($rows as $row)<article class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-bold">{{ \App\Support\Ui::date($row->date) }} · {{ \App\Support\Ui::label('presence', $row->attendance_status) }}</h3><x-badge kind="attendance" :value="$row->status" /></div>
        <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $row->activity }}</p>@if($row->notes)<p class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-600">{{ $row->notes }}</p>@endif
        <p class="mt-2 text-xs text-slate-500">{{ $names['locations'][$row->clinical_location_id] ?? '' }} · Verifikator: {{ $names['mentors'][$row->mentor_assignment_id] ?? '—' }}@if($row->verified_at) · Diputuskan {{ \App\Support\Ui::dateTime($row->verified_at) }}@endif</p>
        @if($row->decision_note)<p class="mt-2 text-sm {{ $row->status === 'rejected' ? 'text-red-800' : '' }}">Catatan pembimbing: {{ $row->decision_note }}</p>@endif
        @if($open && ! $sealed && in_array($row->status, ['waiting', 'corrected']) && $access->mentor($user, $row) && ! $owner)
            <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tolak hari ini…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('attendance.decide', $row->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $row->revision }}"><input type="hidden" name="action" value="reject"><label class="block">Alasan penolakan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-danger">Tolak presensi</button></form></details>
        @endif
        @if($open && (($owner && ! $sealed && ! $row->admin_only && in_array($row->status, ['draft', 'rejected'])) || $admin))
            <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ $admin ? 'Koreksi oleh Admin…' : 'Perbaiki dan kirim ulang' }}</summary><form class="mt-3 grid gap-4 md:grid-cols-2" method="POST" action="{{ route('attendance.save', $p->ulid) }}">@csrf @include('attendance.fields', ['row' => $row, 'date' => $row->date, 'plan' => null])
                @if($admin)<label class="md:col-span-2">Alasan koreksi (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><div class="md:col-span-2"><button class="btn-primary" name="action" value="correct">Koreksi dan minta verifikasi ulang</button></div>@else<div class="md:col-span-2"><button class="btn-primary" name="action" value="submit">Kirim ulang ke pembimbing</button></div>@endif</form></details>
        @endif
        @if($open && ! $sealed && $admin && in_array($row->status, ['waiting', 'corrected', 'rejected']))<details class="mt-3"><summary class="cursor-pointer text-sm text-slate-600">Ganti verifikator…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('attendance.replace', $row->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $row->revision }}"><label class="block">Pembimbing pengganti<select name="mentor_assignment_id" required>@foreach($assignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->name }} ({{ \App\Support\Ui::period($assignment->start_date, $assignment->end_date) }})</option>@endforeach</select></label><label class="block">Alasan (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><button class="btn-secondary">Simpan pengganti</button></form></details>@endif
    </article>@empty<p class="card text-slate-500">Belum ada presensi.</p>@endforelse</div>

    <section class="card mt-6"><h2 class="text-lg font-bold">Rekap akhir</h2><p class="my-3 text-sm text-slate-600">Dibuat setelah hari terakhir stase, saat semua hari kegiatan sudah diisi dan diverifikasi. Ketua KSM lalu mengesahkannya.</p>
        @if($open && ! $sealed && $p->end_date < $today && ($access->manage($user, $p->department_id) || $chief))
            @if(count($snapshot['missing']) || $snapshot['pending'])<p class="mb-3 text-sm text-amber-900">Masih ada {{ count($snapshot['missing']) }} hari belum diisi dan {{ $snapshot['pending'] }} hari belum terverifikasi. Rekap bisa dibuat, tetapi belum bisa disahkan.</p>@endif
            <form method="POST" action="{{ route('attendance.summary', $p->ulid) }}">@csrf<input type="hidden" name="version" value="{{ $summaries->first()->version ?? 0 }}"><button class="btn-secondary" name="action" value="generate">{{ $summaries->isEmpty() ? 'Buat rekap' : 'Buat ulang rekap dari data terbaru' }}</button></form>
        @endif
        @foreach($summaries as $summary)<div class="mt-4 border-t pt-4"><p class="font-semibold">Versi {{ $summary->version }} <x-badge class="ml-1" kind="summary" :value="$summary->status" /></p><div class="mt-2 flex flex-wrap gap-3"><a class="btn-secondary" href="{{ route('attendance.report', $summary->ulid) }}">Lihat / cetak</a>@if($open && $summary->status === 'draft' && $chief && ! $owner)<form method="POST" action="{{ route('attendance.summary', $p->ulid) }}" onsubmit="return confirm('Sahkan rekap ini? Setelah disahkan, presensi dikunci.')">@csrf<input type="hidden" name="version" value="{{ $summary->version }}"><button class="btn-primary" name="action" value="approve">Sahkan & kunci</button></form>@endif</div></div>@endforeach
    </section>

    <details class="card mt-6"><summary class="cursor-pointer font-semibold">Riwayat perubahan</summary>@foreach($histories as $h)<div class="mt-3 border-t pt-3 text-sm"><p class="font-semibold">{{ \App\Support\Ui::event($h->event) }}</p><p class="text-xs text-slate-500">{{ $h->actor_name ?? 'Sistem' }} · {{ \App\Support\Ui::dateTime($h->created_at) }}</p>@if($h->reason)<p class="break-words">{{ $h->reason }}</p>@endif</div>@endforeach<div class="mt-4">{{ $histories->links() }}</div></details>
</x-layouts.app>
