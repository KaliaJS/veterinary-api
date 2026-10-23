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
use RuntimeException;

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

        // Le premier morceau commence comme un fichier audio du format annoncé (23.10.2026)
        if ($index === 0 && ! self::estAudio((string) file_get_contents($fichier->getRealPath(), false, null, 0, 16), $format)) {
            throw ValidationException::withMessages(['fichier' => 'Ce fichier n’est pas un enregistrement audio.']);
        }

        // Borne de tout l'enregistrement (23.10.2026) : au-delà, plus rien n'est reçu
        if ($this->octets($enregistrement) + (int) $fichier->getSize() > (int) config('transcription.octets_max_enregistrement')) {
            throw ValidationException::withMessages(['fichier' => 'Cet enregistrement dépasse la taille permise.']);
        }

        $fichier->storeAs($partie->dossierMorceaux(), self::nom($index), 'prive');

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

        return collect(Storage::disk('prive')->files($partie->dossierMorceaux()))
            ->map(fn (string $chemin) => (int) basename($chemin))
            ->sort()
            ->values()
            ->all();
    }

    /** Octets déjà reçus pour l'enregistrement (morceaux et parties assemblées). */
    private function octets(EnregistrementConsultation $enregistrement): int
    {
        $disque = Storage::disk('prive');

        return array_sum(array_map(fn (string $chemin): int => $disque->size($chemin), $disque->allFiles($enregistrement->dossierAudio())));
    }

    /**
     * Les octets bout à bout, par flux (23.10.2026) : le disque privé peut
     * être un bucket, sans chemin local.
     */
    private function assembler(EnregistrementConsultation $enregistrement, PartieEnregistrement $partie): string
    {
        $disque = Storage::disk('prive');
        $chemin = $enregistrement->dossierAudio().'/'.$partie->numero.'.audio';

        $sortie = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        for ($index = 0; $index < $partie->morceaux; $index++) {
            $entree = $disque->readStream($partie->dossierMorceaux().'/'.self::nom($index));
            if (! is_resource($entree) || ! is_resource($sortie)) {
                throw new RuntimeException('Morceau d’audio illisible.');
            }
            stream_copy_to_stream($entree, $sortie);
            fclose($entree);
        }
        rewind($sortie);
        $disque->writeStream($chemin, $sortie);
        fclose($sortie);

        $disque->deleteDirectory($partie->dossierMorceaux());

        return $chemin;
    }

    /** Les premiers octets d'un fichier du format annoncé. */
    public static function estAudio(string $debut, string $format): bool
    {
        return match ($format) {
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => substr($debut, 4, 4) === 'ftyp',
            'audio/webm' => str_starts_with($debut, "\x1A\x45\xDF\xA3"),
            'audio/ogg' => str_starts_with($debut, 'OggS'),
            'audio/wav' => str_starts_with($debut, 'RIFF') && substr($debut, 8, 4) === 'WAVE',
            'audio/mpeg' => str_starts_with($debut, 'ID3') || (strlen($debut) > 1 && ord($debut[0]) === 0xFF && (ord($debut[1]) & 0xE0) === 0xE0),
            'audio/aac' => str_starts_with($debut, 'ID3') || (strlen($debut) > 1 && ord($debut[0]) === 0xFF && (ord($debut[1]) & 0xF6) === 0xF0),
            default => false,
        };
    }

    private static function nom(int $index): string
    {
        return sprintf('%05d', $index);
    }
}
