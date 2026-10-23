<?php

namespace App\Services\Transcription;

use App\Enums\StatutPartie;
use App\Jobs\TranscrirePartie;
use App\Models\EnregistrementConsultation;
use App\Models\PartieEnregistrement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Réception de l'audio par morceaux d'environ 1 Mo : chacun passe sous les
 * limites d'upload des serveurs, et une connexion qui lâche (visite à la
 * ferme) ne fait renvoyer que les morceaux manquants. Un morceau renvoyé
 * remplace le précédent ; quand tous sont là, la partie est assemblée (les
 * octets bout à bout redonnent le fichier) et part en transcription.
 */
final class ReceptionAudio
{
    public function morceau(EnregistrementConsultation $enregistrement, int $numero, int $index, int $total, string $format, UploadedFile $fichier): PartieEnregistrement
    {
        $partie = PartieEnregistrement::query()->firstOrCreate(
            ['enregistrement_consultation_id' => $enregistrement->id, 'numero' => $numero],
            ['format' => $format, 'morceaux' => $total],
        );

        // Partie déjà complète : un renvoi tardif ne change rien
        if ($partie->statut !== StatutPartie::Envoi) {
            return $partie;
        }
        if ($partie->morceaux !== $total || $index >= $total) {
            throw ValidationException::withMessages(['morceau' => 'Ce morceau ne correspond pas à la partie annoncée.']);
        }

        // Borne de tout l'enregistrement (23.10.2026) : au-delà, plus rien n'est reçu
        if ($this->octets($enregistrement) + (int) $fichier->getSize() > (int) config('transcription.octets_max_enregistrement')) {
            throw ValidationException::withMessages(['fichier' => 'Cet enregistrement dépasse la taille permise.']);
        }

        $fichier->storeAs($partie->dossierMorceaux(), self::nom($index), 'local');

        $complete = DB::transaction(function () use ($partie, $enregistrement): ?PartieEnregistrement {
            $partie = PartieEnregistrement::query()->lockForUpdate()->findOrFail($partie->id);
            if ($partie->statut !== StatutPartie::Envoi || count($this->recus($partie)) < $partie->morceaux) {
                return null;
            }

            $partie->forceFill([
                'statut' => StatutPartie::Recue,
                'fichier' => $this->assembler($enregistrement, $partie),
                'recue_le' => now(),
            ])->save();

            return $partie;
        });

        if ($complete) {
            TranscrirePartie::dispatch($complete->id);

            return $complete->refresh();
        }

        return $partie->refresh();
    }

    /** @return list<int> numéros des morceaux déjà reçus */
    public function recus(PartieEnregistrement $partie): array
    {
        if ($partie->statut !== StatutPartie::Envoi) {
            return range(0, $partie->morceaux - 1);
        }

        return collect(Storage::disk('local')->files($partie->dossierMorceaux()))
            ->map(fn (string $chemin) => (int) basename($chemin))
            ->sort()
            ->values()
            ->all();
    }

    /** Octets déjà reçus pour l'enregistrement (morceaux et parties assemblées). */
    private function octets(EnregistrementConsultation $enregistrement): int
    {
        $disque = Storage::disk('local');

        return array_sum(array_map(fn (string $chemin): int => $disque->size($chemin), $disque->allFiles($enregistrement->dossierAudio())));
    }

    private function assembler(EnregistrementConsultation $enregistrement, PartieEnregistrement $partie): string
    {
        $disque = Storage::disk('local');
        $chemin = $enregistrement->dossierAudio().'/'.$partie->numero.'.audio';

        $sortie = fopen($disque->path($chemin), 'wb');
        for ($index = 0; $index < $partie->morceaux; $index++) {
            $entree = fopen($disque->path($partie->dossierMorceaux().'/'.self::nom($index)), 'rb');
            stream_copy_to_stream($entree, $sortie);
            fclose($entree);
        }
        fclose($sortie);

        $disque->deleteDirectory($partie->dossierMorceaux());

        return $chemin;
    }

    private static function nom(int $index): string
    {
        return sprintf('%05d', $index);
    }
}
