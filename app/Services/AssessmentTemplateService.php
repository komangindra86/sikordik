<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssessmentTemplateService
{
    public function available(object $p, ?string $date = null)
    {
        $q = DB::table('assessment_templates')->where('is_active', true);
        foreach (['institution_id', 'study_program_id', 'participant_type_id', 'department_id'] as $key) {
            $q->where(fn ($q) => $q->whereNull($key)->orWhere($key, $p->$key));
        }
        $date ??= $p->start_date;

        return $q->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    public function create(User $u, array $input): int
    {
        abort_unless(app(AdmissionsAccess::class)->admin($u), 403);
        // The form includes blank rows to add optional components without JavaScript.
        Validator::make($input, ['components' => 'required|array|max:30', 'components.*' => 'required|array', 'components.*.name' => 'nullable|string|max:150'])->validate();
        $input['components'] = array_values(array_filter($input['components'], fn ($c) => trim($c['name'] ?? '') !== ''));
        $d = Validator::make($input, [
            'name' => 'required|string|max:150', 'exam_type' => 'required|string|max:100',
            'institution_id' => 'nullable|integer|exists:institutions,id,is_active,1',
            'study_program_id' => 'nullable|integer|exists:study_programs,id,is_active,1',
            'participant_type_id' => 'nullable|integer|exists:participant_types,id,is_active,1',
            'department_id' => 'nullable|integer|exists:departments,id,is_active,1',
            'start_date' => 'nullable|required_with:end_date|date_format:Y-m-d',
            'end_date' => 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
            'calculation' => 'required|in:none,weighted', 'pass_mark' => 'nullable|numeric|decimal:0,2|between:0,100',
            'components' => 'required|array|min:1|max:30', 'components.*' => 'array:name,input_type,description,minimum,maximum,weight,pass_mark,notes,required', 'components.*.name' => 'required|string|max:150|distinct',
            'components.*.input_type' => 'required|in:number,text', 'components.*.description' => 'nullable|string|max:1000',
            'components.*.minimum' => 'nullable|numeric|decimal:0,2|between:-999999,999999', 'components.*.maximum' => 'nullable|numeric|decimal:0,2|between:-999999,999999',
            'components.*.weight' => 'nullable|numeric|decimal:0,2|between:0,100', 'components.*.pass_mark' => 'nullable|numeric|decimal:0,2|between:-999999,999999',
            'components.*.notes' => 'nullable|string|max:1000', 'components.*.required' => 'required|boolean',
        ])->validate();
        if (! empty($d['study_program_id']) && ! empty($d['institution_id'])) {
            abort_unless(DB::table('study_programs')->where('id', $d['study_program_id'])->where('institution_id', $d['institution_id'])->exists(), 422, 'Program studi tidak sesuai institusi.');
        }
        foreach ($d['components'] as $c) {
            if ($c['input_type'] === 'number') {
                abort_unless(isset($c['minimum'], $c['maximum']) && $c['minimum'] < $c['maximum'], 422, 'Komponen angka membutuhkan rentang minimum < maksimum.');
                abort_if(isset($c['pass_mark']) && ($c['pass_mark'] < $c['minimum'] || $c['pass_mark'] > $c['maximum']), 422, 'Batas lulus di luar rentang komponen.');
            } else {
                abort_if(isset($c['minimum']) || isset($c['maximum']) || isset($c['weight']) || isset($c['pass_mark']), 422, 'Komponen teks tidak memakai rentang, bobot, atau batas lulus.');
            }
            if ($d['calculation'] === 'weighted' && $c['input_type'] === 'number') {
                abort_unless($c['required'] && isset($c['weight']) && $c['weight'] > 0, 422, 'Komponen angka berbobot wajib diisi dan memiliki bobot positif.');
            }
        }
        abort_if($d['calculation'] === 'weighted' && abs(array_sum(array_column($d['components'], 'weight')) - 100) > 0.001, 422, 'Jumlah bobot harus 100.');
        abort_if($d['calculation'] === 'none' && isset($d['pass_mark']), 422, 'Batas lulus total hanya berlaku untuk perhitungan berbobot.');

        return DB::transaction(function () use ($u, $d) {
            $components = $d['components'];
            unset($d['components']);
            $id = DB::table('assessment_templates')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($components as $i => $c) {
                DB::table('assessment_components')->insert($c + ['assessment_template_id' => $id, 'position' => $i + 1]);
            }
            app(AuditLogger::class)->log('assessment.template_created', 'assessment_template', $id);

            return $id;
        });
    }

    public function disable(User $u, int $id): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($u), 403);
        DB::transaction(function () use ($id) {
            DB::table('assessment_templates')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('assessment_templates')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            app(AuditLogger::class)->log('assessment.template_disabled', 'assessment_template', $id);
        });
    }

    public function scores(object $template, array $components, array $input): array
    {
        $scores = [];
        $total = 0;
        abort_if(array_diff(array_keys($input), array_column($components, 'id')), 422, 'Komponen nilai tidak dikenal.');
        foreach ($components as $c) {
            $rule = $c['required'] ? 'required' : 'nullable';
            $rule .= $c['input_type'] === 'number' ? '|numeric|decimal:0,2|between:'.$c['minimum'].','.$c['maximum'] : '|string|max:2000';
            $value = Validator::make(['value' => $input[$c['id']] ?? null], ['value' => $rule], ['value.required' => 'Nilai '.$c['name'].' wajib diisi.'])->validate()['value'];
            $value = $value !== null && $c['input_type'] === 'number' ? (float) $value : $value;
            $scores[] = ['component' => $c, 'value' => $value, 'passed' => $value !== null && isset($c['pass_mark']) ? $value >= $c['pass_mark'] : null];
            if ($template->calculation === 'weighted' && $c['input_type'] === 'number') {
                $total += ($value - $c['minimum']) / ($c['maximum'] - $c['minimum']) * $c['weight'];
            }
        }
        $total = $template->calculation === 'weighted' ? round($total, 2) : null;

        return ['scores' => $scores, 'total' => $total, 'passed' => $total !== null && $template->pass_mark !== null ? $total >= $template->pass_mark : null];
    }
}
