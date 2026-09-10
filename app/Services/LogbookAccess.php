<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LogbookAccess extends SchedulingAccess
{
    public function assignments(User $u, string $role): Builder
    {
        return DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')
            ->where('a.status', 'approved')->where('a.role', $role)->where('a.educator_user_id', $u->id)
            ->where('e.user_id', $u->id)->where('e.is_active', true)
            ->whereRaw($this->role($u, [$role === 'mentor' ? 'pembimbing' : 'supervisor']) ? '1=1' : '1=0');
    }

    public function placements(User $u): Builder
    {
        return DB::table('placements')->where(function ($q) use ($u) {
            $q->whereIn('id', app(AdmissionsAccess::class)->placements($u)->select('id'))
                ->orWhereIn('id', $this->assignments($u, 'mentor')->select('a.placement_id'))
                ->orWhereIn('id', $this->assignments($u, 'supervisor')->select('a.placement_id'));
        });
    }

    public function rows(User $u, object $p): Builder
    {
        $q = DB::table('logbooks')->where('placement_id', $p->id);
        if ($this->role($u, ['super-admin', 'admin-kordik', 'tim-kordik']) || $this->manage($u, $p->department_id) || $this->chief($u, $p->department_id)) {
            return $q;
        }

        return $q->where(function ($q) use ($u, $p) {
            $q->whereRaw('1=0');
            if ($this->owner($u, $p)) {
                $q->orWhere(fn ($q) => $q->where('kind', 'participant')->where('author_id', $u->id));
            }
            $q->orWhere(fn ($q) => $q->where('kind', 'educator')->where('author_id', $u->id)
                ->whereIn('author_assignment_id', $this->assignments($u, 'mentor')->select('a.id')))
                ->orWhereIn('logbooks.reviewer_assignment_id', $this->assignments($u, 'mentor')->select('a.id'))
                ->orWhereIn('logbooks.reviewer_assignment_id', $this->assignments($u, 'supervisor')->select('a.id'));
        });
    }

    public function author(User $u, object $p, object $r): bool
    {
        return (int) $r->author_id === (int) $u->id && ($r->kind === 'participant' ? $this->owner($u, $p) :
            $this->assignments($u, 'mentor')->where('a.id', $r->author_assignment_id)->exists());
    }

    public function reviewer(User $u, object $r): bool
    {
        return (int) $r->author_id !== (int) $u->id && $this->assignments($u, $r->kind === 'participant' ? 'mentor' : 'supervisor')
            ->where('a.id', $r->reviewer_assignment_id)->exists();
    }
}
