<x-layouts.app title="Terima peserta baru">
    @include('admissions.nav')
    <h1 class="mb-2 text-2xl font-bold">Terima peserta baru</h1>
    <p class="mb-5 text-sm text-slate-600">Satu surat untuk satu atau banyak peserta. Setelah ini Anda memeriksa kemungkinan peserta lama, lalu semua penempatan dibuat sekaligus.</p>
    <form class="space-y-5" method="POST" action="{{ route('admissions.batch-preview') }}">@csrf
        <section class="card"><h2 class="mb-3 text-lg font-bold">1. Surat pengantar</h2>
            <label class="block">Surat<select name="letter_ulid"><option value="">Surat baru — isi di bawah</option>@foreach($letters as $l)<option value="{{ $l->ulid }}" @selected(old('letter_ulid', request('letter')) === $l->ulid)>{{ $l->number }} · {{ \App\Support\Ui::date($l->letter_date) }}</option>@endforeach</select></label>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label>Institusi pengirim<select name="institution_id"><option value="">Pilih institusi</option>@foreach($institutions as $i)<option value="{{ $i->id }}" @selected(old('institution_id') == $i->id)>{{ $i->name }}</option>@endforeach</select></label>
                <label>Nomor surat<input name="number" value="{{ old('number') }}" maxlength="191"></label>
                <label>Tanggal surat<input type="date" name="letter_date" value="{{ old('letter_date') }}"></label>
                <label>Perihal<input name="subject" value="{{ old('subject') }}" maxlength="255"></label>
            </div>
            <p class="mt-2 text-xs text-slate-500">Empat isian ini hanya untuk surat baru. Abaikan bila memilih surat yang sudah dicatat.</p>
        </section>

        <section class="card"><h2 class="mb-3 text-lg font-bold">2. Penempatan (sama untuk semua peserta di bawah)</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label>Program studi<select name="study_program_id" required><option value="">Pilih</option>@foreach($study_programs as $o)<option value="{{ $o->id }}" @selected(old('study_program_id') == $o->id)>{{ $o->name }} — {{ $institutions->firstWhere('id', $o->institution_id)?->name }}</option>@endforeach</select></label>
                <label>Jenis peserta<select name="participant_type_id" required><option value="">Pilih</option>@foreach($participant_types as $o)<option value="{{ $o->id }}" @selected(old('participant_type_id') == $o->id)>{{ $o->name }}</option>@endforeach</select></label>
                <label>KSM tujuan<select name="department_id" required><option value="">Pilih</option>@foreach($departments as $o)<option value="{{ $o->id }}" @selected(old('department_id') == $o->id)>{{ $o->name }}</option>@endforeach</select></label>
                <div class="grid grid-cols-2 gap-4"><label>Mulai<input type="date" name="start_date" value="{{ old('start_date') }}" required></label><label>Selesai<input type="date" name="end_date" value="{{ old('end_date') }}" required></label></div>
            </div>
        </section>

        <section class="card"><h2 class="mb-1 text-lg font-bold">3. Peserta</h2><p class="mb-3 text-sm text-slate-500">Cukup nama. NIM, tanggal lahir, dan email membantu mengenali peserta yang pernah stase di sini.</p>
            <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-sm"><thead><tr class="text-left"><th class="p-1">Nama sesuai identitas</th><th class="p-1">NIM</th><th class="p-1">Tanggal lahir</th><th class="p-1">Email</th></tr></thead>
                <tbody id="batch-rows">@for($i = 0; $i < max(5, count(old('rows', []))); $i++)<tr><td class="p-1"><input name="rows[{{ $i }}][name]" value="{{ old("rows.$i.name") }}" maxlength="255" aria-label="Nama peserta {{ $i + 1 }}"></td><td class="p-1"><input name="rows[{{ $i }}][nim]" value="{{ old("rows.$i.nim") }}" maxlength="100" aria-label="NIM peserta {{ $i + 1 }}"></td><td class="p-1"><input type="date" name="rows[{{ $i }}][birth_date]" value="{{ old("rows.$i.birth_date") }}" aria-label="Tanggal lahir peserta {{ $i + 1 }}"></td><td class="p-1"><input type="email" name="rows[{{ $i }}][email]" value="{{ old("rows.$i.email") }}" maxlength="255" aria-label="Email peserta {{ $i + 1 }}"></td></tr>@endfor</tbody>
            </table></div>
            <button class="btn-secondary mt-3" type="button" id="batch-add">Tambah 5 baris</button>
            <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold text-brand-700">Atau tempel banyak peserta dari Excel</summary>
                <label class="mt-3 block">Satu peserta per baris. Urutan kolom: Nama, NIM, Tanggal lahir, Email<textarea name="pasted" rows="6" placeholder="Ayu Lestari&#9;2101001&#9;14-03-2001&#9;ayu@contoh.ac.id">{{ old('pasted') }}</textarea></label>
                <p class="mt-1 text-xs text-slate-500">Salin sel dari Excel lalu tempel di sini. Tanggal boleh 14-03-2001 atau 2001-03-14. Paling banyak {{ \App\Services\BatchAdmissionService::MAX_ROWS }} peserta.</p>
            </details>
        </section>
        <button class="btn-primary">Lanjut: periksa data</button>
    </form>
    <script>
        document.getElementById('batch-add').addEventListener('click', function () {
            var body = document.getElementById('batch-rows');
            for (var n = 0; n < 5; n++) {
                var i = body.rows.length, row = body.rows[0].cloneNode(true);
                row.querySelectorAll('input').forEach(function (input) {
                    input.value = '';
                    input.name = input.name.replace(/rows\[\d+\]/, 'rows[' + i + ']');
                    input.setAttribute('aria-label', input.getAttribute('aria-label').replace(/\d+$/, i + 1));
                });
                body.appendChild(row);
            }
        });
    </script>
</x-layouts.app>
