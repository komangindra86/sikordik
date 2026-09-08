<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterDataRequest;
use App\Services\AuditLogger;
use App\Services\UserAccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function index(Request $request, string $master): View
    {
        $definition = $this->definition($master);
        $search = trim((string) $request->query('q'));
        $records = $this->query($definition['table'])
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('masters.index', compact('master', 'definition', 'records', 'search'));
    }

    public function create(string $master): View
    {
        $definition = $this->definition($master);

        return view('masters.form', ['master' => $master, 'definition' => $definition, 'record' => null, 'options' => $this->options($definition)]);
    }

    public function store(MasterDataRequest $request, string $master, AuditLogger $audit): RedirectResponse
    {
        $definition = $this->definition($master);
        abort_if($definition['table'] === 'departments' && ! app(UserAccessService::class)->hasGlobalScope($request->user()), 403);
        $data = $this->data($request, $definition);
        $id = DB::transaction(function () use ($definition, $data, $audit) {
            $id = DB::table($definition['table'])->insertGetId(array_merge($data, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]));
            $audit->log('master.created', $definition['table'], $id, $definition['label'].' dibuat.', null, [], $data);

            return $id;
        });

        return redirect()->route('masters.edit', [$master, $id])->with('status', $definition['label'].' berhasil dibuat.');
    }

    public function edit(string $master, int $id): View
    {
        $definition = $this->definition($master);
        $record = $this->query($definition['table'])->where('id', $id)->first();
        abort_unless($record, 404);

        return view('masters.form', ['master' => $master, 'definition' => $definition, 'record' => $record, 'options' => $this->options($definition)]);
    }

    public function update(MasterDataRequest $request, string $master, int $id, AuditLogger $audit): RedirectResponse
    {
        $definition = $this->definition($master);
        $old = (array) $this->query($definition['table'])->where('id', $id)->first();
        abort_unless($old, 404);
        $data = $this->data($request, $definition);
        DB::transaction(function () use ($definition, $data, $id, $old, $request, $audit) {
            DB::table($definition['table'])->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
            $audit->log('master.updated', $definition['table'], $id, $definition['label'].' diperbarui.', $request->validated('change_reason'), $old, $data);
        });

        return back()->with('status', $definition['label'].' berhasil diperbarui.');
    }

    public function status(Request $request, string $master, int $id, AuditLogger $audit): RedirectResponse
    {
        $definition = $this->definition($master);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'change_reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $old = $this->query($definition['table'])->where('id', $id)->value('is_active');
        abort_if($old === null, 404);
        DB::transaction(function () use ($definition, $data, $id, $old, $audit) {
            DB::table($definition['table'])->where('id', $id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
            $audit->log('master.status_changed', $definition['table'], $id, 'Status '.$definition['label'].' diubah.', $data['change_reason'], ['is_active' => (bool) $old], ['is_active' => (bool) $data['is_active']]);
        });

        return back()->with('status', 'Status data berhasil diperbarui.');
    }

    private function definition(string $master): array
    {
        $definition = config('masters.'.$master);
        abort_unless($definition, 404);

        return $definition;
    }

    private function data(MasterDataRequest $request, array $definition): array
    {
        $data = collect($request->validated())->only(array_keys($definition['fields']))->all();
        foreach ($definition['boolean_fields'] ?? [] as $field) {
            $data[$field] = $request->boolean($field);
        }

        if (in_array($definition['table'], ['clinical_locations', 'educators'], true) && ! app(UserAccessService::class)->hasGlobalScope($request->user())) {
            abort_unless(app(UserAccessService::class)->canAccessDepartment($request->user(), (int) ($data['department_id'] ?? 0)), 403);
        }
        if (! empty($data['user_id'])) {
            abort_unless($this->query('users')->where('id', $data['user_id'])->where('is_active', true)->exists(), 403);
        }

        return $data;
    }

    private function options(array $definition): array
    {
        $options = [];
        foreach ($definition['fields'] as $name => $field) {
            if (($field['type'] ?? null) === 'select') {
                $options[$name] = $this->query($field['options']['table'])->where('is_active', true)->orderBy('name')->get(['id', 'name']);
            }
        }

        return $options;
    }

    private function query(string $table): Builder
    {
        $query = DB::table($table);
        if (in_array($table, ['departments', 'clinical_locations', 'educators'], true)) {
            return app(UserAccessService::class)->scopeDepartments($query, auth()->user(), $table === 'departments' ? 'id' : 'department_id');
        }
        if ($table === 'users') {
            $query->whereNull('deleted_at');
            if (! app(UserAccessService::class)->hasGlobalScope(auth()->user())) {
                $query->where('id', auth()->id());
            }
        }

        return $query;
    }
}
