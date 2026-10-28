<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Throwable;

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
                try {
                    $this->compter($organisation, $disque, $lister);
                } catch (Throwable $e) {
                    // Une panne du stockage : cette organisation garde le chiffre de la veille, les suivantes sont comptées
                    report($e);
                }
            }
        });

        return self::SUCCESS;
    }

    private function compter(Organisation $organisation, Filesystem $disque, FilesystemOperator $lister): void
    {
        $octets = 0;
        foreach (self::DOSSIERS as $dossier) {
            foreach ($lister->listContents($dossier.'/'.$organisation->id, true) as $element) {
                if ($element instanceof FileAttributes) {
                    $octets += (int) ($element->fileSize() ?? $disque->size($element->path()));
                }
            }
        }
        // Consentements signés : rangés par intervention, rattachés par le
        // dossier de l'animal ; les PDF ajoutés se comptent en direct
        Document::query()->withoutGlobalScopes()->whereNotNull('chemin')->whereNull('taille_octets')
            ->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->where('organisation_id', $organisation->id))
            ->pluck('chemin')
            ->each(function (string $chemin) use ($disque, &$octets): void {
                // Une seule lecture : un fichier disparu ne compte pas
                $octets += (int) rescue(fn () => $disque->size($chemin), 0, false);
            });

        $organisation->forceFill(['stockage_autres_octets' => $octets, 'stockage_compte_le' => now()])->saveQuietly();
    }
}
