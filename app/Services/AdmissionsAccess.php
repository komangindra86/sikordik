<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdmissionsAccess
{
    public function role(User $user, array $roles): bool
    {
        return $user->is_active && (bool) array_intersect($roles, $user->roleCodes());
    }

    public function admin(User $user): bool
    {
        return $this->role($user, ['admin-kordik', 'super-admin']);
    }

    public function placements(User $user): Builder
    {
        $query = DB::table('placements');
        if ($this->role($user, ['admin-kordik', 'tim-kordik', 'super-admin'])) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereRaw('1 = 0');
            if ($this->role($user, ['ketua-ksm', 'sekretariat-ksm'])) {
                $q->orWhereIn('department_id', $user->departmentScopeIds());
            }
            if ($this->role($user, ['peserta'])) {
                $q->orWhereIn('participant_id', DB::table('participants')->where('user_id', $user->id)->select('id'));
            }
        });
    }

    public function placement(User $user, string $ulid): object
    {
        return $this->placements($user)->where('ulid', $ulid)->firstOrFail();
    }

    public function file(User $user, object $file): bool
    {
        // Explicit document entitlement: Super Admin wildcard is deliberately not used.
        if ($this->role($user, ['admin-kordik', 'tim-kordik'])) {
            return in_array($file->resource_type, ['letter', 'placement', 'participant'], true)
                && in_array($file->category, ['surat', 'foto', 'ijazah', 'bhd', 'sip', 'str', 'kompetensi', 'pendukung', 'administrasi'], true);
        }
        if ($file->resource_type !== 'placement') {
            return false;
        }
        $placement = DB::table('placements')->where('id', $file->resource_id)->first();
        if (! $placement) {
            return false;
        }
        if ($this->role($user, ['ketua-ksm', 'sekretariat-ksm']) && in_array((int) $placement->department_id, $user->departmentScopeIds(), true)) {
            return in_array($file->category, ['bhd', 'sip', 'str', 'kompetensi', 'pendukung'], true);
        }

        return $this->role($user, ['peserta']) && $file->participant_visible
            && in_array($file->category, ['ijazah', 'bhd', 'sip', 'str', 'kompetensi', 'administrasi'], true)
            && DB::table('participants')->where('id', $placement->participant_id)->where('user_id', $user->id)->exists();
    }
}
