<form class="space-y-3" method="POST" enctype="multipart/form-data" action="{{ route('admissions.upload', [$resource, $resourceUlid]) }}">@csrf
    <label class="block">Kategori<select name="category">@foreach($categories as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select></label>
    <label class="block">Berkas privat<input type="file" name="file" accept="{{ $resource === 'letter' ? '.pdf' : '.pdf,.jpg,.jpeg,.png' }}" required></label>
    <p class="text-xs text-slate-500">Maksimum 10 MB. Setiap unggahan menjadi versi baru dan diperiksa sebelum bisa diunduh.</p>
    <label class="flex gap-2 text-xs"><input type="checkbox" name="deidentified" value="1" required> Saya memastikan berkas tidak memuat identitas pasien.</label><button class="btn-secondary">Unggah versi baru</button>
</form>
