<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'job_title',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $this->id)
            ->where(function ($query) use ($permission) {
                $query->where('r.code', 'super-admin')->orWhere('p.code', $permission);
            })
            ->exists();
    }

    public function roleCodes(): array
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $this->id)
            ->orderBy('roles.name')
            ->pluck('roles.code')
            ->all();
    }

    public function departmentScopeIds(): array
    {
        return DB::table('user_scopes')
            ->where('user_id', $this->id)
            ->where('scope_type', 'department')
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
