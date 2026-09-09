<div class="mb-6 flex items-center gap-3"><div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 font-bold text-white">SK</div><div><p class="font-bold text-slate-900">SIKORDIK</p><p class="text-xs text-slate-500">Pendidikan Klinis RSBM</p></div></div>
<nav class="space-y-1">
    <a class="nav-link {{ request()->routeIs('scheduling.*') ? 'nav-link-active' : '' }}" href="{{ route('scheduling.index') }}">Penugasan & jadwal</a>
    <a class="nav-link" href="{{ route('scheduling.notifications') }}">Notifikasi</a>
    <a class="nav-link {{ request()->routeIs('admissions.*') ? 'nav-link-active' : '' }}" href="{{ route('admissions.index') }}">Penerimaan & penempatan</a>
    @if(auth()->user()->hasPermission('dashboard.view'))<a class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>@endif
    @if(auth()->user()->hasPermission('users.view'))<a class="nav-link {{ request()->routeIs('users.*') ? 'nav-link-active' : '' }}" href="{{ route('users.index') }}">Manajemen pengguna</a>@endif
    @if(auth()->user()->hasPermission('roles.manage'))<a class="nav-link {{ request()->routeIs('roles.*') ? 'nav-link-active' : '' }}" href="{{ route('roles.index') }}">Role & permission</a>@endif
    @if(auth()->user()->hasPermission('masters.view'))<p class="px-3 pb-1 pt-4 text-xs font-bold uppercase tracking-wider text-slate-400">Data master</p>@foreach(config('masters') as $slug => $item)<a class="nav-link {{ request()->route('master') === $slug ? 'nav-link-active' : '' }}" href="{{ route('masters.index', $slug) }}">{{ $item['label'] }}</a>@endforeach @endif
    @if(auth()->user()->hasPermission('audit-logs.view'))<a class="nav-link {{ request()->routeIs('audit-logs.*') ? 'nav-link-active' : '' }}" href="{{ route('audit-logs.index') }}">Audit log</a>@endif
</nav>
<div class="mt-6 rounded-xl bg-slate-50 p-3 text-xs text-slate-500"><p class="font-semibold text-slate-700">Cakupan KSM</p><p class="mt-1">{{ count(auth()->user()->departmentScopeIds()) ? count(auth()->user()->departmentScopeIds()).' KSM ditugaskan' : 'Akses global / belum ditetapkan' }}</p></div>
