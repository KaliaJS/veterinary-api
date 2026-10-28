<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Le stockage de chaque organisation hors DICOM (décision de Jim,
 * 28.10.2026) : photos des animaux, de l'équipe et des cliniques, logo du
 * site, consentements signés, à leur taille réelle sur le disque privé.
 * Les DICOM se comptent en direct (Organisation::stockageOctets). L'audio
 * des consultations, effacé en quelques jours, ne compte pas.
 */
class CompterStockage extends Command
{
    protected $signature = 'stockage:compter';

    protected $description = 'Compte le stockage des organisations (photos, logo, consentements)';

    /** Dossiers rangés par organisation */
    public const array DOSSIERS = ['photos-animaux', 'photos', 'photos-cliniques', 'logos'];

    public function handle(): int
    {
        $disque = Storage::disk('prive');
        $lister = $disque->getDriver();

        Organisation::query()->select('id')->chunkById(100, function ($organisations) use ($disque, $lister): void {
            foreach ($organisations as $organisation) {
                $octets = 0;
                foreach (self::DOSSIERS as $dossier) {
                    foreach ($lister->listContents($dossier.'/'.$organisation->id, true) as $element) {
                        if ($element instanceof FileAttributes) {
                            $octets += (int) ($element->fileSize() ?? $disque->size($element->path()));
                        }
                    }
                }
                // Consentements signés : rangés par intervention, rattachés par le dossier de l'animal
                Document::query()->withoutGlobalScopes()->whereNotNull('chemin')
                    ->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->where('organisation_id', $organisation->id))
                    ->pluck('chemin')
                    ->each(function (string $chemin) use ($disque, &$octets): void {
                        if ($disque->exists($chemin)) {
                            $octets += $disque->size($chemin);
                        }
                    });

                $organisation->forceFill(['stockage_autres_octets' => $octets, 'stockage_compte_le' => now()])->saveQuietly();
            }
        });

        return self::SUCCESS;
    }
}
