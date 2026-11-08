<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Models\NeuroExam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Un examen neurologique, à la création comme à la modification : seuls
 * les champs envoyés comptent. Chaque valeur vient des listes de NeuroExam ;
 * null veut dire « non examiné ». Une patte ou un nerf inconnu est refusé
 * à son nom ; le patellaire ne se note qu'aux postérieurs.
 */
class NeuroExamRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'mental_status' => ['nullable', Rule::in(NeuroExam::MENTAL_STATUSES)],
            'gait_score' => ['nullable', 'integer', 'min:0', 'max:'.NeuroExam::GAIT_SCORE_MAX, fn (string $attribute, mixed $value, \Closure $fail) => is_bool($value) && $fail('Le score est un nombre de 0 à '.NeuroExam::GAIT_SCORE_MAX.'.')],
            'limbs' => ['sometimes', 'array'],
            'limbs.*' => ['array'],
            'limbs.*.motor' => ['nullable', Rule::in(NeuroExam::MOTOR)],
            'limbs.*.proprioception' => ['nullable', Rule::in(NeuroExam::PROPRIOCEPTION)],
            'limbs.*.withdrawal' => ['nullable', Rule::in(NeuroExam::REFLEX)],
            'limbs.*.patellar' => ['nullable', Rule::in(NeuroExam::REFLEX)],
            'limbs.*.deep_pain' => ['nullable', Rule::in(NeuroExam::DEEP_PAIN)],
            'spine' => ['sometimes', 'array'],
            'spine.painful_regions' => ['sometimes', 'array'],
            'spine.painful_regions.*' => [Rule::in(NeuroExam::SPINE_REGIONS)],
            'spine.cutaneous_trunci' => ['nullable', Rule::in(NeuroExam::CUTANEOUS_TRUNCI)],
            'spine.cutoff_at' => ['nullable', 'required_if:spine.cutaneous_trunci,cutoff', Rule::in(NeuroExam::vertebrae())],
            'cranial_nerves' => ['sometimes', 'array'],
            'cranial_nerves.*' => ['array'],
            'cranial_nerves.*.left' => ['nullable', Rule::in(NeuroExam::CRANIAL_VALUES)],
            'cranial_nerves.*.right' => ['nullable', Rule::in(NeuroExam::CRANIAL_VALUES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
        foreach (array_diff(NeuroExam::LIMBS, NeuroExam::HIND_LIMBS) as $front) {
            $rules["limbs.$front.patellar"] = ['prohibited'];
        }

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['limbs' => NeuroExam::LIMBS, 'cranial_nerves' => NeuroExam::CRANIAL_TESTS] as $field => $known) {
                    $value = $this->input($field);
                    if (! is_array($value)) {
                        continue;
                    }
                    foreach (array_diff(array_keys($value), $known) as $unknown) {
                        $validator->errors()->add("$field.$unknown", 'Inconnu : '.$unknown.'.');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'limbs.*.patellar.prohibited' => 'Le réflexe patellaire se note aux postérieurs.',
            'spine.cutoff_at.required_if' => 'Indiquez la vertèbre où le réflexe s’arrête.',
        ];
    }
}
