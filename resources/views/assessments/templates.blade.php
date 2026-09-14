<x-layouts.app title="Template penilaian">
    <a class="text-brand-700" href="{{ route('assessments.index') }}">← Penilaian</a>
    <h1 class="my-3 text-2xl font-bold">Template penilaian</h1>
    <p class="mb-5 text-sm text-slate-600">Template yang tersimpan bersifat tetap. Untuk mengubah komponen, buat template pengganti dan nonaktifkan yang lama. Nilai terdahulu tetap utuh.</p>
    <form class="card space-y-5 p-5" method="POST" action="{{ route('assessments.template-store') }}">@csrf
        <h2 class="font-semibold">Template baru</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="label" for="name">Nama template / versi institusi</label><input class="input" id="name" name="name" value="{{ old('name') }}" required maxlength="150"></div>
            <div><label class="label" for="exam_type">Jenis penilaian (misalnya Mini-CEX, DOPS, OSCE)</label><input class="input" id="exam_type" name="exam_type" value="{{ old('exam_type') }}" required maxlength="100"></div>
            @foreach(['institution_id' => ['institutions', 'Institusi'], 'study_program_id' => ['study_programs', 'Program studi'], 'participant_type_id' => ['participant_types', 'Jenis peserta'], 'department_id' => ['departments', 'KSM']] as $field => [$table, $label])
                <div><label class="label" for="{{ $field }}">{{ $label }}</label><select class="input" name="{{ $field }}" id="{{ $field }}"><option value="">Semua</option>@foreach($masters[$table] as $m)<option value="{{ $m->id }}" @selected(old($field) == $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            @endforeach
            <div><label class="label" for="start_date">Periode mulai (opsional)</label><input class="input" type="date" id="start_date" name="start_date" value="{{ old('start_date') }}"></div>
            <div><label class="label" for="end_date">Periode akhir (opsional)</label><input class="input" type="date" id="end_date" name="end_date" value="{{ old('end_date') }}"></div>
            <div><label class="label" for="calculation">Perhitungan</label><select class="input" id="calculation" name="calculation"><option value="none">Tanpa agregasi, tampilkan setiap komponen</option><option value="weighted" @selected(old('calculation') === 'weighted')>Berbobot, normalisasi rentang ke 0–100</option></select></div>
            <div><label class="label" for="pass_mark">Batas lulus total berbobot (opsional)</label><input class="input" type="number" step="0.01" min="0" max="100" id="pass_mark" name="pass_mark" value="{{ old('pass_mark') }}"></div>
        </div>
        <p class="text-sm text-slate-600">Opsi berbobot: jumlah ((nilai − minimum) ÷ (maksimum − minimum) × bobot). Jumlah bobot harus 100; seluruh komponen angka wajib. Pilih tanpa agregasi bila rumus institusi berbeda, atau gunakan unggahan formulir yang sudah dihitung institusi.</p>
        <p class="text-sm">Isi minimal satu komponen. Komponen tanpa nama tidak disimpan. Kolom angka dikosongkan untuk input teks.</p>
        @for($i = 0; $i < 30; $i++)
            <details class="rounded-lg border border-slate-200 p-4" @if($i === 0 || old("components.$i.name")) open @endif>
                <summary class="cursor-pointer font-semibold">Komponen {{ $i + 1 }}</summary>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div><label class="label" for="c{{ $i }}name">Nama komponen</label><input class="input" id="c{{ $i }}name" name="components[{{ $i }}][name]" value="{{ old("components.$i.name") }}" maxlength="150"></div>
                    <div><label class="label" for="c{{ $i }}type">Jenis input</label><select class="input" id="c{{ $i }}type" name="components[{{ $i }}][input_type]"><option value="number">Angka</option><option value="text" @selected(old("components.$i.input_type") === 'text')>Teks</option></select></div>
                    @foreach(['description' => 'Deskripsi', 'notes' => 'Catatan/petunjuk'] as $key => $label)<div><label class="label" for="c{{ $i }}{{ $key }}">{{ $label }}</label><input class="input" id="c{{ $i }}{{ $key }}" name="components[{{ $i }}][{{ $key }}]" value="{{ old("components.$i.$key") }}" maxlength="1000"></div>@endforeach
                    @foreach(['minimum' => 'Skor minimum', 'maximum' => 'Skor maksimum', 'weight' => 'Bobot (%)', 'pass_mark' => 'Batas lulus komponen'] as $key => $label)<div><label class="label" for="c{{ $i }}{{ $key }}">{{ $label }}</label><input class="input" type="number" step="0.01" id="c{{ $i }}{{ $key }}" name="components[{{ $i }}][{{ $key }}]" value="{{ old("components.$i.$key") }}"></div>@endforeach
                    <div><label class="label" for="c{{ $i }}required">Status pengisian</label><select class="input" id="c{{ $i }}required" name="components[{{ $i }}][required]"><option value="1">Wajib</option><option value="0" @selected(old("components.$i.required") === '0')>Opsional</option></select></div>
                </div>
            </details>
        @endfor
        <button class="btn-primary">Simpan template tetap</button>
    </form>
    <h2 class="mb-3 mt-7 text-lg font-semibold">Template tersimpan</h2>
    <div class="grid gap-4 md:grid-cols-2">@forelse($templates as $t)<div class="card p-5"><h3 class="font-semibold">{{ $t->name }}</h3><p class="my-2 text-sm">{{ $t->exam_type }} · {{ $t->is_active ? 'Aktif' : 'Nonaktif' }}</p><p class="text-sm">{{ $t->calculation === 'none' ? 'Tanpa agregasi' : 'Berbobot 0–100' }}</p>
        <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold">Cakupan dan komponen</summary><div class="mt-3 space-y-3 text-sm">
            @foreach(['institution_id' => ['institutions', 'Institusi'], 'study_program_id' => ['study_programs', 'Program studi'], 'participant_type_id' => ['participant_types', 'Jenis peserta'], 'department_id' => ['departments', 'KSM']] as $key => [$table, $label])<p>{{ $label }}: {{ $t->$key ? ($masters[$table]->firstWhere('id', $t->$key)?->name ?? 'Master nonaktif #'.$t->$key) : 'Semua' }}</p>@endforeach
            <p>Periode: {{ $t->start_date ? \Carbon\Carbon::parse($t->start_date)->format('d-m-Y').' – '.\Carbon\Carbon::parse($t->end_date)->format('d-m-Y') : 'Semua periode' }}</p>
            @foreach($components[$t->id] ?? [] as $c)<div class="rounded-lg bg-slate-50 p-3"><p class="font-semibold">{{ $c->position }}. {{ $c->name }} ({{ $c->input_type === 'number' ? 'Angka' : 'Teks' }}, {{ $c->required ? 'wajib' : 'opsional' }})</p><p>{{ $c->description }} {{ $c->notes }}</p>@if($c->input_type === 'number')<p>Rentang {{ $c->minimum }}–{{ $c->maximum }} · Bobot {{ $c->weight ?? '—' }} · Batas lulus {{ $c->pass_mark ?? '—' }}</p>@endif</div>@endforeach
        </div></details>
        @if($t->is_active)<form class="mt-3" method="POST" action="{{ route('assessments.template-disable', $t->id) }}">@csrf<button class="btn-secondary">Nonaktifkan untuk penilaian baru</button></form>@endif</div>@empty<p>Belum ada template.</p>@endforelse</div>
    <div class="mt-4">{{ $templates->links() }}</div>
</x-layouts.app>
