<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtolower(trim((string) $this->code))]);
    }

    public function rules(): array
    {
        $roleId = $this->route('role');
        $creating = $this->isMethod('post');

        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'code')->ignore($roleId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'change_reason' => [$creating ? 'nullable' : 'required', 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
