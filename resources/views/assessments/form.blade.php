<x-layouts.app title="Isi penilaian">
    <a class="text-brand-700" href="{{ route('assessments.placement', $p->ulid) }}">← Penilaian penempatan</a>
    <h1 class="my-3 text-2xl font-bold">{{ $r ? 'Versi penilaian baru' : 'Isi penilaian' }}</h1>
    @if(!$r)
        <form class="card mb-5 space-y-4 p-5" method="GET">
            <div><label class="label" for="date">Tanggal penilaian</label><input class="input" type="date" id="date" name="date" value="{{ $date }}" required></div>
            <p class="text-sm">Terapkan tanggal dahulu untuk memperbarui pilihan template sesuai periode.</p>
            <div><label class="label" for="template_id">Template yang sesuai penempatan</label><select class="input" id="template_id" name="template_id"><option value="">Pilih template</option>@foreach($templates as $t)<option value="{{ $t->id }}" @selected($template?->id === $t->id)>{{ $t->name }} · {{ $t->exam_type }}</option>@endforeach</select></div>
            <button class="btn-secondary">Terapkan tanggal dan template</button>
        </form>
    @endif
    @if($template)
        <form class="card max-w-3xl space-y-5 p-5" method="POST" enctype="multipart/form-data" action="{{ $r ? route('assessments.update', [$p->ulid, $r->ulid]) : route('assessments.store', $p->ulid) }}">@csrf
            <input type="hidden" name="revision" value="{{ $r->revision ?? 0 }}"><input type="hidden" name="template_id" value="{{ $template->id }}"><input type="hidden" name="date" value="{{ $date }}">
            <h2 class="font-semibold">{{ $template->name }} · {{ \Carbon\Carbon::parse($date)->format('d-m-Y') }}</h2>
            <div><label class="label" for="title">Judul / identitas ujian</label><input class="input" id="title" name="title" value="{{ old('title', $r->title ?? '') }}" maxlength="150" required @readonly($r)></div>
            @if($r)
                @foreach(['mode', 'author_assignment_id', 'mentor_assignment_id'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $r->$key }}">@endforeach
                <p class="text-sm">Metode: {{ $r->mode === 'dynamic' ? 'Formulir dinamis' : 'Unggah formulir institusi' }}. Identitas dan penugasan mengikuti versi awal.</p>
            @else
                <div><label class="label" for="mode">Metode pengisian</label><select class="input" id="mode" name="mode"><option value="dynamic">Formulir dinamis</option><option value="document" @selected(old('mode') === 'document')>Unggah formulir institusi yang sudah diisi dan ditandatangani</option></select></div>
                <div><label class="label" for="author_assignment_id">Penugasan Anda sebagai pengisi</label><select class="input" id="author_assignment_id" name="author_assignment_id" required>@foreach($assignments->where('educator_user_id', auth()->id()) as $a)<option value="{{ $a->id }}" @selected(old('author_assignment_id') == $a->id)>{{ $a->name }} · {{ $a->role === 'mentor' ? 'Pembimbing' : 'Penguji' }}</option>@endforeach</select></div>
                <div><label class="label" for="mentor_assignment_id">Pembimbing pengesah dan penerbit nilai</label><select class="input" id="mentor_assignment_id" name="mentor_assignment_id" required>@foreach($assignments->where('role', 'mentor') as $a)<option value="{{ $a->id }}" @selected(old('mentor_assignment_id') == $a->id)>{{ $a->name }}</option>@endforeach</select></div>
            @endif
            @if(!$r || $r->mode === 'dynamic')
                <h2 class="font-semibold">Komponen formulir dinamis</h2>
                <p class="text-sm text-slate-600">Komponen di bawah hanya digunakan pada metode formulir dinamis. {{ $template->calculation === 'weighted' ? 'Nilai dinormalisasi dari rentang setiap komponen lalu dijumlahkan sesuai bobot, skala total 0–100.' : 'Tidak ada perhitungan total otomatis.' }}</p>
                @foreach($components as $c)
                    @php($score = collect($s['scores'] ?? [])->first(fn($item) => $item['component']['id'] == $c->id))
                    <div><label class="label" for="score{{ $c->id }}">{{ $c->name }} · {{ $c->required ? 'Wajib' : 'Opsional' }}</label><p class="mb-2 text-sm text-slate-600">{{ $c->description }} {{ $c->notes }} @if($c->input_type === 'number')Rentang {{ $c->minimum }}–{{ $c->maximum }}. @if($c->weight !== null)Bobot {{ $c->weight }}%.@endif @endif</p>
                        @if($c->input_type === 'number')<input class="input" type="number" step="0.01" min="{{ $c->minimum }}" max="{{ $c->maximum }}" id="score{{ $c->id }}" name="scores[{{ $c->id }}]" value="{{ old('scores.'.$c->id, $score['value'] ?? '') }}">
                        @else<textarea class="input" id="score{{ $c->id }}" name="scores[{{ $c->id }}]" maxlength="2000">{{ old('scores.'.$c->id, $score['value'] ?? '') }}</textarea>@endif
                    </div>
                @endforeach
            @endif
            <div><label class="label" for="file">Formulir institusi PDF (maksimal 10 MB)</label><input class="input" type="file" id="file" name="file" accept="application/pdf,.pdf"><p class="mt-2 text-sm">Wajib untuk metode unggah. Setiap versi menyediakan PDF baru; berkas menunggu pemeriksaan keamanan sebelum pengesahan.</p></div>
            <div><label class="label" for="notes">Catatan penilaian / alasan koreksi</label><textarea class="input" id="notes" name="notes" maxlength="2000">{{ old('notes', $s['notes'] ?? '') }}</textarea></div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="deidentified" value="1" required><span>Saya memastikan teks dan lampiran bebas identitas serta informasi medis sensitif pasien.</span></label>
            <button class="btn-primary">Simpan versi draft</button>
        </form>
    @else<p class="text-sm">Pilih tanggal dan template yang berlaku. Jika belum tersedia, hubungi Admin Kordik.</p>@endif
</x-layouts.app>
