<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $definition = config('masters.'.$this->route('master'));
        abort_unless($definition, 404);

        $rules = $definition['rules'];
        $id = $this->route('id');

        foreach ($definition['unique'] ?? [] as $column) {
            $rules[$column][] = Rule::unique($definition['table'], $column)->ignore($id);
        }

        if ($columns = $definition['unique_composite'] ?? null) {
            $primaryColumn = array_pop($columns);
            $rules[$primaryColumn][] = Rule::unique($definition['table'], $primaryColumn)
                ->ignore($id)
                ->where(fn ($query) => collect($columns)->each(fn ($column) => $query->where($column, $this->input($column))));
        }

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['change_reason'] = ['required', 'string', 'min:5', 'max:1000'];
        }

        return $rules;
    }
}
