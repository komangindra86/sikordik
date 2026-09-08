<x-layouts.guest title="Masuk"><form method="POST" action="{{ route('login.store') }}" class="space-y-4">@csrf
    <div><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
    <div><label for="password">Kata sandi</label><input id="password" name="password" type="password" required autocomplete="current-password">@error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
    <label class="flex items-center gap-2 font-normal"><input name="remember" type="checkbox" value="1"> Ingat saya</label><button class="btn-primary w-full" type="submit">Masuk</button><a class="block text-center text-sm font-semibold text-brand-700 hover:underline" href="{{ route('password.request') }}">Lupa kata sandi?</a>
</form></x-layouts.guest>
