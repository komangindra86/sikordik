<x-layouts.app :title="($record ? 'Ubah ' : 'Tambah ').$definition['label']">
    <div class="mb-6"><a class="text-sm font-semibold text-brand-700" href="{{ route('masters.index', $master) }}">← Kembali</a><h1 class="mt-2 text-2xl font-bold text-slate-900">{{ $record ? 'Ubah' : 'Tambah' }} {{ $definition['label'] }}</h1></div>
    <form method="POST" action="{{ $record ? route('masters.update', [$master, $record->id]) : route('masters.store', $master) }}" class="space-y-5">@csrf @if($record)@method('PUT')@endif
        <section class="card"><div class="grid gap-4 sm:grid-cols-2">@foreach($definition['fields'] as $name => $field)
            @php($value = old($name, $record->{$name} ?? ''))
            @if($field['type'] === 'checkbox')<label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 font-normal sm:col-span-2"><input name="{{ $name }}" type="checkbox" value="1" {{ old($name, $record->{$name} ?? false) ? 'checked' : '' }}><span>{{ $field['label'] }}</span></label>
            @else<div class="{{ $field['type'] === 'textarea' ? 'sm:col-span-2' : '' }}"><label for="{{ $name }}">{{ $field['label'] }}</label>
                @if($field['type'] === 'select')<select id="{{ $name }}" name="{{ $name }}" {{ empty($field['nullable']) ? 'required' : '' }}>@if(!empty($field['nullable']))<option value="">— Tidak ditentukan —</option>@else<option value="">Pilih data</option>@endif @foreach($options[$name] as $option)<option value="{{ $option->id }}" {{ (string)$value === (string)$option->id ? 'selected' : '' }}>{{ $option->name }}</option>@endforeach</select>
                @elseif($field['type'] === 'textarea')<textarea id="{{ $name }}" name="{{ $name }}" rows="3">{{ $value }}</textarea>
                @else<input id="{{ $name }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $value }}" {{ in_array('required', $definition['rules'][$name]) ? 'required' : '' }}>@endif
                @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>@endif
        @endforeach</div></section>
        @if($record)<section class="card"><label for="change_reason">Alasan perubahan <span class="text-red-600">*</span></label><textarea id="change_reason" name="change_reason" rows="3" required>{{ old('change_reason') }}</textarea></section>@endif
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a class="btn-secondary" href="{{ route('masters.index', $master) }}">Batal</a><button class="btn-primary" type="submit">Simpan data</button></div>
    </form>
    @if($record && auth()->user()->hasPermission('masters.status'))<section class="card mt-8 border-amber-200"><h2 class="font-bold text-slate-900">Status data</h2><p class="mt-1 text-sm text-slate-500">Data transaksi akan tetap mempertahankan referensi ke data master nonaktif.</p><form method="POST" action="{{ route('masters.status', [$master, $record->id]) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto]">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $record->is_active ? 0 : 1 }}"><div><label for="status_reason">Alasan</label><input id="status_reason" name="change_reason" required minlength="5"></div><button class="btn-secondary self-end" type="submit">{{ $record->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></section>@endif
</x-layouts.app>
