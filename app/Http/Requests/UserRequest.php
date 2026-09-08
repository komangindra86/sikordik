<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        $userId = $this->route('user');
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:12', 'confirmed'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
            'change_reason' => [$creating ? 'nullable' : 'required', 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
