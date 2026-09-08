<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $users = DB::table('users')->whereNull('deleted_at')
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(15)->withQueryString();
        $rolesByUser = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_id', $users->pluck('id'))->orderBy('roles.name')->get(['user_id', 'roles.name'])->groupBy('user_id');

        return view('users.index', compact('users', 'rolesByUser', 'search'));
    }

    public function create(): View
    {
        return view('users.form', $this->formData());
    }

    public function store(UserRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $this->validateRoleAssignment($data['role_ids'], $data['department_ids'] ?? []);

        $userId = DB::transaction(function () use ($data, $audit) {
            $userId = DB::table('users')->insertGetId([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null, 'password' => Hash::make($data['password']),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->syncAccess($userId, $data['role_ids'], $data['department_ids'] ?? []);
            $audit->log('user.created', 'user', $userId, 'Akun pengguna dibuat.', null, [], ['name' => $data['name'], 'email' => $data['email'], 'role_ids' => $data['role_ids'], 'department_ids' => $data['department_ids'] ?? []]);

            return $userId;
        });

        return redirect()->route('users.edit', $userId)->with('status', 'Pengguna berhasil dibuat.');
    }

    public function edit(int $user): View
    {
        $record = DB::table('users')->where('id', $user)->whereNull('deleted_at')->first();
        abort_unless($record, 404);

        return view('users.form', array_merge($this->formData(), [
            'record' => $record,
            'selectedRoles' => DB::table('user_roles')->where('user_id', $user)->pluck('role_id')->map(fn ($id) => (int) $id)->all(),
            'selectedDepartments' => DB::table('user_scopes')->where('user_id', $user)->where('scope_type', 'department')->pluck('scope_id')->map(fn ($id) => (int) $id)->all(),
        ]));
    }

    public function update(UserRequest $request, int $user, AuditLogger $audit): RedirectResponse
    {
        $old = (array) DB::table('users')->where('id', $user)->whereNull('deleted_at')->first();
        abort_unless($old, 404);
        $data = $request->validated();
        $this->validateRoleAssignment($data['role_ids'], $data['department_ids'] ?? []);

        DB::transaction(function () use ($data, $user, $old, $audit) {
            $updates = ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'job_title' => $data['job_title'] ?? null, 'updated_at' => now()];
            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
            }
            $oldRoles = DB::table('user_roles')->where('user_id', $user)->pluck('role_id')->all();
            $oldScopes = DB::table('user_scopes')->where('user_id', $user)->where('scope_type', 'department')->pluck('scope_id')->all();
            DB::table('users')->where('id', $user)->update($updates);
            $this->syncAccess($user, $data['role_ids'], $data['department_ids'] ?? []);
            $audit->log('user.updated', 'user', $user, 'Data dan akses pengguna diperbarui.', $data['change_reason'], array_merge($old, ['role_ids' => $oldRoles, 'department_ids' => $oldScopes]), array_merge($updates, ['role_ids' => $data['role_ids'], 'department_ids' => $data['department_ids'] ?? []]));
        });

        return back()->with('status', 'Pengguna berhasil diperbarui.');
    }

    public function status(Request $request, int $user, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'change_reason' => ['required', 'string', 'min:5', 'max:1000']]);
        abort_if($request->user()->id === $user && ! $request->boolean('is_active'), 422, 'Anda tidak dapat menonaktifkan akun sendiri.');
        $old = DB::table('users')->where('id', $user)->whereNull('deleted_at')->value('is_active');
        abort_if($old === null, 404);

        DB::transaction(function () use ($user, $data, $old, $audit) {
            DB::table('users')->where('id', $user)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
            if (! $data['is_active']) {
                DB::table('sessions')->where('user_id', $user)->delete();
            }
            $audit->log('user.status_changed', 'user', $user, 'Status akun pengguna diubah.', $data['change_reason'], ['is_active' => (bool) $old], ['is_active' => (bool) $data['is_active']]);
        });

        return back()->with('status', 'Status pengguna berhasil diperbarui.');
    }

    private function formData(): array
    {
        return [
            'roles' => DB::table('roles')->orderBy('name')->get(),
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('name')->get(),
            'record' => null, 'selectedRoles' => [], 'selectedDepartments' => [],
        ];
    }

    private function validateRoleAssignment(array $roleIds, array $departmentIds): void
    {
        $roleCodes = DB::table('roles')->whereIn('id', $roleIds)->pluck('code')->all();
        if (in_array('super-admin', $roleCodes, true) && ! in_array('super-admin', request()->user()->roleCodes(), true)) {
            throw ValidationException::withMessages(['role_ids' => 'Hanya Super Admin yang dapat menetapkan role Super Admin.']);
        }
        $scopedRoles = ['sekretariat-ksm', 'ketua-ksm'];
        if (array_intersect($scopedRoles, $roleCodes) && $departmentIds === []) {
            throw ValidationException::withMessages(['department_ids' => 'Minimal satu scope KSM wajib dipilih untuk role berbasis KSM.']);
        }
    }

    private function syncAccess(int $userId, array $roleIds, array $departmentIds): void
    {
        DB::table('user_roles')->where('user_id', $userId)->delete();
        DB::table('user_roles')->insert(collect($roleIds)->map(fn ($roleId) => ['user_id' => $userId, 'role_id' => $roleId, 'assigned_by' => auth()->id(), 'assigned_at' => now()])->all());
        DB::table('user_scopes')->where('user_id', $userId)->where('scope_type', 'department')->delete();
        if ($departmentIds !== []) {
            DB::table('user_scopes')->insert(collect($departmentIds)->map(fn ($departmentId) => ['user_id' => $userId, 'scope_type' => 'department', 'scope_id' => $departmentId, 'assigned_by' => auth()->id(), 'assigned_at' => now()])->all());
        }
    }
}
