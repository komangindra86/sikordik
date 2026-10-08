<x-layouts.app title="Periksa penerimaan">
    @include('admissions.nav')
    <h1 class="mb-2 text-2xl font-bold">Periksa sebelum menyimpan</h1>
    <p class="mb-5 text-sm text-slate-600">Belum ada yang tersimpan. Untuk mengubah isian, kembali ke halaman sebelumnya lewat tombol kembali peramban.</p>
    <div class="card mb-5 text-sm"><p><strong>Surat:</strong> {{ $letter ? $letter->number.' ('.\App\Support\Ui::date($letter->letter_date).')' : $head['number'].' ('.\App\Support\Ui::date($head['letter_date']).') — surat baru' }} · {{ $names['institution_id'] }}</p>
        <p class="mt-1"><strong>Penempatan:</strong> {{ $names['department_id'] }} · {{ $names['study_program_id'] }} · {{ $names['participant_type_id'] }} · {{ \App\Support\Ui::period($head['start_date'], $head['end_date']) }}</p></div>
    <form method="POST" action="{{ route('admissions.batch-store') }}">@csrf
        @foreach($head as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <div class="space-y-3">
            @foreach($rows as $i => $row)
                @foreach(['name', 'nim', 'birth_date', 'email'] as $field)<input type="hidden" name="rows[{{ $i }}][{{ $field }}]" value="{{ $row[$field] ?? '' }}">@endforeach
                <div class="card {{ $row['candidates'] ? 'border-amber-300' : '' }}"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold">{{ $i + 1 }}. {{ $row['name'] }}</p>@if(! $row['candidates'])<x-badge tone="ok" label="Peserta baru" />@else<x-badge tone="wait" label="Mungkin peserta lama" />@endif</div>
                    <p class="text-sm text-slate-500">NIM {{ $row['nim'] ?: '—' }} · Lahir {{ \App\Support\Ui::date($row['birth_date'] ?? null) }} · {{ $row['email'] ?: 'tanpa email' }}</p>
                    @if($row['candidates'])
                        <fieldset class="mt-3 space-y-2"><legend class="text-sm font-semibold">Pilih salah satu:</legend>
                            @foreach($row['candidates'] as $n => $c)<label class="flex items-start gap-2 font-normal"><input class="mt-1" type="radio" name="rows[{{ $i }}][use]" value="{{ $c['ulid'] }}" @checked($n === 0) required><span>Orang yang sama: <strong>{{ $c['number'] }} · {{ $c['name'] }}</strong> <span class="text-slate-500">({{ implode('; ', $c['reasons']) }})</span> — pakai data lama, buat penempatan baru</span></label>@endforeach
                            <label class="flex items-start gap-2 font-normal"><input class="mt-1" type="radio" name="rows[{{ $i }}][use]" value="new"><span>Orang berbeda — daftarkan sebagai peserta baru</span></label>
                        </fieldset>
                        <label class="mt-2 block">Bila orang berbeda, tulis alasannya (minimal 10 karakter)<input name="rows[{{ $i }}][reason]" maxlength="2000"></label>
                    @else
                        <input type="hidden" name="rows[{{ $i }}][use]" value="new">
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-5 flex flex-wrap gap-3"><button class="btn-primary" name="submit" value="1">Simpan & ajukan {{ count($rows) }} penempatan ke KSM</button><button class="btn-secondary" name="submit" value="0">Simpan sebagai draf</button></div>
    </form>
</x-layouts.app>
