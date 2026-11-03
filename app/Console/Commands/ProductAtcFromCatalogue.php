<?php

namespace App\Console\Commands;

use App\Models\Produit;
use App\Services\Catalogue\AtcFromCatalogue;
use Illuminate\Console\Command;

/**
 * Les articles déjà en stock reprennent leur code ATC du catalogue
 * Swissmedic, par leur code-barres (03.11.2026). Un code saisi ou effacé
 * à la main n'est jamais remplacé. Lancée une fois par la migration du code ATC ; sans
 * danger si on la relance.
 */
class ProductAtcFromCatalogue extends Command
{
    protected $signature = 'produits:atc-depuis-catalogue';

    protected $description = 'Reprend le code ATC des articles du stock depuis le catalogue Swissmedic, par leur code-barres';

    public function handle(): int
    {
        $filled = 0;
        Produit::withoutGlobalScopes()
            ->whereNull('atc_code')
            // Un code saisi ou effacé à la main n'est jamais repris
            ->where('atc_code_is_manual', false)
            ->whereNotNull('ean')
            ->where('ean', '!=', '')
            ->chunkById(200, function ($products) use (&$filled): void {
                foreach ($products as $product) {
                    /** @var Produit $product */
                    $code = AtcFromCatalogue::forGtin($product->ean);
                    if ($code !== null) {
                        $product->forceFill(['atc_code' => $code])->saveQuietly();
                        $filled++;
                    }
                }
            });
        $this->info("{$filled} article(s) ont reçu leur code ATC du catalogue.");

        return self::SUCCESS;
    }
}
