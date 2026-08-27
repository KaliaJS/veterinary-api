<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\TypePack;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\AcheterPackRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Services\Numeroteur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Packs de quota (docs/data-model.md §2) : palier du catalogue, prix du
 * serveur, facturé immédiatement (« F- »). E-mails et SMS s'ajoutent au
 * mois en cours, le stockage est permanent.
 */
class PackQuotaController extends Controller
{
    public function __construct(private Numeroteur $numeroteur) {}

    public function acheter(AcheterPackRequest $request): OrganisationResource
    {
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;

        if ($organisation->moyen_paiement === null) {
            throw ValidationException::withMessages([
                'moyen_paiement' => 'Enregistrez d’abord un moyen de paiement.',
            ]);
        }

        $type = TypePack::from($request->validated('type'));
        $quantite = (int) $request->validated('quantite');
        $prix = $type->prixPour($quantite);

        DB::transaction(function () use ($organisation, $type, $quantite, $prix): void {
            $organisation->increment($type->colonneQuota(), $quantite);

            $organisation->facturesAbonnement()->create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::FactureAbonnement, $organisation),
                'emise_le' => today(),
                'libelle' => $type->libelle($quantite),
                'montant' => $prix,
            ]);
        });

        return new OrganisationResource($organisation->refresh());
    }
}
