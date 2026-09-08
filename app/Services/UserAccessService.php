<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if (in_array('super-admin', $user->roleCodes(), true)) {
            return true;
        }

        return DB::table('user_scopes')
            ->where('user_id', $user->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $departmentId)
            ->exists();
    }
}
