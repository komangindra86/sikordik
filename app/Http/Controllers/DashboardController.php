<?php

namespace App\Http\Controllers;

use App\Services\UserAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $access = app(UserAccessService::class);
        $stats = $access->hasGlobalScope(auth()->user()) ? [
            ['label' => 'Pengguna aktif', 'value' => DB::table('users')->where('is_active', true)->whereNull('deleted_at')->count(), 'hint' => 'Akun yang dapat masuk'],
            ['label' => 'Institusi', 'value' => DB::table('institutions')->where('is_active', true)->count(), 'hint' => 'Institusi pendidikan aktif'],
            ['label' => 'KSM', 'value' => DB::table('departments')->where('is_active', true)->count(), 'hint' => 'Kelompok staf medis'],
            ['label' => 'Tenaga pendidik', 'value' => DB::table('educators')->where('is_active', true)->count(), 'hint' => 'Pembimbing, penguji, supervisor'],
        ] : [
            ['label' => 'KSM dalam cakupan', 'value' => $access->scopeDepartments(DB::table('departments')->where('is_active', true), auth()->user(), 'id')->count(), 'hint' => 'KSM yang ditugaskan kepada Anda'],
        ];

        return view('dashboard', compact('stats'));
    }
}
