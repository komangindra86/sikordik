<x-layouts.app title="Tautan survei">
    <a class="text-brand-700 underline" href="{{ route('completion.index') }}">Kembali ke penyelesaian</a>
    <h1 class="my-4 text-2xl font-bold">Kelola tautan Google Form</h1>
    <p class="mb-5 text-sm">Siapkan kolom kode respons pada Google Form. Survei peserta dapat menilai pelayanan Kordik, pembimbing, fasilitas, jadwal, lingkungan belajar, proses pembelajaran, dan saran. Survei pasien dilakukan lewat wawancara, minimal satu respons per penempatan.</p>
    <form class="card space-y-4 p-5" method="POST" action="{{ route('completion.form-store') }}">@csrf
        <label class="block">Jenis survei<select name="kind">@foreach(\App\Services\SurveyService::KINDS as $kind => $label)<option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ $label }}</option>@endforeach</select></label>
        <label class="block">Nama dan versi formulir<input name="name" maxlength="150" value="{{ old('name') }}" required></label>
        <label class="block">Tautan respons Google Form<input type="url" name="url" maxlength="500" value="{{ old('url') }}" placeholder="https://forms.gle/..." required></label>
        <p class="text-sm text-slate-600">Gunakan tautan forms.gle atau docs.google.com/forms/d/e/.../viewform tanpa parameter. Formulir aktif terbaru digunakan untuk token baru; tautan lama tetap tersimpan untuk riwayat.</p>
        <label class="flex items-start gap-2"><input type="checkbox" name="confirm" value="1" required><span>Formulir menyediakan kolom kode respons, tidak meminta identitas/data medis pasien, dan pengumpulan email otomatis dinonaktifkan untuk survei pasien.</span></label>
        <button class="btn-primary">Simpan formulir baru</button>
    </form>
    <div class="mt-5 space-y-3">@foreach($forms as $f)<article class="card p-5"><h2 class="font-semibold">{{ $f->name }}</h2><p class="my-2 text-sm">{{ \App\Services\SurveyService::KINDS[$f->kind] }} · {{ $f->is_active ? 'Aktif' : 'Nonaktif' }}</p><a class="break-all text-brand-700 underline" href="{{ $f->url }}" target="_blank" rel="noopener noreferrer">{{ $f->url }}</a>@if($f->is_active)<form class="mt-3" method="POST" action="{{ route('completion.form-disable', $f->id) }}">@csrf<button class="btn-secondary">Nonaktifkan untuk token baru</button></form>@endif</article>@endforeach</div>
    <div class="mt-4">{{ $forms->links() }}</div>
</x-layouts.app>
