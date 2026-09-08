<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'string', 'confirmed', 'min:12']]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) use ($audit) {
            DB::transaction(function () use ($user, $password, $audit) {
                DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'updated_at' => now()]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $audit->log('auth.password_reset', 'user', $user->id, 'Kata sandi pengguna direset.');
            });
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Tautan reset tidak valid atau telah kedaluwarsa.']);
        }

        return redirect()->route('login')->with('status', 'Kata sandi berhasil diubah. Silakan masuk.');
    }
}
