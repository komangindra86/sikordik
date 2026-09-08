<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token',
        'remember_token', 'authorization', 'cookie', 'file', 'contents',
    ];

    public function log(
        string $event,
        ?string $auditableType = null,
        string|int|null $auditableId = null,
        ?string $description = null,
        ?string $reason = null,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'description' => $description,
            'reason' => $reason,
            'old_values' => $oldValues === [] ? null : json_encode($this->sanitize($oldValues), JSON_THROW_ON_ERROR),
            'new_values' => $newValues === [] ? null : json_encode($this->sanitize($newValues), JSON_THROW_ON_ERROR),
            'ip_address' => request()?->ip(),
            'user_agent' => mb_substr((string) request()?->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    private function sanitize(array $values): array
    {
        $filtered = Arr::except($values, self::SENSITIVE_KEYS);

        return collect($filtered)->map(function ($value, $key) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                return '[DISARING]';
            }

            return is_array($value) ? $this->sanitize($value) : $value;
        })->all();
    }
}
