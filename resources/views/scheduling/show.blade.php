<x-layouts.app title="Pembimbing & jadwal">
    @php
        $u = auth()->user();
        $manage = $a->manage($u, $p->department_id);
        $owner = $a->owner($u, $p);
        $edit = $manage || $owner;
        $chief = $a->chief($u, $p->department_id);
        $open = in_array($p->status, \App\Services\ScheduleService::OPEN_PLACEMENTS);
        $roles = ['mentor' => 'Pembimbing', 'examiner' => 'Penguji', 'supervisor' => 'Supervisor'];
        $hasMentor = $assignments->where('role', 'mentor')->where('status', 'approved')->isNotEmpty();
        $all = \Illuminate\Support\Facades\DB::table('schedules')->where('placement_id', $p->id)->whereIn('status', ['draft', 'submitted'])->get();
        $drafts = $all->where('status', 'draft')->count();
        $mine = $all->where('status', 'submitted')->filter(fn ($s) => $a->mentor($u, $s))->count();
    @endphp
    <x-placement-header :p="$p" active="schedule" />

    <section class="card mb-6"><h2 class="text-lg font-bold">Pembimbing, penguji, dan supervisor</h2>
        <div class="mt-3 space-y-3">
            @forelse($assignments as $assignment)
                <div class="rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold">{{ $assignment->educator_name }} · {{ $roles[$assignment->role] }}</p><x-badge kind="assignment" :value="$assignment->status" /></div>
                    <p class="text-sm text-slate-600">{{ \App\Support\Ui::period($assignment->start_date, $assignment->end_date) }}</p>
                    @if($assignment->status === 'rejected' && $assignment->decision_reason)<p class="mt-1 text-sm text-red-800">Alasan penolakan: {{ $assignment->decision_reason }}</p>@endif
                    @if($assignment->status === 'pending' && $chief && $assignment->requested_by != $u->id)
                        <div class="mt-3 flex flex-wrap gap-3"><form method="POST" action="{{ route('scheduling.assignment-decide', $assignment->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $assignment->revision }}"><input type="hidden" name="action" value="approve"><button class="btn-primary">Setujui</button></form></div>
                        <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tolak…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.assignment-decide', $assignment->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $assignment->revision }}"><input type="hidden" name="action" value="reject"><label class="block">Alasan (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><button class="btn-danger">Tolak penugasan</button></form></details>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Belum ada pembimbing. {{ $manage ? 'Ajukan di bawah ini.' : 'Admin Kordik atau Sekretariat KSM yang mengajukan.' }}</p>
            @endforelse
        </div>
        @if($manage && $open)
            <details class="mt-4" @if($assignments->whereIn('status', ['pending', 'approved'])->isEmpty()) open @endif><summary class="cursor-pointer font-semibold text-brand-700">Ajukan {{ $assignments->isEmpty() ? 'pembimbing' : 'pendidik lain / pengganti' }}</summary>
                <form class="mt-3 grid gap-4 md:grid-cols-2" method="POST" action="{{ route('scheduling.assignment', $p->ulid) }}">@csrf
                    <label>Pendidik<select name="educator_id" required><option value="">Pilih pendidik</option>@foreach($educators as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
                    <label>Sebagai<select name="role">@foreach($roles as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label>Mulai<input type="date" name="start_date" value="{{ $p->start_date }}" min="{{ $p->start_date }}" max="{{ $p->end_date }}" required></label><label>Selesai<input type="date" name="end_date" value="{{ $p->end_date }}" min="{{ $p->start_date }}" max="{{ $p->end_date }}" required></label>
                    <details class="md:col-span-2"><summary class="cursor-pointer text-sm text-slate-600">Kelompok atau pergantian pendidik (opsional)</summary><div class="mt-3 grid gap-4 md:grid-cols-2">
                        <label>Kelompok<select name="clinical_group_id"><option value="">Individual</option>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></label>
                        <label>Menggantikan<select name="replaces_id"><option value="">Tidak menggantikan siapa pun</option>@foreach($assignments->where('status', 'approved') as $old)<option value="{{ $old->id }}">{{ $old->educator_name }} · {{ $roles[$old->role] }}</option>@endforeach</select></label>
                        <label class="md:col-span-2">Alasan (wajib bila menggantikan, minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000"></textarea></label></div></details>
                    <div class="md:col-span-2"><button class="btn-primary">Ajukan ke Ketua KSM</button></div>
                </form>
                @if($educators->isEmpty())<p class="mt-2 text-sm text-amber-900">Belum ada pendidik aktif pada KSM ini. Tambahkan di Pengaturan → Pembimbing / Penguji / Supervisor dan catat lisensinya.</p>@endif
            </details>
        @endif
    </section>

    <section class="mb-6">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">Jadwal kegiatan</h2>
            <div class="flex flex-wrap gap-2">
                @if($edit && $open && $hasMentor)<a class="btn-primary" href="{{ route('scheduling.range', $p->ulid) }}">Susun jadwal satu periode</a><a class="btn-secondary" href="{{ route('scheduling.create', $p->ulid) }}">Tambah satu jadwal</a>@endif
                @if($p->status === 'dijadwalkan' && $a->admin($u))<form method="POST" action="{{ route('scheduling.start', $p->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $p->revision }}"><button class="btn-secondary">Mulai stase sekarang</button></form>@endif
            </div>
        </div>
        @if($open && $edit && $drafts)<form class="card mb-4 flex flex-wrap items-center justify-between gap-3 border-amber-300" method="POST" action="{{ route('scheduling.bulk', $p->ulid) }}">@csrf<input type="hidden" name="action" value="submit"><p class="text-sm"><strong>{{ $drafts }} jadwal masih draf.</strong> Draf belum terlihat oleh pembimbing.</p><button class="btn-primary">Ajukan semua ke pembimbing</button></form>@endif
        @if($open && $mine)<form class="card mb-4 flex flex-wrap items-center justify-between gap-3 border-amber-300" method="POST" action="{{ route('scheduling.bulk', $p->ulid) }}">@csrf<input type="hidden" name="action" value="release"><p class="text-sm"><strong>{{ $mine }} jadwal menunggu persetujuan Anda.</strong> Setelah disetujui, jadwal langsung terbit.</p><button class="btn-primary">Setujui semua</button></form>@endif

        <div class="space-y-3">
        @forelse($schedules as $s)
            <article class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-semibold">{{ \App\Support\Ui::date($s->date) }} · {{ $s->start_time ? substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5) : 'Sepanjang hari' }}</h3><x-badge kind="schedule" :value="$s->status" /></div>
                <p class="mt-1 text-sm">{{ $s->activity }} @if($s->replaces_id)<span class="text-slate-500">({{ $s->change_kind === 'cancel' ? 'permohonan pembatalan' : 'pengganti jadwal sebelumnya' }})</span>@endif</p>
                <p class="text-sm text-slate-500">{{ $s->location_name }} · Pembimbing: {{ $assignments->firstWhere('id', $s->mentor_assignment_id)?->educator_name }}@if($s->examiner_assignment_id) · Penguji: {{ $assignments->firstWhere('id', $s->examiner_assignment_id)?->educator_name }}@endif @if($s->group_name)· {{ $s->group_name }}@endif</p>
                @if($s->notes)<p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $s->notes }}</p>@endif
                @if($s->reason && in_array($s->status, ['revision', 'cancelled']))<p class="mt-2 break-words text-sm text-red-800">Catatan: {{ $s->reason }}</p>@endif
                @if($open)
                    @php($isMentor = $a->mentor($u, $s))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if($edit && $s->status === 'draft')<form method="POST" action="{{ route('scheduling.transition', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><input type="hidden" name="action" value="submit"><button class="btn-primary">Ajukan</button></form>@endif
                        @if($isMentor && $s->status === 'submitted')<form method="POST" action="{{ route('scheduling.release', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><button class="btn-primary">Setujui</button></form>@endif
                        @if($edit && $s->status === 'approved')<form method="POST" action="{{ route('scheduling.transition', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><input type="hidden" name="action" value="publish"><button class="btn-primary">Terbitkan</button></form>@endif
                        @if($edit && in_array($s->status, ['draft', 'revision']))<a class="btn-secondary" href="{{ route('scheduling.edit', [$p->ulid, $s->ulid]) }}">Ubah</a>@endif
                        @if($edit && $s->status === 'published')<a class="btn-secondary" href="{{ route('scheduling.edit', [$p->ulid, $s->ulid]) }}">Ubah / batalkan</a>@endif
                        @if($isMentor && $s->status === 'published' && $s->date < now()->toDateString() && $s->change_kind !== 'cancel')<form method="POST" action="{{ route('scheduling.transition', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><input type="hidden" name="action" value="complete"><button class="btn-secondary">Tandai selesai</button></form>@endif
                    </div>
                    @if($isMentor && $s->status === 'submitted')<details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-red-700">Minta revisi…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.transition', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><input type="hidden" name="action" value="revise"><label class="block">Apa yang perlu diubah (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><button class="btn-danger">Minta revisi</button></form></details>@endif
                    @if($edit && in_array($s->status, ['draft', 'revision', 'submitted', 'approved']))<details class="mt-2"><summary class="cursor-pointer text-sm text-slate-600">Hapus dari rencana…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.transition', $s->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $s->revision }}"><input type="hidden" name="action" value="withdraw"><label class="block">Alasan (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><button class="btn-danger">Tarik jadwal ini</button></form></details>@endif
                @endif
            </article>
        @empty
            <div class="card text-slate-600">{{ $hasMentor ? 'Belum ada jadwal. Gunakan "Susun jadwal satu periode" untuk membuat semuanya sekaligus.' : 'Jadwal dapat disusun setelah pembimbing disetujui Ketua KSM.' }}</div>
        @endforelse
        </div>
        <div class="mt-4">{{ $schedules->links() }}</div>
    </section>

    @if($memberships->isNotEmpty() || ($manage && $open && $groups->isNotEmpty()))
    <details class="card mt-6"><summary class="cursor-pointer text-lg font-semibold">Kelompok</summary><div class="mt-4 space-y-3">
        @forelse($memberships as $m)<div class="rounded-xl border border-slate-200 p-4"><p>{{ $m->name }} · {{ \App\Support\Ui::period($m->start_date, $m->end_date) }}</p>
            @if($manage && $open)<form class="mt-3 flex flex-wrap items-end gap-3" method="POST" action="{{ route('scheduling.membership-end', $m->id) }}">@csrf<input type="hidden" name="expected_end_date" value="{{ $m->end_date }}"><label>Akhir keanggotaan<input type="date" name="end_date" required></label><label class="min-w-0 flex-1">Alasan pindah<input name="reason" required minlength="10" maxlength="2000"></label><button class="btn-secondary">Akhiri keanggotaan</button></form>@endif</div>
        @empty<p class="text-sm text-slate-500">Penempatan individual.</p>@endforelse
        @if($manage && $open && $groups->isNotEmpty())<form class="grid gap-4 md:grid-cols-2" method="POST" action="{{ route('scheduling.join', $p->ulid) }}">@csrf<label>Kelompok<select name="clinical_group_id" required>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></label><label>Mulai<input type="date" name="start_date" value="{{ $p->start_date }}" required></label><label>Selesai<input type="date" name="end_date" value="{{ $p->end_date }}" required></label><label>Alasan<input name="reason" required minlength="10" maxlength="2000"></label><div><button class="btn-secondary">Masukkan ke kelompok</button></div></form>@endif
    </div></details>
    @endif

    @if($extensions->isNotEmpty() || ($a->admin($u) && $open))
    <details class="card mt-6" @if($extensions->whereIn('status', ['pending_ksm', 'pending_kordik'])->isNotEmpty()) open @endif><summary class="cursor-pointer text-lg font-semibold">Perpanjangan stase</summary><div class="mt-4 space-y-3">
        @foreach($extensions as $e)
            @php($conflictList = json_decode($e->conflict_snapshot, true))
            <div class="rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p>Sampai {{ \App\Support\Ui::date($e->new_end_date) }}</p><x-badge kind="request" :value="$e->status" /></div><p class="text-sm text-slate-500">{{ $e->reason }}</p>
                @foreach($conflictList as $conflict)<p class="mt-2 text-sm">Berbenturan dengan {{ $conflict['department'] }} · {{ \App\Support\Ui::period($conflict['start_date'], $conflict['end_date']) }}</p>@endforeach
                @if($e->supporting_file_ulid && ($manage || $chief || $a->role($u, ['tim-kordik'])))<a class="text-sm text-brand-700 underline" href="{{ route('admissions.download', $e->supporting_file_ulid) }}">Unduh bukti pendukung</a>@endif
                @php($decider = $e->status === 'pending_ksm' ? $chief : ($e->status === 'pending_kordik' && $a->role($u, ['tim-kordik'])))
                @if($decider && $e->requested_by != $u->id)
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.extension-decide', $e->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $e->revision }}"><input type="hidden" name="action" value="approve">@if(count($conflictList))<label class="flex items-start gap-2 text-sm font-normal"><input type="checkbox" name="approve_overlap" value="1" required><span>Saya menyetujui periode paralel lintas KSM berdasarkan bukti pendukung; benturan jam tetap dilarang.</span></label>@endif<button class="btn-primary">Setujui perpanjangan</button></form>
                    <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-red-700">Tolak…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.extension-decide', $e->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $e->revision }}"><input type="hidden" name="action" value="reject"><label class="block">Alasan (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><button class="btn-danger">Tolak perpanjangan</button></form></details>
                @endif
                @if($a->admin($u) && in_array($e->status, ['pending_ksm', 'pending_kordik']))<details class="mt-2"><summary class="cursor-pointer text-sm text-slate-600">Tarik permohonan…</summary><form class="mt-3 space-y-3" method="POST" action="{{ route('scheduling.extension-decide', $e->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $e->revision }}"><input type="hidden" name="action" value="withdraw"><label class="block">Alasan (minimal 10 karakter)<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><button class="btn-danger">Tarik permohonan</button></form></details>@endif
            </div>
        @endforeach
        @if($a->admin($u) && $open)<form class="grid gap-4 md:grid-cols-2" method="POST" action="{{ route('scheduling.extension', $p->ulid) }}">@csrf<input type="hidden" name="revision" value="{{ $p->revision }}"><label>Tanggal akhir baru<input type="date" name="new_end_date" min="{{ $p->end_date }}" required></label><label>Bukti periode paralel (jika ada)<select name="supporting_file_id"><option value="">Tidak ada</option>@foreach($supportingFiles as $file)<option value="{{ $file->id }}">{{ $file->original_name }}</option>@endforeach</select></label><label class="md:col-span-2">Alasan (minimal 10 karakter)<input name="reason" required minlength="10" maxlength="2000"></label><div><button class="btn-secondary">Ajukan perpanjangan</button></div></form><p class="text-sm text-slate-500">Perpanjangan disetujui Ketua KSM lalu Tim Kordik. Masa berlaku dokumen dan penugasan pendidik tidak ikut diperpanjang otomatis.</p>@endif
    </div></details>
    @endif

    @include('scheduling.history')
</x-layouts.app>
