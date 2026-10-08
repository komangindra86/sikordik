@php
    $navUser = auth()->user();
    $navRoles = $navUser->roleCodes();
    $navStaff = (bool) array_intersect($navRoles, ['super-admin', 'admin-kordik', 'tim-kordik', 'ketua-ksm', 'sekretariat-ksm']);
    $navParticipant = in_array('peserta', $navRoles, true);
    $navEducator = (bool) array_intersect($navRoles, ['pembimbing', 'supervisor']);
    $navAdmin = (bool) array_intersect($navRoles, ['super-admin', 'admin-kordik']);
    $navUnread = \Illuminate\Support\Facades\DB::table('scheduling_notifications')->where('user_id', $navUser->id)->whereNull('read_at')->count();
    $navLink = fn (string ...$patterns) => 'nav-link '.(request()->routeIs(...$patterns) ? 'nav-link-active' : '');
@endphp
<div class="mb-6 flex items-center gap-3"><div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 font-bold text-white">SK</div><div><p class="font-bold text-slate-900">SIKORDIK</p><p class="text-xs text-slate-500">Pendidikan Klinis RSBM</p></div></div>
<nav class="space-y-1">
    @if($navUser->hasPermission('dashboard.view'))<a class="{{ $navLink('dashboard') }}" href="{{ route('dashboard') }}">Beranda</a>@endif
    <a class="{{ $navLink('scheduling.notifications') }}" href="{{ route('scheduling.notifications') }}">Notifikasi @if($navUnread)<span class="badge badge-wait ml-auto">{{ $navUnread }}</span>@endif</a>

    <a class="{{ $navLink('placements.*', 'admissions.show', 'scheduling.show', 'scheduling.create', 'scheduling.edit', 'attendance.*', 'logbooks.*', 'assessments.placement', 'assessments.show', 'assessments.create', 'assessments.edit', 'completion.show') }}" href="{{ route('placements.index') }}">{{ $navStaff ? 'Penempatan' : ($navParticipant ? 'Stase saya' : 'Peserta bimbingan') }}</a>
    @if($navAdmin)<a class="{{ $navLink('admissions.index', 'admissions.create', 'admissions.participants', 'admissions.participant-*', 'admissions.letters', 'admissions.imports', 'admissions.import') }}" href="{{ route('admissions.index') }}">Penerimaan peserta</a>@endif

    <p class="nav-heading">Lainnya</p>
    @if($navStaff || $navEducator)<a class="{{ $navLink('reports') }}" href="{{ route('reports') }}">Laporan Excel / PDF</a>@endif
    <a class="{{ $navLink('verification.*') }}" href="{{ route('verification.index') }}">Cek pengesahan (QR)</a>

    @if($navStaff)
        <details @if(request()->routeIs('users.*', 'roles.*', 'masters.*', 'audit-logs.*', 'admissions.templates', 'assessments.templates', 'completion.forms', 'completion.index', 'scheduling.licenses', 'scheduling.index')) open @endif>
            <summary class="nav-heading cursor-pointer">Pengaturan</summary>
            @if($navUser->hasPermission('users.view'))<a class="{{ $navLink('users.*') }}" href="{{ route('users.index') }}">Pengguna</a>@endif
            @if($navUser->hasPermission('roles.manage'))<a class="{{ $navLink('roles.*') }}" href="{{ route('roles.index') }}">Role & hak akses</a>@endif
            @if($navUser->hasPermission('masters.view'))@foreach(config('masters') as $slug => $item)<a class="nav-link {{ request()->route('master') === $slug ? 'nav-link-active' : '' }}" href="{{ route('masters.index', $slug) }}">{{ $item['label'] }}</a>@endforeach @endif
            @if(in_array('admin-kordik', $navRoles, true))<a class="{{ $navLink('scheduling.licenses') }}" href="{{ route('scheduling.licenses') }}">Lisensi pendidik</a>@endif
            @if($navAdmin)
                <a class="{{ $navLink('admissions.templates') }}" href="{{ route('admissions.templates') }}">Persyaratan dokumen</a>
                <a class="{{ $navLink('assessments.templates') }}" href="{{ route('assessments.templates') }}">Template penilaian</a>
                <a class="{{ $navLink('completion.forms') }}" href="{{ route('completion.forms') }}">Tautan survei</a>
            @endif
            @if($navStaff)<a class="{{ $navLink('scheduling.index') }}" href="{{ route('scheduling.index') }}">Kelompok KSM</a><a class="{{ $navLink('completion.index') }}" href="{{ route('completion.index', ['archive' => 1]) }}">Arsip</a>@endif
            @if($navUser->hasPermission('audit-logs.view'))<a class="{{ $navLink('audit-logs.*') }}" href="{{ route('audit-logs.index') }}">Audit log</a>@endif
        </details>
    @endif
</nav>
@if($navStaff)<div class="mt-6 rounded-xl bg-slate-50 p-3 text-xs text-slate-500"><p class="font-semibold text-slate-700">Cakupan KSM</p><p class="mt-1">{{ count($navUser->departmentScopeIds()) ? count($navUser->departmentScopeIds()).' KSM ditugaskan' : (array_intersect($navRoles, ['super-admin', 'admin-kordik', 'tim-kordik']) ? 'Semua KSM' : 'Belum ada KSM ditugaskan') }}</p></div>@endif
