<?php

namespace App\Http\Resources\V1;

use App\Enums\Espece;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use App\Models\CliniqueEspece;
use App\Models\CliniqueService;
use App\Services\Photos\PhotosCliniques;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La clinique vue de l'écran Organisation : identité, adresse,
 * rattachement à sa société, et son équipe (fiches RH complètes — la
 * même table `employes` que l'écran Équipe).
 *
 * @mixin Clinique
 */
class CliniqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'raison_sociale' => $this->raison_sociale,
            'societe_id' => $this->societe_id,
            // Écran Organisation : patients des 12 derniers mois (servi par la liste des cliniques)
            'patients' => $this->when($this->resource->getAttribute('patients_douze_mois') !== null, fn (): int => (int) $this->resource->getAttribute('patients_douze_mois')),
            'rue' => $this->rue,
            'npa' => $this->npa,
            'ville' => $this->ville,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'tel' => $this->tel,
            'email' => $this->email,
            'fuseau_horaire' => $this->fuseau_horaire,
            'nombre_boxes' => $this->nombre_boxes,
            // Ce que la clinique propose (ouvre les modules de l'espace
            // vétérinaire, paraît dans l'annuaire), les animaux soignés, et
            // si l'annuaire montre ses vétérinaires
            'services' => $this->services
                ->sortBy(fn (CliniqueService $ligne): int => array_search($ligne->service, ServiceClinique::cases(), true))
                ->map(fn (CliniqueService $ligne): array => ['service' => $ligne->service->value, 'modalite' => $ligne->modalite?->value])
                ->values()->all(),
            'especes' => $this->especes
                ->sortBy(fn (CliniqueEspece $ligne): int => array_search($ligne->espece, Espece::cases(), true))
                ->map(fn (CliniqueEspece $ligne): string => $ligne->espece->value)
                ->values()->all(),
            'affiche_veterinaires' => $this->affiche_veterinaires,
            'rdv_sans_compte' => $this->rdv_sans_compte,
            // Rendez-vous en ligne : réservés aussitôt (défaut), ou chacun validé par la clinique
            'rdv_a_valider' => $this->rdv_a_valider,
            // Une urgence prise en ligne attend la clinique (défaut)
            'urgences_a_valider' => $this->urgences_a_valider,
            // Acompte pour réserver (centimes, null : aucun), remboursé si annulé tant d'heures avant
            'acompte_centimes' => $this->acompte_centimes,
            // Photo : grande version, largeurs pour `srcset`, aperçu flou
            'photo' => PhotosCliniques::pour($this->resource),
            // Dans l'annuaire des cliniques, ou ce qui manque pour y paraître
            'annuaire' => [
                'est_visible' => $this->estDansLAnnuaire(),
                'manques' => $this->manquesAnnuaire(),
            ],
            'equipe' => EmployeResource::collection($this->whenLoaded('employes')),
            'nombre_employes_actifs' => $this->whenLoaded(
                'employes',
                fn (): int => $this->employes->where('acces_suspendu', false)->count(),
            ),
        ];
    }
}
