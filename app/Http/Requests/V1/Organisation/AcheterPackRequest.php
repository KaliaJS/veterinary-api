<?php

namespace App\Http\Requests\V1\Organisation;

use App\Enums\TypePack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Achat d'un pack de quota : le palier doit exister au catalogue — le
 * prix est celui du serveur, jamais celui du client.
 */
class AcheterPackRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TypePack::class)],
            'quantite' => [
                'required',
                'integer',
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    $type = TypePack::tryFrom((string) $this->input('type'));

                    if ($type !== null && $type->prixPour((int) $valeur) === null) {
                        $echec('Ce palier n’existe pas au catalogue des packs.');
                    }
                },
            ],
        ];
    }
}
