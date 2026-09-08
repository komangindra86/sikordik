<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = DB::table('roles')->orderByDesc('is_system')->orderBy('name')->get();
        $permissionCounts = DB::table('role_permissions')->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');
        $userCounts = DB::table('user_roles')->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return view('roles.index', compact('roles', 'permissionCounts', 'userCounts'));
    }

    public function create(): View
    {
        return view('roles.form', $this->formData());
    }

    public function store(RoleRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $roleId = DB::transaction(function () use ($data, $audit) {
            $roleId = DB::table('roles')->insertGetId(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
            $this->syncPermissions($roleId, $data['permission_ids']);
            $audit->log('role.created', 'role', $roleId, 'Role kustom dibuat.', null, [], $data);

            return $roleId;
        });

        return redirect()->route('roles.edit', $roleId)->with('status', 'Role berhasil dibuat.');
    }

    public function edit(int $role): View
    {
        $record = DB::table('roles')->where('id', $role)->first();
        abort_unless($record, 404);
        abort_if($record->code === 'super-admin', 403, 'Role Super Admin memiliki izin penuh implisit dan tidak dapat diubah.');

        return view('roles.form', array_merge($this->formData(), ['record' => $record, 'selectedPermissions' => DB::table('role_permissions')->where('role_id', $role)->pluck('permission_id')->map(fn ($id) => (int) $id)->all()]));
    }

    public function update(RoleRequest $request, int $role, AuditLogger $audit): RedirectResponse
    {
        $old = (array) DB::table('roles')->where('id', $role)->first();
        abort_unless($old, 404);
        abort_if($old['code'] === 'super-admin', 403, 'Role Super Admin tidak dapat diubah.');
        $data = $request->validated();
        if ($old['is_system']) {
            $data['code'] = $old['code'];
        }

        DB::transaction(function () use ($role, $old, $data, $audit) {
            $oldPermissions = DB::table('role_permissions')->where('role_id', $role)->pluck('permission_id')->all();
            DB::table('roles')->where('id', $role)->update(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'updated_at' => now()]);
            $this->syncPermissions($role, $data['permission_ids']);
            $audit->log('role.updated', 'role', $role, 'Role dan permission diperbarui.', $data['change_reason'], array_merge($old, ['permission_ids' => $oldPermissions]), $data);
        });

        return back()->with('status', 'Role dan permission berhasil diperbarui.');
    }

    private function formData(): array
    {
        return ['permissions' => DB::table('permissions')->orderBy('module')->orderBy('name')->get()->groupBy('module'), 'record' => null, 'selectedPermissions' => []];
    }

    private function syncPermissions(int $roleId, array $permissionIds): void
    {
        if (! in_array('super-admin', auth()->user()->roleCodes(), true)) {
            $existing = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
            $permissions = DB::table('permissions')->whereIn('id', array_merge($existing, $permissionIds))->pluck('code');
            foreach ($permissions as $permission) {
                if (! auth()->user()->hasPermission($permission)) {
                    throw ValidationException::withMessages(['permission_ids' => 'Tidak boleh mengelola izin melebihi kewenangan Anda.']);
                }
            }
        }
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        DB::table('role_permissions')->insert(collect($permissionIds)->map(fn ($permissionId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all());
    }
}
