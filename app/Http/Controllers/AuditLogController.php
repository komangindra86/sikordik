<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $event = trim((string) $request->query('event'));
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->when($event, fn ($query) => $query->where('audit_logs.event', 'like', "%{$event}%"))
            ->orderByDesc('audit_logs.id')
            ->paginate(20, ['audit_logs.*', 'users.name as user_name'])->withQueryString();

        return view('audit-logs.index', compact('logs', 'event'));
    }
}
