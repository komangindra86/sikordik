<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $stats = [
            ['label' => 'Pengguna aktif', 'value' => DB::table('users')->where('is_active', true)->whereNull('deleted_at')->count(), 'hint' => 'Akun yang dapat masuk'],
            ['label' => 'Institusi', 'value' => DB::table('institutions')->where('is_active', true)->count(), 'hint' => 'Institusi pendidikan aktif'],
            ['label' => 'KSM', 'value' => DB::table('departments')->where('is_active', true)->count(), 'hint' => 'Kelompok staf medis'],
            ['label' => 'Tenaga pendidik', 'value' => DB::table('educators')->where('is_active', true)->count(), 'hint' => 'Pembimbing, penguji, supervisor'],
        ];

        return view('dashboard', compact('stats'));
    }
}
