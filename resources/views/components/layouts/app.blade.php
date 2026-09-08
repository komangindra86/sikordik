<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#087565">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title><link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><div class="min-h-screen lg:flex">
    <aside class="hidden w-72 shrink-0 border-r border-slate-200 bg-white p-5 lg:block">@include('partials.navigation')</aside>
    <div class="min-w-0 flex-1"><header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:px-8"><div class="flex items-center justify-between gap-4">
        <details class="relative lg:hidden"><summary class="btn-secondary cursor-pointer list-none">Menu</summary><div class="absolute left-0 top-12 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">@include('partials.navigation')</div></details>
        <div><p class="text-sm text-slate-500">Selamat datang,</p><p class="font-semibold text-slate-900">{{ auth()->user()->name }}</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-secondary" type="submit">Keluar</button></form>
    </div></header><main class="p-4 lg:p-8">
        @if(session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert"><p class="font-semibold">Periksa kembali data berikut:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main></div>
</div></body></html>
