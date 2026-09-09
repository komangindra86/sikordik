<details class="card mt-6 p-5"><summary class="cursor-pointer text-lg font-semibold">Riwayat perubahan (100 terbaru)</summary>
    @php($events = ['assignment_requested'=>'Penugasan diajukan','assignment_approve'=>'Penugasan disetujui','assignment_reject'=>'Penugasan ditolak','assignment_replaced'=>'Pendidik diganti','schedule_saved'=>'Draft jadwal disimpan','schedule_submit'=>'Jadwal diajukan','schedule_approve'=>'Jadwal disetujui','schedule_revise'=>'Revisi diminta','schedule_publish'=>'Keputusan jadwal diterbitkan','schedule_withdraw'=>'Pengajuan ditarik','schedule_complete'=>'Kegiatan selesai','schedule_replaced'=>'Jadwal lama digantikan / dibatalkan','extension_requested'=>'Perpanjangan diajukan','extension_approve'=>'Perpanjangan disetujui','extension_reject'=>'Perpanjangan ditolak','extension_withdraw'=>'Perpanjangan ditarik','group_joined'=>'Keanggotaan ditambahkan','group_ended'=>'Keanggotaan diakhiri'])
    <ol class="mt-4 space-y-4">@forelse($histories as $h)
        <li class="border-l-2 border-brand-100 pl-4"><p class="text-sm font-semibold">{{ $events[$h->event] ?? 'Data diperbarui' }}</p><p class="text-xs text-slate-500">{{ $h->actor_name }} · {{ \Carbon\Carbon::parse($h->created_at)->format('d-m-Y H:i') }} WITA</p><p class="mt-1 break-words text-sm">{{ $h->reason }}</p>
            <details class="mt-2 text-sm"><summary class="cursor-pointer text-brand-700">Bandingkan sebelum dan sesudah</summary>
                <div class="mt-2 grid gap-3 md:grid-cols-2">@foreach(['Sebelum' => $h->before, 'Sesudah' => $h->after] as $label => $json)
                    @php($data = json_decode($json ?? 'null', true))
                    <div class="rounded-xl bg-slate-50 p-3"><p class="font-semibold">{{ $label }}</p>@if(!$data)<p class="text-slate-500">Belum ada data.</p>@else
                    @foreach(['activity'=>'Kegiatan','date'=>'Tanggal','start_date'=>'Mulai','end_date'=>'Selesai','old_end_date'=>'Akhir sebelumnya','new_end_date'=>'Akhir usulan','start_time'=>'Jam mulai','end_time'=>'Jam selesai','status'=>'Status','reason'=>'Alasan'] as $field => $title)
                        @if(isset($data[$field]))<p class="mt-1 break-words"><span class="text-slate-500">{{ $title }}:</span> {{ $field === 'status' ? \App\Services\ScheduleService::label($data[$field]) : (in_array($field, ['date','start_date','end_date','old_end_date','new_end_date']) ? \Carbon\Carbon::parse($data[$field])->format('d-m-Y') : $data[$field]) }}</p>@endif
                    @endforeach
                    @if(isset($data['educator_id']))<p class="mt-1">Pendidik: {{ $assignments->firstWhere('educator_id', $data['educator_id'])?->educator_name ?? 'Lihat riwayat penugasan' }}</p>@endif
                    @endif</div>
                @endforeach</div>
            </details>
        </li>
    @empty<li class="text-sm text-slate-500">Belum ada riwayat fase 3.</li>@endforelse</ol>
</details>
