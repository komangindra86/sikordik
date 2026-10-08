<x-layouts.app title="Akun saya">
    <h1 class="mb-5 text-2xl font-bold">Akun saya</h1>
    <div class="grid items-start gap-5 xl:grid-cols-2">
        <section class="card"><h2 class="mb-3 text-lg font-bold">Data akun</h2>
            <dl class="space-y-2 text-sm"><div><dt class="text-slate-500">Nama</dt><dd class="font-semibold">{{ $user->name }}</dd></div><div><dt class="text-slate-500">Email untuk masuk</dt><dd class="font-semibold">{{ $user->email }}</dd></div>
                <div><dt class="text-slate-500">Peran</dt><dd class="font-semibold">{{ $roles->implode(', ') ?: '—' }}</dd></div>@if($departments->isNotEmpty())<div><dt class="text-slate-500">KSM</dt><dd class="font-semibold">{{ $departments->implode(', ') }}</dd></div>@endif</dl>
            <p class="mt-4 text-xs text-slate-500">Nama, email, dan peran diubah oleh Admin Kordik.</p>
        </section>
        <section class="card"><h2 class="mb-3 text-lg font-bold">Ganti kata sandi</h2>
            <form class="space-y-4" method="POST" action="{{ route('account.password') }}">@csrf @method('PUT')
                <label class="block">Kata sandi saat ini<input type="password" name="current_password" autocomplete="current-password" required></label>
                <label class="block">Kata sandi baru (minimal 12 karakter)<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
                <label class="block">Ulangi kata sandi baru<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
                <button class="btn-primary">Simpan kata sandi</button>
            </form>
        </section>
    </div>
</x-layouts.app>
