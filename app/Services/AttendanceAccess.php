<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AttendanceAccess extends SchedulingAccess
{
    public function placements(User $u): Builder
    {
        return DB::table('placements')->where(function ($q) use ($u) {
            $q->whereIn('id', parent::placements($u)->select('placements.id'));
            if ($this->role($u, ['pembimbing'])) {
                $q->orWhereIn('id', DB::table('educator_assignments as a')->join('educators as e', 'e.id', '=', 'a.educator_id')
                    ->where('a.educator_user_id', $u->id)->where('e.user_id', $u->id)->where('e.is_active', true)
                    ->where('a.role', 'mentor')->where('a.status', 'approved')->select('a.placement_id'));
            }
        });
    }
}
