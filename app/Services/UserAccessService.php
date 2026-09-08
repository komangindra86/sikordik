<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    public function hasGlobalScope(User $user): bool
    {
        return $user->is_active && (bool) array_intersect(
            ['super-admin', 'admin-kordik', 'tim-kordik'], $user->roleCodes()
        );
    }

    public function scopeDepartments(Builder $query, User $user, string $column = 'department_id'): Builder
    {
        if ($this->hasGlobalScope($user)) {
            return $query;
        }

        return $query->whereIn($column, $user->is_active ? $user->departmentScopeIds() : []);
    }

    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if (! $user->is_active || ! DB::table('departments')->where('id', $departmentId)->exists()) {
            return false;
        }
        if ($this->hasGlobalScope($user)) {
            return true;
        }

        return DB::table('user_scopes')
            ->where('user_id', $user->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $departmentId)
            ->exists();
    }
}
