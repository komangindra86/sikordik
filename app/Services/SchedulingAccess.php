<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SchedulingAccess
{
    public function role(User $u, array $roles): bool
    {
        return app(AdmissionsAccess::class)->role($u, $roles);
    }

    public function admin(User $u): bool
    {
        return $this->role($u, ['admin-kordik']);
    }

    public function chief(User $u, int $department): bool
    {
        return $this->role($u, ['ketua-ksm']) && in_array($department, $u->departmentScopeIds(), true);
    }

    public function manage(User $u, int $department): bool
    {
        return $this->admin($u) || ($this->role($u, ['sekretariat-ksm']) && in_array($department, $u->departmentScopeIds(), true));
    }

    public function owner(User $u, object $p): bool
    {
        return $this->role($u, ['peserta']) && DB::table('participants')->where('id', $p->participant_id)->where('user_id', $u->id)->exists();
    }

    public function placements(User $u): Builder
    {
        $q = DB::table('placements');
        if ($this->role($u, ['admin-kordik', 'tim-kordik', 'super-admin'])) {
            return $q;
        }

        return $q->where(function ($q) use ($u) {
            $q->whereRaw('1=0');
            if ($this->role($u, ['ketua-ksm', 'sekretariat-ksm'])) {
                $q->orWhereIn('department_id', $u->departmentScopeIds());
            }
            if ($this->role($u, ['peserta'])) {
                $q->orWhereIn('participant_id', DB::table('participants')->where('user_id', $u->id)->select('id'));
            }
            if ($this->role($u, ['pembimbing', 'supervisor'])) {
                $q->orWhereIn('id', DB::table('educator_assignments')->where('status', 'approved')
                    ->where('educator_user_id', $u->id)
                    ->where('end_date', '>=', now()->toDateString())
                    ->whereIn('educator_id', DB::table('educators')->where('user_id', $u->id)->where('is_active', true)->select('id'))->select('placement_id'));
            }
        });
    }

    public function placement(User $u, string $ulid): object
    {
        return $this->placements($u)->where('ulid', $ulid)->firstOrFail();
    }

    public function mentor(User $u, object $s): bool
    {
        return $this->role($u, ['pembimbing']) && DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')
            ->where('a.id', $s->mentor_assignment_id)->where('a.role', 'mentor')->where('a.status', 'approved')
            ->where('a.educator_user_id', $u->id)
            ->where('a.start_date', '<=', $s->date)->where('a.end_date', '>=', $s->date)
            ->where('e.user_id', $u->id)->where('e.is_active', true)->exists();
    }
}
