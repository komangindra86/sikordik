<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $roles = DB::table('roles')->whereIn('code', $user->roleCodes())->orderBy('name')->pluck('name');
        $departments = DB::table('departments')->whereIn('id', $user->departmentScopeIds())->orderBy('name')->pluck('name');

        return view('account', compact('user', 'roles', 'departments'));
    }

    public function password(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:12|confirmed|different:current_password'],
            ['password.different' => 'Kata sandi baru harus berbeda dari yang lama.']);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini tidak cocok.']);
        }
        DB::transaction(function () use ($request, $user, $data, $audit) {
            DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60), 'updated_at' => now()]);
            // Sign out every other device; only the session that knew the old password stays.
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            $audit->log('auth.password_changed', 'user', $user->id, 'Pengguna mengganti kata sandinya sendiri.');
        });
        $request->session()->regenerate();

        return back()->with('status', 'Kata sandi diganti. Perangkat lain yang masih masuk sudah dikeluarkan.');
    }
}
