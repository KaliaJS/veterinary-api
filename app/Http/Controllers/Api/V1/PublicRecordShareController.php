<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\RecordShare;
use App\Models\RecordShareEvent;
use App\Services\Patients\RecordShareItems;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La page d'un lien de partage du dossier (09.11.2026), sans compte :
 * l'animal, la clinique, la liste des pièces choisies par date ; chaque
 * pièce s'ouvre ; « Tout télécharger » est compté (5 par lien, 50 par
 * clinique et par mois, par l'ensemble de ses liens). Un lien coupé,
 * expiré ou inconnu répond 404, sans dire lequel. Rien d'autre du dossier
 * que les pièces choisies.
 */
class PublicRecordShareController extends Controller
{
    /** Une ouverture n'est notée qu'une fois par quart d'heure */
    private const OPEN_EVERY_MINUTES = 15;

    public function show(string $token, RecordShareItems $items): JsonResponse
    {
        $share = $this->share($token);
        $patient = $share->patient ?? abort(404);
        $last = RecordShareEvent::query()->where('record_share_id', $share->id)->where('kind', 'opened')->max('at');
        if ($last === null || CarbonImmutable::parse($last)->addMinutes(self::OPEN_EVERY_MINUTES)->isPast()) {
            RecordShareEvent::query()->create(['record_share_id' => $share->id, 'kind' => 'opened', 'at' => now()]);
        }
        $clinique = Clinique::withoutGlobalScopes()->find($share->clinique_id);
        $chosen = collect($share->items ?? [])->map(fn (array $i) => $i['type'].':'.$i['id'])->flip();
        // La liste comme les pièces : telle qu'au partage ; une lettre de référence en tête
        $list = collect($items->candidates($patient, null, null, $share->created_at))
            ->flatMap(fn (array $g) => $g['items'])
            ->filter(fn (array $i) => $chosen->has($i['type'].':'.$i['id']))
            ->sortByDesc(fn (array $i) => ($i['type'] === 'referral_letter' ? '1' : '0').$i['date'].sprintf('%010d', $i['id']))
            ->values();

        return response()->json(['data' => [
            'patient' => [
                'name' => $patient->nom,
                'species' => $patient->espece->libelle(),
                'breed' => $patient->race,
                'sex' => $patient->sexe->value,
                'born_on' => $patient->ne_le?->toDateString(),
                'chip' => $patient->puce,
            ],
            'clinic' => ['name' => $clinique?->nom, 'phone' => $clinique?->tel, 'email' => $clinique?->email],
            // L'en-tête et le pied des PDF composés sur la page, comme ceux de la clinique
            'en_tete' => $clinique?->enTeteDocument(),
            'shared_by' => $share->created_by_name,
            'expires_at' => $share->expires_at->toIso8601String(),
            'timezone' => $share->timezone(),
            'downloads_left' => $this->downloadsLeft($share),
            'items' => $list,
        ]]);
    }

    public function item(string $token, string $type, int $id, RecordShareItems $items): JsonResponse
    {
        $share = $this->share($token);
        abort_unless($share->includes($type, $id), 404);
        $this->countOpening($share);

        // Les notes des visites si le vétérinaire l'a choisi ; rien de ce qui s'est ajouté après le partage
        $options = ['visit_notes' => $share->include_visit_notes, 'as_of' => $share->created_at];

        return response()->json(['data' => $items->printable($share->patient ?? abort(404), $type, $id, $options) ?? abort(404)]);
    }

    public function file(string $token, string $type, int $id, int $n, RecordShareItems $items): StreamedResponse
    {
        $share = $this->share($token);
        abort_unless($share->includes($type, $id), 404);
        // Compté seulement pour un fichier qui existe
        $file = $items->file($share->patient ?? abort(404), $type, $id, $n) ?? abort(404);
        $this->countOpening($share);

        return Storage::disk('prive')->download($file['path'], $file['name'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store']);
    }

    /**
     * « Tout télécharger » : la page compose le dossier, puis le fait compter
     * ici avant de l'enregistrer (un téléchargement qui échoue ne coûte
     * rien). Compté sous le verrou de la clinique, qui porte la limite du
     * mois, puis du lien ; au-delà, refusé avec sa raison.
     */
    public function download(string $token): JsonResponse
    {
        $share = $this->share($token);
        $left = DB::transaction(function () use ($share): int {
            Clinique::withoutGlobalScopes()->whereKey($share->clinique_id)->lockForUpdate()->first();
            $locked = RecordShare::withoutGlobalScopes()->whereKey($share->id)->lockForUpdate()->firstOrFail();
            if ($locked->full_downloads >= RecordShare::MAX_DOWNLOADS_PER_LINK) {
                abort(429, 'Ce lien a déjà servi à '.RecordShare::MAX_DOWNLOADS_PER_LINK.' téléchargements complets. Demandez un nouveau lien à la clinique.');
            }
            if ($this->clinicDownloadsThisMonth($locked) >= RecordShare::MAX_DOWNLOADS_PER_CLINIC_MONTH) {
                abort(429, 'La clinique a atteint ses téléchargements complets du mois. Réessayez le mois prochain, ou demandez à la clinique de vous envoyer le dossier.');
            }
            $locked->forceFill(['full_downloads' => $locked->full_downloads + 1])->save();
            RecordShareEvent::query()->create(['record_share_id' => $locked->id, 'kind' => 'downloaded', 'at' => now()]);

            return $this->downloadsLeft($locked);
        });

        return response()->json(['data' => ['downloads_left' => $left]]);
    }

    /**
     * Chaque pièce ou fichier ouvert compte, « Tout télécharger » compris,
     * jusqu'au plafond du lien (choix de Jim, 09.11.2026). Refusé en 403 :
     * pas un « trop de requêtes » que la page attendrait pour rien.
     */
    private function countOpening(RecordShare $share): void
    {
        $counted = RecordShare::withoutGlobalScopes()->whereKey($share->id)->whereColumn('openings', '<', 'openings_max')->increment('openings');
        abort_if($counted === 0, 403, 'Ce lien a atteint son nombre d’ouvertures. Demandez un nouveau lien à la clinique.');
    }

    private function share(string $token): RecordShare
    {
        $share = RecordShare::withoutGlobalScopes()->where('token_hash', RecordShare::hash($token))->where('kind', 'link')->first();
        abort_unless($share?->isActive(), 404);

        return $share;
    }

    /** Le mois de la clinique, à son heure */
    private function clinicDownloadsThisMonth(RecordShare $share): int
    {
        $monthStart = now($share->timezone())->startOfMonth()->utc();

        return RecordShareEvent::query()
            ->where('kind', 'downloaded')
            ->where('at', '>=', $monthStart)
            ->whereIn('record_share_id', RecordShare::withoutGlobalScopes()->where('clinique_id', $share->clinique_id)->select('id'))
            ->count();
    }

    private function downloadsLeft(RecordShare $share): int
    {
        return max(0, min(
            RecordShare::MAX_DOWNLOADS_PER_LINK - $share->full_downloads,
            RecordShare::MAX_DOWNLOADS_PER_CLINIC_MONTH - $this->clinicDownloadsThisMonth($share),
        ));
    }
}
