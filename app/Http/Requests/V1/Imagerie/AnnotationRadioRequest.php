<?php

namespace App\Http\Requests\V1\Imagerie;

use App\Support\Imagerie\RadiosDuDossier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Une annotation dessinée sur une radio : type, points en pixels de
 * l'image d'origine (dans l'image), image du fichier, étiquette et
 * certitude des listes fixes. À la modification, chaque champ est
 * facultatif ; type et points vont ensemble.
 */
class AnnotationRadioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creation = $this->isMethod('post');
        $requis = $creation ? 'required' : 'sometimes';
        $cliche = RadiosDuDossier::depuisLaRoute($this->route('patient'), $this->route('examen'), $this->route('cliche'));
        $types = config('imagerie.types');

        return [
            'type' => [$creation || $this->has('points') ? 'required' : 'sometimes', 'string', Rule::in(array_keys($types))],
            'points' => [$creation || $this->has('type') ? 'required' : 'sometimes', 'array', function (string $attribut, mixed $points, Closure $echec) use ($types, $cliche): void {
                $type = $this->input('type');
                [$min, $max] = is_string($type) ? ($types[$type]['points'] ?? [1, 500]) : [1, 500];
                if (! is_array($points) || ! array_is_list($points) || count($points) < $min || count($points) > $max) {
                    $echec($min === $max ? "Ce dessin demande {$min} point(s)." : "Ce dessin demande de {$min} à {$max} points.");

                    return;
                }
                foreach ($points as $point) {
                    if (! is_array($point) || ! array_is_list($point) || count($point) !== 2 || ! (is_int($point[0]) || is_float($point[0])) || ! (is_int($point[1]) || is_float($point[1]))) {
                        $echec('Chaque point est une paire [x, y].');

                        return;
                    }
                    // Dans l'image (colonnes × lignes), quand le fichier les donne
                    if ($point[0] < 0 || $point[1] < 0
                        || ($cliche?->colonnes !== null && $point[0] > $cliche->colonnes)
                        || ($cliche?->lignes !== null && $point[1] > $cliche->lignes)) {
                        $echec('Un point sort de l’image.');

                        return;
                    }
                }
            }],
            'image' => ['sometimes', 'integer', 'min:0', 'max:'.max(0, ($cliche === null ? 1 : $cliche->images) - 1)],
            'etiquette' => [$requis, 'string', Rule::in(array_keys(config('imagerie.etiquettes')))],
            'certitude' => [$requis, 'string', Rule::in(array_keys(config('imagerie.certitudes')))],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'Choisissez un dessin.',
            'type.string' => 'Ce type de dessin n’existe pas.',
            'type.in' => 'Ce type de dessin n’existe pas.',
            'points.required' => 'Le dessin n’a pas de points.',
            'points.array' => 'Le dessin n’a pas de points.',
            'image.integer' => 'Ce fichier n’a pas autant d’images.',
            'etiquette.required' => 'Choisissez une étiquette.',
            'certitude.required' => 'Choisissez « Sûr » ou « Suspect ».',
            'image.max' => 'Ce fichier n’a pas autant d’images.',
            'etiquette.in' => 'Choisissez une étiquette de la liste.',
            'certitude.in' => 'Choisissez « Sûr » ou « Suspect ».',
        ];
    }
}
