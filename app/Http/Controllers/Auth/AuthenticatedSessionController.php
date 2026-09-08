<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        $credentials = ['email' => $request->validated('email'), 'password' => $request->validated('password'), 'is_active' => true];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($request->throttleKey(), 60);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }

        RateLimiter::clear($request->throttleKey());
        $request->session()->regenerate();
        DB::table('users')->where('id', Auth::id())->update(['last_login_at' => now(), 'updated_at' => now()]);
        $audit->log('auth.login', 'user', Auth::id(), 'Pengguna berhasil masuk.');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->log('auth.logout', 'user', Auth::id(), 'Pengguna keluar.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
