<div class="mb-6 flex flex-wrap gap-2">
    @if(app(\App\Services\AdmissionsAccess::class)->admin(auth()->user()))
    <a class="{{ request()->routeIs('admissions.batch*') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admissions.batch') }}">Terima peserta baru</a>
    @endif
    <a class="{{ request()->routeIs('admissions.index') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admissions.index') }}">Daftar penerimaan</a>
    @if(app(\App\Services\AdmissionsAccess::class)->admin(auth()->user()))
    <a class="{{ request()->routeIs('admissions.participants', 'admissions.participant-*') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admissions.participants') }}">Data peserta</a>
    <a class="{{ request()->routeIs('admissions.letters') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admissions.letters') }}">Surat masuk</a>
    <a class="{{ request()->routeIs('admissions.imports', 'admissions.import') ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admissions.imports') }}">Impor XLSX</a>
    @endif
</div>
