<x-layouts.app :title="$change ? 'Ajukan perubahan jadwal' : 'Draft jadwal'">
    <a class="text-sm text-brand-700" href="{{ route('scheduling.show', $p->ulid) }}">← Kembali ke penempatan</a>
    <h1 class="my-5 text-2xl font-bold">{{ $change ? 'Ajukan perubahan jadwal terbit' : 'Draft jadwal' }}</h1>
    <p class="mb-4 text-sm text-slate-500">Waktu WITA. Kosongkan kedua jam untuk kegiatan sepanjang hari. Jangan menulis identitas atau informasi medis pasien.</p>
    <form class="card grid max-w-4xl gap-4 p-5 md:grid-cols-2" method="POST" action="{{ $row && !$change ? route('scheduling.update', [$p->ulid, $row->ulid]) : route('scheduling.store', $p->ulid) }}">@csrf
        <input type="hidden" name="revision" value="{{ $change ? 0 : ($row->revision ?? 0) }}"><input type="hidden" name="replaces_id" value="{{ $change ? $row->id : ($row->replaces_id ?? '') }}">
        <label>Tanggal<input class="input" type="date" name="date" min="{{ $p->start_date }}" max="{{ $p->end_date }}" value="{{ old('date', $row->date ?? '') }}" required></label>
        <label>Jenis kegiatan<input class="input" name="activity" value="{{ old('activity', $row->activity ?? '') }}" required maxlength="150"></label>
        <label>Jam mulai<input class="input" type="time" name="start_time" value="{{ old('start_time', isset($row->start_time) ? substr($row->start_time, 0, 5) : '') }}"></label>
        <label>Jam selesai<input class="input" type="time" name="end_time" value="{{ old('end_time', isset($row->end_time) ? substr($row->end_time, 0, 5) : '') }}"></label>
        <label>Lokasi<select class="input" name="clinical_location_id" required><option value="">Pilih lokasi</option>@foreach($locations as $l)<option value="{{ $l->id }}" @selected(old('clinical_location_id', $row->clinical_location_id ?? '') == $l->id)>{{ $l->name }}</option>@endforeach</select></label>
        <label>Kelompok (opsional)<select class="input" name="clinical_group_id"><option value="">Individual</option>@foreach($groups as $g)<option value="{{ $g->id }}" @selected(old('clinical_group_id', $row->clinical_group_id ?? '') == $g->id)>{{ $g->name }}</option>@endforeach</select></label>
        @foreach(['mentor_assignment_id' => ['mentor', 'Pembimbing'], 'examiner_assignment_id' => ['examiner', 'Penguji (opsional)']] as $field => [$role, $label])<label>{{ $label }}<select class="input" name="{{ $field }}" @required($role === 'mentor')><option value="">Pilih penugasan</option>@foreach($assignments->where('role', $role) as $assignment)<option value="{{ $assignment->id }}" @selected(old($field, $row->$field ?? '') == $assignment->id)>{{ $assignment->name }} · {{ \Carbon\Carbon::parse($assignment->end_date)->format('d-m-Y') }}</option>@endforeach</select></label>@endforeach
        @if($change || ($row->replaces_id ?? null))<label>Jenis perubahan<select class="input" name="change_kind"><option value="schedule">Ganti jadwal</option><option value="cancel" @selected(old('change_kind', $row->change_kind ?? '') === 'cancel')>Batalkan jadwal asal</option></select></label>@else<input type="hidden" name="change_kind" value="schedule">@endif
        <label class="md:col-span-2">Catatan<textarea class="input" name="notes" maxlength="2000">{{ old('notes', $row->notes ?? '') }}</textarea></label>
        <label class="md:col-span-2">Alasan (wajib untuk perubahan atau tindakan admin)<textarea class="input" name="reason" minlength="10" maxlength="2000">{{ old('reason', $change ? '' : ($row->reason ?? '')) }}</textarea></label>
        <div class="md:col-span-2"><button class="btn-primary">Simpan draft</button></div>
    </form>
</x-layouts.app>
