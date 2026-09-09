<input type="hidden" name="revision" value="{{ $row->revision ?? 0 }}">
@if($row)<input type="hidden" name="date" value="{{ $row->date }}">@else<label>Tanggal pendidikan<select class="input" name="date" required>@foreach($days as $date)@if($date <= now()->toDateString() && !$rows->contains('date', $date))<option value="{{ $date }}">{{ $date }}</option>@endif
@endforeach</select></label>@endif
<label>Status kehadiran<select class="input" name="attendance_status" required>@foreach(\App\Services\AttendanceService::STATUSES as $status)<option value="{{ $status }}" @selected(($row->attendance_status ?? 'hadir') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></label>
<label>Lokasi/unit<select class="input" name="clinical_location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(($row->clinical_location_id ?? null) == $location->id)>{{ $location->name }}</option>@endforeach</select></label>
<label>Pembimbing verifikator<select class="input" name="mentor_assignment_id" required>@foreach($assignments as $assignment)<option value="{{ $assignment->id }}" @selected(($row->mentor_assignment_id ?? null) == $assignment->id)>{{ $assignment->name }} ({{ $assignment->start_date }} – {{ $assignment->end_date }})</option>@endforeach</select></label>
<label class="md:col-span-2">Ringkasan kegiatan<textarea class="input" name="activity" maxlength="2000" required>{{ $row->activity ?? '' }}</textarea></label>
<label class="md:col-span-2">Catatan<textarea class="input" name="notes" maxlength="2000">{{ $row->notes ?? '' }}</textarea></label>
<p class="text-xs text-slate-500 md:col-span-2">Jangan mencantumkan nama, nomor rekam medis, atau identitas pasien.</p>
