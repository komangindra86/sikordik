<form class="space-y-3" method="POST" enctype="multipart/form-data" action="{{ route('admissions.upload', [$resource, $resourceUlid]) }}">@csrf
    @if(count($categories) === 1)<input type="hidden" name="category" value="{{ array_key_first($categories) }}">@else<label class="block">Jenis berkas<select name="category">@foreach($categories as $category => $label)<option value="{{ $category }}">{{ $label }}</option>@endforeach</select></label>@endif
    <label class="block">{{ $resource === 'letter' ? 'PDF' : 'PDF, JPG, atau PNG' }}, maksimal 10 MB<input type="file" name="file" accept="{{ $resource === 'letter' ? '.pdf' : '.pdf,.jpg,.jpeg,.png' }}" required></label>
    <p class="text-xs text-slate-500">Setiap unggahan menjadi versi baru dan diperiksa keamanannya sebelum bisa diunduh.</p>
    <label class="flex items-start gap-2 text-sm font-normal"><input type="checkbox" name="deidentified" value="1" required><span>Berkas tidak memuat identitas pasien.</span></label><button class="btn-secondary">Unggah</button>
</form>
