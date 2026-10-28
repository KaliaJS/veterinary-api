<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PreferencesProprietaire;
use App\Models\User;
use Illuminate\View\View;

/**
 * Lien « Ne plus recevoir les rappels » des e-mails (28.10.2026), signé :
 * la page demande de confirmer ; le POST (bouton de la page, ou désabonnement
 * en un clic de la messagerie) coupe les rappels par e-mail du propriétaire.
 */
class DesabonnementRappelsController extends Controller
{
    public function confirmer(int $client): View
    {
        return view('rappels.desabonnement', ['client' => $this->client($client), 'fait' => false]);
    }

    public function desabonner(int $client): View
    {
        $fiche = $this->client($client);
        $fiche->forceFill(['rappels_email' => false])->save();
        // L'espace du propriétaire dit la même chose que le lien
        if ($fiche->user_id !== null) {
            PreferencesProprietaire::pour(User::query()->findOrFail($fiche->user_id))->update(['rappels_email' => false]);
        }

        return view('rappels.desabonnement', ['client' => $fiche, 'fait' => true]);
    }

    private function client(int $id): Client
    {
        return Client::query()->withoutGlobalScopes()->findOrFail($id);
    }
}
