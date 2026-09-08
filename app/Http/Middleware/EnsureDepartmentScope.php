<?php

namespace App\Http\Middleware;

use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDepartmentScope
{
    public function __construct(private readonly UserAccessService $access) {}

    public function handle(Request $request, Closure $next, string $parameter = 'department'): Response
    {
        $departmentId = (int) $request->route($parameter);
        abort_unless($departmentId > 0 && $this->access->canAccessDepartment($request->user(), $departmentId), 403, 'Data KSM berada di luar cakupan akses Anda.');

        return $next($request);
    }
}
