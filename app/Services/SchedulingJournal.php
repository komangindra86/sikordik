<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchedulingJournal
{
    public function record(User $actor, string $table, int $id, string $event, ?object $before, ?string $reason = null): void
    {
        $after = DB::table($table)->find($id);
        DB::table('scheduling_histories')->insert(['resource_type' => $table, 'resource_id' => $id, 'event' => $event,
            'actor_id' => $actor->id, 'reason' => $reason, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(AuditLogger::class)->log('scheduling.'.$event, $table, $id, reason: $reason,
            newValues: ['actor_id' => $actor->id, 'status' => $after->status ?? null, 'revision' => $after->revision ?? null]);
    }

    public function notify(array $users, object $p, string $message): void
    {
        foreach (array_unique(array_filter($users)) as $id) {
            DB::table('scheduling_notifications')->insert(['ulid' => (string) Str::ulid(), 'user_id' => $id,
                'placement_id' => $p->id, 'message' => $message, 'created_at' => now()]);
        }
    }

    public function roleUsers(string $role, ?int $department = null): array
    {
        $q = DB::table('users')->where('is_active', true)->whereIn('id', DB::table('user_roles')->whereIn('role_id', DB::table('roles')->where('code', $role)->select('id'))->select('user_id'));
        if ($department !== null) {
            $q->whereIn('id', DB::table('user_scopes')->where('scope_type', 'department')->where('scope_id', $department)->select('user_id'));
        }

        return $q->pluck('id')->all();
    }
}
