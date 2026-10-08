<x-layouts.app title="Susun jadwal stase">
    <a class="text-sm text-brand-700" href="{{ route('scheduling.show', $p->ulid) }}">← Kembali ke jadwal</a>
    <h1 class="mb-2 mt-3 text-2xl font-bold">Susun jadwal stase</h1>
    <p class="mb-5 text-sm text-slate-600">Isi sekali untuk seluruh periode. Sistem membuat satu jadwal per hari yang dipilih; hari yang berbeda dari biasanya bisa diubah satu per satu sesudahnya.</p>
    @if($assignments->isEmpty())
        <p class="card">Jadwal baru dapat disusun setelah pembimbing ditugaskan dan disetujui Ketua KSM.</p>
    @else
    <form class="card grid max-w-3xl gap-4 md:grid-cols-2" method="POST" action="{{ route('scheduling.range-store', $p->ulid) }}">@csrf
        <label>Dari tanggal<input type="date" name="date_from" min="{{ $p->start_date }}" max="{{ $p->end_date }}" value="{{ old('date_from', $p->start_date) }}" required></label>
        <label>Sampai tanggal<input type="date" name="date_to" min="{{ $p->start_date }}" max="{{ $p->end_date }}" value="{{ old('date_to', $p->end_date) }}" required></label>
        <fieldset class="md:col-span-2"><legend class="text-sm font-semibold text-slate-700">Hari kegiatan</legend>
            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">@foreach([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'] as $n => $day)<label class="flex items-center gap-2 font-normal"><input type="checkbox" name="weekdays[]" value="{{ $n }}" @checked(in_array($n, old('weekdays', [1, 2, 3, 4, 5])))>{{ $day }}</label>@endforeach</div>
        </fieldset>
        <label>Jam mulai (opsional)<input type="time" name="start_time" value="{{ old('start_time') }}"></label>
        <label>Jam selesai (opsional)<input type="time" name="end_time" value="{{ old('end_time') }}"></label>
        <label class="md:col-span-2">Kegiatan<input name="activity" value="{{ old('activity', 'Kegiatan stase') }}" maxlength="150" required></label>
        <label>Lokasi<select name="clinical_location_id" required>@foreach($locations as $l)<option value="{{ $l->id }}" @selected(old('clinical_location_id') == $l->id)>{{ $l->name }}</option>@endforeach</select></label>
        <label>Pembimbing<select name="mentor_assignment_id" required>@foreach($assignments as $a)<option value="{{ $a->id }}" @selected(old('mentor_assignment_id') == $a->id)>{{ $a->name }} ({{ \App\Support\Ui::period($a->start_date, $a->end_date) }})</option>@endforeach</select></label>
        @unless($owner)<label class="md:col-span-2">Alasan Anda menyusun jadwal untuk peserta (minimal 10 karakter)<textarea name="reason" minlength="10" maxlength="2000" required>{{ old('reason') }}</textarea></label>@endunless
        <p class="text-xs text-slate-500 md:col-span-2">Waktu WITA. Tanpa jam berarti kegiatan sepanjang hari. Jangan menulis identitas pasien.</p>
        <div class="flex flex-wrap gap-3 md:col-span-2"><button class="btn-primary" name="submit" value="1">Simpan & ajukan ke pembimbing</button><button class="btn-secondary" name="submit" value="0">Simpan sebagai draf</button></div>
    </form>
    @endif
</x-layouts.app>
