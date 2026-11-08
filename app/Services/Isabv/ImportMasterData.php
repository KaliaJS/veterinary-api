<?php

namespace App\Services\Isabv;

use App\Models\CatalogueImport;
use App\Models\IsabvCode;
use App\Models\IsabvPreparation;
use App\Support\MasquageSql;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Les données de base de l'OSAV (SI ABV, 08.11.2026), lues sur son serveur
 * sans jeton (`/psw/getMasterData/*`) : préparations, espèces et catégories
 * d'utilisation, clés de diagnostic, fréquences et voies d'administration.
 * Rien n'est relu si la date de dernière modification de l'OSAV n'a pas
 * changé depuis le dernier passage. Les codes que l'OSAV a retirés
 * s'effacent ; les préparations restent (une ancienne déclaration garde son
 * nom), sans plus se déclarer.
 */
class ImportMasterData
{
    public function run(bool $force = false): CatalogueImport
    {
        $start = hrtime(true);
        $journal = new CatalogueImport(['source' => CatalogueImport::ISABV, 'statut' => CatalogueImport::ECHEC]);
        $previous = CatalogueImport::query()->where('source', CatalogueImport::ISABV)
            ->whereIn('statut', [CatalogueImport::REUSSI, CatalogueImport::IDENTIQUE])->latest('id')->first();
        try {
            $modified = $this->get('lastModificationDate');
            $journal->fichier_etag = is_string($modified) ? mb_substr($modified, 0, 128) : null;
            if (! $force && $journal->fichier_etag !== null && $previous?->fichier_etag === $journal->fichier_etag) {
                $journal->statut = CatalogueImport::IDENTIQUE;
                $journal->message = 'Données de base inchangées à l’OSAV';

                return $this->close($journal, $start);
            }
            $lists = [];
            foreach (['preparation', 'species', 'diagnosisKey', 'administrationMode', 'administrationMethod'] as $name) {
                $list = $this->get($name);
                if (! is_array($list) || ! array_is_list($list) || $list === []) {
                    throw new RuntimeException('Liste « '.$name.' » vide ou de forme inattendue');
                }
                $lists[$name] = $list;
            }
            $counts = DB::transaction(fn (): array => $this->store($lists));
            Cache::forget('isabv:declarable-gtins');
            $journal->fill($counts)->statut = CatalogueImport::REUSSI;
        } catch (Throwable $error) {
            $journal->message = mb_substr(MasquageSql::message($error->getMessage()), 0, 2000);
            $this->close($journal, $start);
            throw $error;
        }

        return $this->close($journal, $start);
    }

    private function get(string $name): mixed
    {
        try {
            $response = Http::baseUrl((string) config('services.isabv.master_data_url'))->timeout(60)->accept('application/json, text/plain, */*')->get('/'.$name);
        } catch (ConnectionException $error) {
            throw new RuntimeException('Le serveur de l’OSAV ne répond pas ('.$name.')', 0, $error);
        }
        if ($response->failed()) {
            throw new RuntimeException('Le serveur de l’OSAV répond '.$response->status().' ('.$name.')');
        }
        $body = $response->json();

        return $body ?? trim($response->body(), " \n\r\t\"");
    }

    /**
     * @param  array<string, list<mixed>>  $lists
     * @return array{ajoutes: int, modifies: int, retires: int}
     */
    private function store(array $lists): array
    {
        $counts = ['ajoutes' => 0, 'modifies' => 0, 'retires' => 0];
        $now = now();

        $rows = [];
        foreach ($lists['species'] as $item) {
            if ($this->isEntry($item)) {
                $rows[] = $this->code($item, IsabvCode::KIND_SPECIES, '', parent: $item['parentSpecies'] ?? null, extra: [
                    'is_valid_for_consultations' => is_bool($item['validForConsultations'] ?? null) ? $item['validForConsultations'] : null,
                ]);
            }
        }
        foreach ($lists['diagnosisKey'] as $item) {
            $species = is_array($item['species'] ?? null) && is_string($item['species']['code'] ?? null) ? $item['species']['code'] : null;
            if ($this->isEntry($item) && $species !== null) {
                // « 1.1-4.8.1 » : l'espèce, puis la clé parente
                $parent = is_string($item['parentDiagnosisKey'] ?? null) && str_contains($item['parentDiagnosisKey'], '-')
                    ? substr($item['parentDiagnosisKey'], strpos($item['parentDiagnosisKey'], '-') + 1) : null;
                $rows[] = $this->code($item, IsabvCode::KIND_DIAGNOSIS, $species, parent: $parent);
            }
        }
        foreach ($lists['administrationMode'] as $item) {
            if ($this->isEntry($item)) {
                $rows[] = $this->code($item, IsabvCode::KIND_FREQUENCY, '', extra: [
                    'category' => is_string($item['prescriptionCategory'] ?? null) ? mb_substr($item['prescriptionCategory'], 0, 8) : null,
                    'per_day' => is_numeric($item['value'] ?? null) ? (float) $item['value'] : null,
                ]);
            }
        }
        foreach ($lists['administrationMethod'] as $item) {
            if ($this->isEntry($item)) {
                $rows[] = $this->code($item, IsabvCode::KIND_ROUTE, '');
            }
        }
        $existing = IsabvCode::query()->get(['kind', 'species_code', 'code'])->map(fn (IsabvCode $c) => $c->kind.'|'.$c->species_code.'|'.$c->code)->flip();
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['kind'].'|'.$row['species_code'].'|'.$row['code'];
            $seen[$key] = true;
            $counts[isset($existing[$key]) ? 'modifies' : 'ajoutes']++;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            IsabvCode::query()->upsert(array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                ['kind', 'species_code', 'code'], ['parent_code', 'label_fr', 'label_de', 'label_it', 'category', 'per_day', 'is_valid_for_consultations', 'updated_at']);
        }
        $stale = $existing->keys()->reject(fn (string $key) => isset($seen[$key]));
        foreach ($stale as $key) {
            [$kind, $species, $code] = explode('|', $key, 3);
            IsabvCode::query()->where(['kind' => $kind, 'species_code' => $species, 'code' => $code])->delete();
            $counts['retires']++;
        }

        $preparations = [];
        foreach ($lists['preparation'] as $item) {
            if (! $this->isEntry($item) || mb_strlen((string) $item['code']) > 12) {
                continue;
            }
            $unit = is_array($item['unit'] ?? null) ? $item['unit'] : [];
            $category = is_array($item['preparationCategory'] ?? null) ? $item['preparationCategory'] : [];
            $route = is_array($item['administrationMethod'] ?? null) ? $item['administrationMethod'] : [];
            $preparations[$item['code']] = [
                'code' => $item['code'],
                'name_fr' => mb_substr($this->label($item, 'FR') ?? $item['code'], 0, 255),
                'name_de' => $this->label($item, 'DE') !== null ? mb_substr($this->label($item, 'DE'), 0, 255) : null,
                'atc_code' => is_string($item['atcCode'] ?? null) ? mb_substr(trim($item['atcCode']), 0, 12) : null,
                'unit_code' => mb_substr(is_string($unit['code'] ?? null) ? $unit['code'] : '', 0, 12),
                'unit_label_fr' => mb_substr(trim((string) ($this->label($unit, 'FR') ?? ($unit['code'] ?? ''))), 0, 24),
                'category_code' => is_string($category['code'] ?? null) ? mb_substr($category['code'], 0, 12) : null,
                'category_label_fr' => $this->label($category, 'FR') !== null ? mb_substr($this->label($category, 'FR'), 0, 60) : null,
                'route_code' => is_string($route['code'] ?? null) ? mb_substr($route['code'], 0, 4) : null,
                'preparation_type' => is_string($item['preparationType'] ?? null) ? mb_substr($item['preparationType'], 0, 24) : null,
                'must_transfer' => ($item['transferISABV'] ?? false) === true,
                'has_critical_substance' => ($item['hasCriticalSubstance'] ?? false) === true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $known = IsabvPreparation::query()->pluck('code')->flip();
        foreach ($preparations as $code => $row) {
            $counts[isset($known[$code]) ? 'modifies' : 'ajoutes']++;
        }
        foreach (array_chunk(array_values($preparations), 500) as $chunk) {
            IsabvPreparation::query()->upsert($chunk, ['code'], ['name_fr', 'name_de', 'atc_code', 'unit_code', 'unit_label_fr', 'category_code', 'category_label_fr', 'route_code', 'preparation_type', 'must_transfer', 'has_critical_substance', 'updated_at']);
        }
        // Retirée par l'OSAV : elle reste, mais ne se déclare plus
        $gone = $known->keys()->reject(fn (string $code) => isset($preparations[$code]))->values();
        if ($gone->isNotEmpty()) {
            $counts['retires'] += IsabvPreparation::query()->whereIn('code', $gone->all())->where('must_transfer', true)->update(['must_transfer' => false, 'updated_at' => $now]);
        }

        return $counts;
    }

    /** @phpstan-assert-if-true array<string, mixed> $item */
    private function isEntry(mixed $item): bool
    {
        return is_array($item) && is_string($item['code'] ?? null) && trim($item['code']) !== '';
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function code(array $item, string $kind, string $species, mixed $parent = null, array $extra = []): array
    {
        return [
            'kind' => $kind,
            'species_code' => mb_substr($species, 0, 12),
            'code' => mb_substr(trim((string) $item['code']), 0, 24),
            'parent_code' => is_string($parent) && $parent !== '' ? mb_substr($parent, 0, 24) : null,
            'label_fr' => mb_substr($this->label($item, 'FR') ?? (string) $item['code'], 0, 255),
            'label_de' => $this->label($item, 'DE') !== null ? mb_substr($this->label($item, 'DE'), 0, 255) : null,
            'label_it' => $this->label($item, 'IT') !== null ? mb_substr($this->label($item, 'IT'), 0, 255) : null,
            'category' => null,
            'per_day' => null,
            'is_valid_for_consultations' => null,
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $item */
    private function label(array $item, string $language): ?string
    {
        $value = $item['description'.$language] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function close(CatalogueImport $journal, int|float $start): CatalogueImport
    {
        $journal->duree_ms = (int) ((hrtime(true) - $start) / 1_000_000);
        $journal->save();

        return $journal;
    }
}
