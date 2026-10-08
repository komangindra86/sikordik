<x-layouts.app title="Putuskan penerimaan">
    <h1 class="mb-2 text-2xl font-bold">Penerimaan yang menunggu keputusan Anda</h1>
    <p class="mb-5 text-sm text-slate-600">Hilangkan centang pada peserta yang ingin diputuskan sendiri-sendiri, lalu setujui sisanya sekaligus. Penolakan dilakukan dari halaman penempatannya karena wajib beralasan.</p>
    @if($placements->isEmpty())
        <p class="card text-slate-600">Tidak ada penerimaan yang menunggu keputusan Anda.</p>
    @else
        <form method="POST" action="{{ route('admissions.accept-many') }}">@csrf
            @foreach($placements->groupBy('letter_number') as $letter => $group)
                <section class="card mb-4 p-0"><h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Surat {{ $letter }} <span class="text-sm font-normal text-slate-500">({{ $group->count() }} peserta)</span></h2>
                    <ul class="divide-y divide-slate-100">@foreach($group as $p)<li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"><label class="flex min-w-0 items-start gap-3 font-normal"><input class="mt-1" type="checkbox" name="ids[]" value="{{ $p->ulid }}" checked><span><span class="block font-semibold">{{ $p->participant_name }}</span><span class="block text-sm text-slate-500">{{ json_decode($p->snapshot)->department }} · {{ json_decode($p->snapshot)->program }} · {{ \App\Support\Ui::period($p->start_date, $p->end_date) }}</span></span></label><a class="text-sm font-semibold text-brand-700" href="{{ route('admissions.show', $p->ulid) }}">Lihat / tolak →</a></li>@endforeach</ul>
                </section>
            @endforeach
            <button class="btn-primary">Setujui yang dicentang</button>
        </form>
    @endif
</x-layouts.app>
