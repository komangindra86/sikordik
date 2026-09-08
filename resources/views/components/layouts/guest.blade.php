<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#087565">
    <title>{{ $title ?? 'Masuk' }} · {{ config('app.name') }}</title><link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-900 via-brand-700 to-brand-500 p-4">
    <section class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl sm:p-8">
        <div class="mb-7 text-center"><div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-xl font-bold text-white">SK</div><p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-600">RSBM</p><h1 class="mt-2 text-2xl font-bold text-slate-900">SIKORDIK</h1><p class="mt-1 text-sm text-slate-500">Sistem Informasi Manajemen Pendidikan Klinis</p></div>
        @if(session('status'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </section>
</main></body></html>
