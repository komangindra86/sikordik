<div class="mb-6 flex flex-wrap gap-2">
    <a class="btn-secondary" href="{{ route('admissions.index') }}">Penempatan</a>
    @if(app(\App\Services\AdmissionsAccess::class)->admin(auth()->user()))
    <a class="btn-secondary" href="{{ route('admissions.participants') }}">Data peserta</a>
    <a class="btn-secondary" href="{{ route('admissions.letters') }}">Surat masuk</a>
    <a class="btn-secondary" href="{{ route('admissions.imports') }}">Impor XLSX</a>
    <a class="btn-secondary" href="{{ route('admissions.templates') }}">Persyaratan</a>
    @endif
</div>
