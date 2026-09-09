<x-layouts.app title="Periksa identitas peserta">
    @include('admissions.nav')<h1 class="mb-4 text-2xl font-bold">Periksa sebelum menyimpan</h1>
    <div class="card mb-5"><p class="text-lg font-semibold">{{ $data['name'] }}</p><p class="text-sm">NIM: {{ $data['nim'] ?? '—' }} · Tanggal lahir: {{ $data['birth_date'] ?? '—' }}</p></div>
    @forelse($candidates as $c)<div class="card mb-3 border-amber-300"><p class="font-semibold">{{ $c['number'] }} · {{ $c['name'] }}</p><p class="my-2 text-sm">{{ implode('; ', $c['reasons']) }}</p><a class="text-brand-700 underline" href="{{ route('admissions.create', ['participant' => $c['ulid']]) }}">Gunakan peserta lama untuk penempatan baru</a></div>@empty<p class="mb-5">Tidak ditemukan kandidat berdasarkan identitas yang diisi.</p>@endforelse
    @if(!collect($candidates)->contains('hard', true))<form class="card space-y-4" method="POST" action="{{ route('admissions.participant-store') }}">@csrf @foreach($data as $key => $value)@if($key !== 'normalized_name')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        @if($candidates)<label class="block">Alasan kandidat bukan orang yang sama<textarea name="duplicate_reason" minlength="10" maxlength="2000" required>{{ old('duplicate_reason') }}</textarea></label>@endif
        <label class="flex items-center gap-2"><input type="checkbox" name="review_confirmed" value="1" required> Saya sudah meninjau data dan kandidat</label><button class="btn-primary">Simpan peserta baru</button>
    </form>@else<p class="card border-red-300">NIK sudah digunakan. Gunakan peserta lama atau kembali dan koreksi NIK.</p>@endif
</x-layouts.app>
