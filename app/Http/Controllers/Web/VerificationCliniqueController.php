<?php

namespace App\Http\Controllers\Web;

use App\Enums\VerificationOrganisation;
use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Services\Inscription\InscriptionClinique;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vérification à la main d'une clinique inscrite, depuis les liens signés
 * de l'e-mail envoyé à la plateforme : la page rappelle la clinique et
 * demande de confirmer ; seul le POST (même lien signé) décide.
 */
class VerificationCliniqueController extends Controller
{
    public function confirmerValidation(Organisation $organisation): View
    {
        return $this->page($organisation, 'valider');
    }

    public function valider(Organisation $organisation, InscriptionClinique $inscription): View
    {
        $inscription->valider($organisation);

        return $this->page($organisation->refresh(), 'valider');
    }

    public function confirmerRefus(Organisation $organisation): View
    {
        return $this->page($organisation, 'refuser');
    }

    public function refuser(Request $request, Organisation $organisation, InscriptionClinique $inscription): View
    {
        $donnees = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $inscription->refuser($organisation, $donnees['note'] ?? null);

        return $this->page($organisation->refresh(), 'refuser');
    }

    private function page(Organisation $organisation, string $geste): View
    {
        $societe = $organisation->societes()->first();

        return view('verification-clinique.decision', [
            'organisation' => $organisation,
            'geste' => $geste,
            'enAttente' => $organisation->verification === VerificationOrganisation::EnAttente,
            'user' => $organisation->inscritePar,
            'societe' => $societe,
            'clinique' => $societe?->cliniques()->first(),
        ]);
    }
}
