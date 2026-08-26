<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RendezVousAuditResource;
use App\Models\RendezVous;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Historique complet, sans pagination : le front regroupe les entrées
 * contiguës par `batch_id` — une page qui couperait un lot en deux
 * casserait ce regroupement.
 */
class RendezVousAuditController extends Controller
{
    public function __invoke(RendezVous $rendezVous): AnonymousResourceCollection
    {
        $this->authorize('view', $rendezVous);

        return RendezVousAuditResource::collection(
            $rendezVous->audits()->with('user')->orderBy('created_at')->orderBy('id')->get(),
        );
    }
}
