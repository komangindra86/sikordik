@php
    $navUser = auth()->user();
    $navRoles = $navUser->roleCodes();
    $navStaff = (bool) array_intersect($navRoles, ['super-admin', 'admin-kordik', 'tim-kordik', 'ketua-ksm', 'sekretariat-ksm']);
    $navParticipant = in_array('peserta', $navRoles, true);
    $navEducator = (bool) array_intersect($navRoles, ['pembimbing', 'supervisor']);
    $navUnread = \Illuminate\Support\Facades\DB::table('scheduling_notifications')->where('user_id', $navUser->id)->whereNull('read_at')->count();
    $navLink = fn (string ...$patterns) => 'nav-link '.(request()->routeIs(...$patterns) ? 'nav-link-active' : '');
@endphp
<div class="mb-6 flex items-center gap-3"><div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 font-bold text-white">SK</div><div><p class="font-bold text-slate-900">SIKORDIK</p><p class="text-xs text-slate-500">Pendidikan Klinis RSBM</p></div></div>
<nav class="space-y-1">
    @if($navUser->hasPermission('dashboard.view'))<a class="{{ $navLink('dashboard') }}" href="{{ route('dashboard') }}">Beranda</a>@endif
    <a class="{{ $navLink('scheduling.notifications') }}" href="{{ route('scheduling.notifications') }}">Notifikasi @if($navUnread)<span class="badge badge-wait ml-auto">{{ $navUnread }}</span>@endif</a>

    <p class="nav-heading">{{ $navStaff ? 'Urutan kerja stase' : 'Stase' }}</p>
    @if($navStaff || $navParticipant)<a class="{{ $navLink('admissions.*') }}" href="{{ route('admissions.index') }}">{{ $navStaff ? '1. Penerimaan & dokumen' : 'Penempatan & dokumen' }}</a>@endif
    <a class="nav-link {{ request()->routeIs('scheduling.*') && ! request()->routeIs('scheduling.notifications') ? 'nav-link-active' : '' }}" href="{{ route('scheduling.index') }}">{{ $navStaff ? '2. Pembimbing & jadwal' : 'Jadwal' }}</a>
    <a class="{{ $navLink('attendance.*') }}" href="{{ route('attendance.index') }}">{{ $navStaff ? '3. Presensi' : 'Presensi' }}</a>
    <a class="{{ $navLink('logbooks.*') }}" href="{{ route('logbooks.index') }}">{{ $navStaff ? '4. Logbook' : 'Logbook' }}</a>
    <a class="{{ $navLink('assessments.*') }}" href="{{ route('assessments.index') }}">{{ $navStaff ? '5. Penilaian' : ($navEducator ? 'Penilaian' : 'Nilai') }}</a>
    @if($navStaff || $navParticipant)<a class="{{ $navLink('completion.*') }}" href="{{ route('completion.index') }}">{{ $navStaff ? '6. Survei & penyelesaian' : 'Survei & penyelesaian' }}</a>@endif

    <p class="nav-heading">Lainnya</p>
    @if($navStaff || $navEducator)<a class="{{ $navLink('reports') }}" href="{{ route('reports') }}">Laporan Excel / PDF</a>@endif
    <a class="{{ $navLink('verification.*') }}" href="{{ route('verification.index') }}">Cek pengesahan (QR)</a>

    @if($navUser->hasPermission('users.view') || $navUser->hasPermission('masters.view') || $navUser->hasPermission('audit-logs.view'))
        <details @if(request()->routeIs('users.*', 'roles.*', 'masters.*', 'audit-logs.*')) open @endif>
            <summary class="nav-heading cursor-pointer">Pengaturan</summary>
            @if($navUser->hasPermission('users.view'))<a class="{{ $navLink('users.*') }}" href="{{ route('users.index') }}">Pengguna</a>@endif
            @if($navUser->hasPermission('roles.manage'))<a class="{{ $navLink('roles.*') }}" href="{{ route('roles.index') }}">Role & hak akses</a>@endif
            @if($navUser->hasPermission('masters.view'))@foreach(config('masters') as $slug => $item)<a class="nav-link {{ request()->route('master') === $slug ? 'nav-link-active' : '' }}" href="{{ route('masters.index', $slug) }}">{{ $item['label'] }}</a>@endforeach @endif
            @if($navUser->hasPermission('audit-logs.view'))<a class="{{ $navLink('audit-logs.*') }}" href="{{ route('audit-logs.index') }}">Audit log</a>@endif
        </details>
    @endif
</nav>
@if($navStaff)<div class="mt-6 rounded-xl bg-slate-50 p-3 text-xs text-slate-500"><p class="font-semibold text-slate-700">Cakupan KSM</p><p class="mt-1">{{ count($navUser->departmentScopeIds()) ? count($navUser->departmentScopeIds()).' KSM ditugaskan' : (array_intersect($navRoles, ['super-admin', 'admin-kordik', 'tim-kordik']) ? 'Semua KSM' : 'Belum ada KSM ditugaskan') }}</p></div>@endif
