<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AssessmentAccess extends SchedulingAccess
{
    public function assignments(User $u): Builder
    {
        return DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')
            ->where('a.status', 'approved')->whereIn('a.role', ['mentor', 'examiner'])->where('a.educator_user_id', $u->id)
            ->where('e.user_id', $u->id)->where('e.is_active', true)
            ->whereRaw($this->role($u, ['pembimbing']) ? '1=1' : '1=0');
    }

    public function placements(User $u): Builder
    {
        return DB::table('placements')->where(fn ($q) => $q
            ->whereIn('id', app(AdmissionsAccess::class)->placements($u)->select('id'))
            ->orWhereIn('id', $this->assignments($u)->select('a.placement_id')));
    }

    public function monitor(User $u, object $p): bool
    {
        return $this->role($u, ['admin-kordik', 'tim-kordik', 'super-admin']) || $this->chief($u, $p->department_id) || $this->manage($u, $p->department_id);
    }

    public function mentor(User $u, object $r): bool
    {
        return $this->assignments($u)->where('a.id', $r->mentor_assignment_id)->where('a.role', 'mentor')->exists();
    }

    public function author(User $u, object $r): bool
    {
        return $this->assignments($u)->where('a.id', $r->author_assignment_id)->exists();
    }

    public function rows(User $u, object $p): Builder
    {
        $q = DB::table('assessments')->where('placement_id', $p->id);
        if ($this->owner($u, $p)) {
            return $q->whereNotNull('published_version');
        }
        if ($this->monitor($u, $p)) {
            return $q;
        }

        return $q->where(fn ($q) => $q->whereIn('author_assignment_id', $this->assignments($u)->select('a.id'))
            ->orWhereIn('mentor_assignment_id', $this->assignments($u)->where('a.role', 'mentor')->select('a.id')));
    }
}
