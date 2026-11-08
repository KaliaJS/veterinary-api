<?php

namespace App\Services\Isabv;

use App\Auth\ContexteClinique;
use App\Enums\DeclarationStatus;
use App\Enums\DomainePermission;
use App\Enums\Espece;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\AntibioticDeclarationLine;
use App\Models\IsabvCode;
use App\Models\IsabvConnection;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Les déclarations SI ABV d'une clinique qui déclare par Veterinary
 * (variante 1, 08.11.2026). Une ordonnance qui porte au moins un article de
 * la liste de l'OSAV donne une déclaration (une par préparation pour une
 * remise à titre de stocks, dont le formulaire n'en porte qu'une) : ce qui
 * est prescrit, remis ou utilisé pendant la consultation (O-SI ABV, art. 2,
 * al. 1, let. b). Elle part dès qu'elle est complète, après la fenêtre
 * d'annulation de l'ordonnance ; incomplète, elle attend le vétérinaire.
 */
class Declarations
{
    /** Après la fenêtre d'annulation d'une signature (20 s), avec une marge */
    public const SEND_DELAY_SECONDS = 30;

    public function __construct(
        private PreparationResolver $resolver,
        private DeclarationRules $rules,
    ) {}

    /**
     * L'espèce ou la catégorie d'utilisation proposée selon l'espèce de la
     * fiche : celle choisie sur la fiche, sinon la seule possible (chien,
     * chat, équidé : un équidé de rente compte comme animal de compagnie,
     * O-SI ABV art. 4, al. 2 ; les autres animaux de compagnie : « Autres »),
     * sinon l'espèce de rente, dont la catégorie reste à choisir.
     */
    public static function defaultSpecies(Patient $patient): ?string
    {
        $chosen = trim((string) $patient->isabv_species);
        if ($chosen !== '') {
            return $chosen;
        }

        return match ($patient->espece) {
            Espece::Chien => '1.5',
            Espece::Chat => '1.4',
            Espece::Cheval => '1.1',
            Espece::Furet, Espece::Rongeur, Espece::Oiseau, Espece::Reptile, Espece::Lapin => '1.11',
            Espece::Bovin => '2.1',
            Espece::Porc => '2.2',
            Espece::Volaille => '2.3',
            Espece::Ovin => '2.6',
            Espece::Caprin => '2.7',
        };
    }

    /**
     * Les déclarations d'une ordonnance signée, avec ce que le vétérinaire a
     * saisi (`$input` : la déclaration ; `$lineInputs` : par ligne, dans
     * l'ordre des lignes de l'ordonnance). Rien si la clinique ne déclare pas
     * par Veterinary, ou si aucun article n'est à déclarer.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>|null>  $lineInputs
     * @param  list<int>|null  $onlyLineIds  seulement ces lignes de l'ordonnance
     * @return list<AntibioticDeclaration>
     */
    public function forOrdonnance(Ordonnance $ordonnance, array $input = [], array $lineInputs = [], ?array $onlyLineIds = null): array
    {
        if (IsabvConnection::activeFor($ordonnance->clinique_id) === null) {
            return [];
        }
        $ordonnance->loadMissing(['patient.client', 'patient.dernierePesee']);
        // Dans l'ordre de leur saisie : `$lineInputs` suit le même ordre
        $lignes = $ordonnance->lignes()->with('produit')->orderBy('id')->get();
        $preparations = $this->resolver->forProducts($lignes->pluck('produit')->filter());
        $lines = [];
        foreach ($lignes->values() as $index => $ligne) {
            $preparation = $preparations[$ligne->produit_id] ?? null;
            if ($preparation?->must_transfer && ($onlyLineIds === null || in_array($ligne->id, $onlyLineIds, true))) {
                $lines[] = [$ligne, $preparation, $lineInputs[$index] ?? []];
            }
        }
        if ($lines === []) {
            return [];
        }
        $patient = $ordonnance->patient;
        $species = isset($input['species_code']) && is_string($input['species_code']) && $input['species_code'] !== '' ? $input['species_code'] : self::defaultSpecies($patient);
        $form = ($input['is_stock_supply'] ?? false) === true && IsabvForm::forSpecies((string) $species) === IsabvForm::LivestockTreatment
            ? IsabvForm::StockSupply
            : (IsabvForm::forSpecies((string) $species) ?? IsabvForm::CompanionTreatment);

        return DB::transaction(function () use ($ordonnance, $input, $lines, $patient, $species, $form): array {
            $groups = $form === IsabvForm::StockSupply ? array_map(fn ($line) => [$line], $lines) : [$lines];
            $created = [];
            foreach ($groups as $group) {
                $declaration = AntibioticDeclaration::create([
                    'clinique_id' => $ordonnance->clinique_id,
                    'recipe_number' => (string) Str::uuid(),
                    'form' => $form,
                    'status' => DeclarationStatus::ToComplete,
                    'source_type' => $ordonnance->getMorphClass(),
                    'source_id' => $ordonnance->id,
                    'patient_id' => $patient->id,
                    'employe_id' => $ordonnance->employe_id,
                    'visit_on' => $ordonnance->signee_le,
                    ...$this->headerFrom($input, $patient, $species, $form),
                ]);
                foreach ($group as [$ligne, $preparation, $lineInput]) {
                    $declaration->lines()->create([
                        'produit_id' => $ligne->produit_id,
                        'ordonnance_ligne_id' => $ligne->id,
                        'preparation_code' => $preparation->code,
                        'route_code' => $preparation->route_code,
                        ...$this->lineFrom($lineInput, $declaration, $preparation->route_code),
                    ]);
                }
                $created[] = $this->settle($declaration);
            }

            return $created;
        });
    }

    /**
     * Le renouvellement d'une ordonnance est une nouvelle prescription : ses
     * déclarations reprennent celles de la précédente, sous un nouveau numéro,
     * datées du jour.
     *
     * @return list<AntibioticDeclaration>
     */
    public function forRenewal(Ordonnance $previous, Ordonnance $renewal): array
    {
        if (IsabvConnection::activeFor($renewal->clinique_id) === null) {
            return [];
        }
        $renewal->loadMissing('lignes');
        $lineIds = $previous->lignes()->orderBy('id')->pluck('id')->values();
        $newLineIds = $renewal->lignes->sortBy('id')->pluck('id')->values();
        $created = [];
        $sources = AntibioticDeclaration::query()->withoutGlobalScopes()->with('lines')
            ->where('source_type', $previous->getMorphClass())->where('source_id', $previous->id)
            ->whereNotIn('status', [DeclarationStatus::Replaced, DeclarationStatus::Deactivated])->get();
        // Une par chaîne de corrections : la correction en cours plutôt que l'envoyée qu'elle corrige
        $corrected = $sources->pluck('replaces_id')->filter()->all();
        $sources = $sources->reject(fn (AntibioticDeclaration $source) => in_array($source->id, $corrected, true));
        // Rien à recopier (signée avant que la clinique déclare, ou l'article relié depuis) : elle se déclare comme une neuve
        if ($sources->isEmpty()) {
            return $this->forOrdonnance($renewal);
        }
        foreach ($sources as $source) {
            $copy = $source->replicate(['recipe_number', 'status', 'osav_uuid', 'osav_message', 'ignored_fields', 'sent_at', 'sent_appendix', 'deactivated_at', 'is_outcome_unknown', 'attempts', 'replaces_id']);
            $copy->fill([
                'recipe_number' => (string) Str::uuid(),
                'status' => DeclarationStatus::ToComplete,
                'source_id' => $renewal->id,
                'employe_id' => $renewal->employe_id,
                'visit_on' => $renewal->signee_le,
                'attempts' => 0,
            ])->save();
            foreach ($source->lines as $line) {
                $position = $lineIds->search($line->ordonnance_ligne_id);
                $copy->lines()->create([...Arr::except($line->getAttributes(), ['id', 'declaration_id', 'created_at', 'updated_at', 'starts_on', 'ends_on']), 'ordonnance_ligne_id' => $position === false ? null : $newLineIds->get($position)]);
            }
            $created[] = $this->settle($copy);
        }
        // Un article relié à la liste depuis la signature : ses lignes se déclarent comme neuves
        $covered = AntibioticDeclarationLine::query()->whereIn('declaration_id', array_map(fn (AntibioticDeclaration $d) => $d->id, $created))->pluck('ordonnance_ligne_id')->filter()->all();
        $uncovered = $renewal->lignes->pluck('id')->reject(fn (int $id) => in_array($id, $covered, true))->values()->all();
        if ($uncovered !== []) {
            $created = [...$created, ...$this->forOrdonnance($renewal, [], [], $uncovered)];
        }

        return $created;
    }

    /**
     * Le vétérinaire complète ou corrige une déclaration. Envoyée, elle ne se
     * modifie plus : une nouvelle, sous un nouveau numéro, la remplace après
     * sa désactivation à l'OSAV (directives, ch. 5.9).
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, array<string, mixed>>|null  $lineInputs  par id de ligne
     */
    public function update(AntibioticDeclaration $declaration, array $input, ?array $lineInputs = null): AntibioticDeclaration
    {
        return DB::transaction(function () use ($declaration, $input, $lineInputs): AntibioticDeclaration {
            // Une correction : l'envoyée qu'elle corrige se verrouille d'abord (l'ordre des numéros, comme partout)
            $replacesId = AntibioticDeclaration::query()->whereKey($declaration->id)->value('replaces_id');
            if ($replacesId !== null) {
                AntibioticDeclaration::withoutGlobalScopes()->whereKey($replacesId)->lockForUpdate()->first();
            }
            $declaration = AntibioticDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->firstOrFail();
            if (in_array($declaration->status, [DeclarationStatus::Replaced, DeclarationStatus::Deactivated, DeclarationStatus::EnteredOnWeb], true)) {
                throw ValidationException::withMessages(['declaration' => 'Cette déclaration a été remplacée, désactivée ou saisie sur le site de l’OSAV : elle ne se modifie plus.']);
            }
            // Prise par l'envoi : son contenu part tel quel, il ne change pas en route
            if ($declaration->status === DeclarationStatus::Ready && $declaration->is_outcome_unknown) {
                throw ValidationException::withMessages(['declaration' => IsabvConnection::activeFor($declaration->clinique_id) !== null
                    ? 'Cette déclaration part en ce moment à l’OSAV : réessayez dans un instant.'
                    : 'Elle partait à l’OSAV au passage en variante 2 : vérifiez dans l’application web de l’OSAV, puis dites-la saisie sur le site.']);
            }
            $target = $declaration;
            // En variante 2, Veterinary n'envoie rien : une envoyée se corrige sur le site de l'OSAV
            if ($declaration->status === DeclarationStatus::Sent && IsabvConnection::activeFor($declaration->clinique_id) === null) {
                throw ValidationException::withMessages(['declaration' => 'La clinique déclare sur le site de l’OSAV : corrigez cette déclaration dans l’application web SI ABV.']);
            }
            if ($declaration->status === DeclarationStatus::Sent) {
                // Une correction déjà commencée se reprend : jamais deux remplaçantes de la même
                $pending = AntibioticDeclaration::query()->where('replaces_id', $declaration->id)
                    ->whereIn('status', [DeclarationStatus::ToComplete, DeclarationStatus::Ready, DeclarationStatus::Rejected])->lockForUpdate()->first();
                if ($pending !== null) {
                    $sameLines = $lineInputs === null ? null : [];
                    $old = $declaration->lines()->orderBy('id')->pluck('id')->values();
                    $new = $pending->lines()->orderBy('id')->pluck('id')->values();
                    foreach ($lineInputs ?? [] as $id => $values) {
                        $position = $old->search($id);
                        $sameLines[$position === false ? $id : $new->get($position, $id)] = $values;
                    }
                    $lineInputs = $sameLines;
                    $target = $pending;
                } else {
                    // L'envoyée reste active à l'OSAV tant que la correction n'est pas complète
                    $target = $declaration->replicate(['recipe_number', 'status', 'osav_uuid', 'osav_message', 'ignored_fields', 'sent_at', 'sent_appendix', 'deactivated_at', 'is_outcome_unknown', 'attempts']);
                    $target->fill(['recipe_number' => (string) Str::uuid(), 'status' => DeclarationStatus::ToComplete, 'replaces_id' => $declaration->id, 'attempts' => 0])->save();
                    foreach ($declaration->lines()->orderBy('id')->get() as $line) {
                        $newLine = $target->lines()->create(Arr::except($line->getAttributes(), ['id', 'declaration_id', 'created_at', 'updated_at']));
                        if ($lineInputs !== null && array_key_exists($line->id, $lineInputs)) {
                            $lineInputs[$newLine->id] = $lineInputs[$line->id];
                            unset($lineInputs[$line->id]);
                        }
                    }
                }
            }
            if ($target->status === DeclarationStatus::Rejected) {
                // Refusée par l'OSAV : la prescription corrigée part sous un nouveau numéro (directives, ch. 5.9)
                $target->fill(['recipe_number' => (string) Str::uuid(), 'osav_uuid' => null, 'is_outcome_unknown' => false]);
            }
            $species = array_key_exists('species_code', $input) ? $input['species_code'] : $target->species_code;
            $form = $target->form;
            if (array_key_exists('species_code', $input) || array_key_exists('is_stock_supply', $input)) {
                $forSpecies = IsabvForm::forSpecies((string) $species);
                $isStock = array_key_exists('is_stock_supply', $input) ? $input['is_stock_supply'] === true : $form === IsabvForm::StockSupply;
                $form = $isStock && $forSpecies === IsabvForm::LivestockTreatment ? IsabvForm::StockSupply : ($forSpecies ?? $form);
            }
            $target->fill(['form' => $form, ...$this->headerFrom($input, $target->patient, $species, $form, $target)])->save();
            foreach ($target->lines()->get() as $line) {
                if ($lineInputs !== null && array_key_exists($line->id, $lineInputs)) {
                    $line->fill($this->lineFrom($lineInputs[$line->id], $target, $line->route_code, $line))->save();
                }
            }
            // Une correction qui ne change rien à l'envoyée ne la remplace pas
            if ($target->replaces_id === $declaration->id && $declaration->status === DeclarationStatus::Sent && self::sameContent($declaration, $target->fresh())) {
                $target->delete();

                return $declaration;
            }

            return $this->settle($target->fresh());
        });
    }

    /**
     * Range la déclaration selon ce qui lui manque : complète, elle part
     * (après la fenêtre d'annulation) ; sinon elle attend le vétérinaire.
     */
    public function settle(AntibioticDeclaration $declaration): AntibioticDeclaration
    {
        if (! $declaration->status->isEditable()) {
            return $declaration;
        }
        $declaration->load('lines');
        $isReady = $this->rules->missing($declaration) === [] && $this->rules->webOnlyReason($declaration) === null;
        $declaration->update([
            'status' => $isReady ? DeclarationStatus::Ready : ($declaration->status === DeclarationStatus::Rejected ? DeclarationStatus::Rejected : DeclarationStatus::ToComplete),
            'osav_message' => $isReady ? null : $declaration->osav_message,
        ]);
        if ($isReady) {
            DB::afterCommit(fn () => SendAntibioticDeclaration::dispatch($declaration->id)->delay(now()->addSeconds(self::SEND_DELAY_SECONDS)));
        }
        if ($declaration->replaces_id !== null && $isReady) {
            // Complète : l'envoyée qu'elle corrige se désactive d'abord à l'OSAV, puis elle part
            $replaced = AntibioticDeclaration::withoutGlobalScopes()->whereKey($declaration->replaces_id)->lockForUpdate()->first();
            if ($replaced?->status === DeclarationStatus::Sent) {
                $replaced->update(['status' => DeclarationStatus::Replaced]);
            }
            DB::afterCommit(fn () => SendAntibioticDeclaration::dispatch($declaration->replaces_id));
        }

        return $declaration;
    }

    /**
     * Un antibiotique remis ou utilisé hors ordonnance (O-SI ABV, art. 2,
     * al. 1, let. b) : ajouté à une facture en caisse, ou prescrit pendant un
     * séjour d'hospitalisation. Une déclaration à compléter (le dosage n'est
     * pas saisi là), une préparation ; rien si la clinique ne déclare pas par
     * Veterinary ou si l'article n'est pas de la liste de l'OSAV.
     *
     * @param  array<string, mixed>  $lineInput
     */
    public function forProductUse(Model $source, int $cliniqueId, ?Patient $patient, Produit $produit, ?int $employeId, CarbonInterface|string $on, array $lineInput = [], ?float $perDay = null): ?AntibioticDeclaration
    {
        if (IsabvConnection::activeFor($cliniqueId) === null) {
            return null;
        }
        $preparation = $this->resolver->declarable($produit);
        if ($preparation === null) {
            return null;
        }
        $patient?->loadMissing(['client', 'dernierePesee']);
        $species = $patient !== null ? self::defaultSpecies($patient) : null;
        $form = IsabvForm::forSpecies((string) $species) ?? IsabvForm::CompanionTreatment;
        // Une dose dans l'unité de l'article (« 1 cp », « 2 ml ») vaut dans celle de l'OSAV si c'est la même
        $productDose = $lineInput['product_dose'] ?? null;
        unset($lineInput['product_dose']);
        if ($productDose !== null && self::isSameUnit((string) $produit->dose_unit, $preparation->unit_code)) {
            $lineInput['dose_1'] ??= $productDose;
        }
        if ($perDay !== null && ! array_key_exists('frequency_1', $lineInput)) {
            // Les heures de prise d'un traitement : « 2 administrations / jour » de l'OSAV, s'il y en a une
            $lineInput['frequency_1'] = IsabvCode::query()->ofKind(IsabvCode::KIND_FREQUENCY)->where('category', $form->frequencyCategory())
                ->where('per_day', $perDay)->value('code');
        }

        return DB::transaction(function () use ($source, $cliniqueId, $patient, $produit, $employeId, $on, $lineInput, $preparation, $species, $form): AntibioticDeclaration {
            $declaration = AntibioticDeclaration::create([
                'clinique_id' => $cliniqueId,
                'recipe_number' => (string) Str::uuid(),
                'form' => $form,
                'status' => DeclarationStatus::ToComplete,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'patient_id' => $patient?->id,
                'employe_id' => $employeId,
                'visit_on' => $on,
                ...$this->headerFrom([], $patient, $species, $form),
            ]);
            $declaration->lines()->create([
                'produit_id' => $produit->id,
                'preparation_code' => $preparation->code,
                'route_code' => $preparation->route_code,
                ...$this->lineFrom($lineInput, $declaration, $preparation->route_code),
            ]);

            return $this->settle($declaration);
        });
    }

    /**
     * Qui répond d'une déclaration née à l'hospitalisation : le vétérinaire du
     * séjour, sinon la personne du geste si elle prescrit ; sinon personne
     * (elle compte alors chez chacun qui prescrit, « Terminé à »).
     */
    public static function responsibleFor(?int $stayVetId): ?int
    {
        if ($stayVetId !== null) {
            return $stayVetId;
        }
        $context = app(ContexteClinique::class);

        return $context->estDefini() && $context->permet(DomainePermission::Prescrire) ? $context->employe()?->id : null;
    }

    /** Un traitement fini : arrêté, ou son séjour sorti (ses jours sont alors déclarés, une prise notée ou annulée ensuite les recompte) */
    public static function isTreatmentOver(StayTreatment $treatment): bool
    {
        return $treatment->ends_at !== null || Sejour::withoutGlobalScopes()->whereKey($treatment->sejour_id)->whereNotNull('sorti_le')->exists();
    }

    /**
     * Un traitement d'hospitalisation arrêté (ou le séjour sorti) : ses jours
     * de traitement sont les jours où une prise a été donnée (sans les prises
     * annulées), recomptés à chaque prise notée ou annulée ensuite ; la
     * consommation se recalcule (formule de l'OSAV).
     */
    public function treatmentStopped(StayTreatment $treatment): void
    {
        DB::transaction(function () use ($treatment): void {
            // La chaîne de ce traitement (l'envoyée, ses corrections) : on recompte sur la dernière, jamais sur une remplacée
            $chain = AntibioticDeclaration::withoutGlobalScopes()->with('lines')
                ->where('source_type', $treatment->getMorphClass())->where('source_id', $treatment->id)
                ->whereNotIn('status', [DeclarationStatus::Replaced, DeclarationStatus::Deactivated])->orderBy('id')->lockForUpdate()->get();
            // Verrouillée dans l'ordre des numéros, comme update (l'envoyée puis sa correction) : jamais d'interblocage
            $latest = $chain->last();
            if ($latest === null) {
                return;
            }
            $days = SejourSoin::query()->where('treatment_id', $treatment->id)->where('fait', true)->whereNull('cancelled_at')->distinct()->count('jour');
            if ($days === 0) {
                // Aucune prise donnée : rien n'a été utilisé ; partie ou en route, elle se désactive
                $this->cancelFor($treatment);

                return;
            }
            $line = $latest->lines->first();
            if ($line === null || $line->days_1 === $days) {
                return;
            }
            $toFix = 'Le traitement compte maintenant '.$days.' jour'.($days > 1 ? 's' : '').' : corrigez cette déclaration dans l’application web de l’OSAV.';
            $isSending = IsabvConnection::activeFor($latest->clinique_id) !== null;
            if ($latest->status === DeclarationStatus::EnteredOnWeb) {
                $latest->update(['osav_message' => $toFix]);

                return;
            }
            if ($latest->status === DeclarationStatus::Ready && $latest->is_outcome_unknown) {
                // En route : l'envoi recompte à sa réponse (SendAntibioticDeclaration) ; en variante 2, le vétérinaire le lit ici
                if (! $isSending) {
                    $latest->update(['osav_message' => $toFix]);
                }

                return;
            }
            if ($latest->status === DeclarationStatus::Rejected) {
                // Refusée : le vétérinaire lit d'abord le refus de l'OSAV ; les jours sont justes quand il la corrige et la renvoie
                $line->fill($this->lineFrom(['days_1' => $days], $latest, $line->route_code, $line))->save();

                return;
            }
            if ($latest->status === DeclarationStatus::Sent && ! $isSending) {
                $latest->update(['osav_message' => $toFix]);

                return;
            }
            // Une correction en attente se reprend par l'envoyée qu'elle corrige, encore active à l'OSAV
            // (revenue au contenu de l'envoyée, elle s'efface) ; l'envoyée déjà remplacée, la correction se reprend seule
            $sent = $chain->firstWhere('id', $latest->replaces_id);
            if ($sent !== null && $sent->status === DeclarationStatus::Sent && $isSending) {
                $sentLine = $sent->lines->first();
                if ($sentLine !== null) {
                    $this->update($sent, [], [$sentLine->id => ['days_1' => $days]]);

                    return;
                }
            }
            $this->update($latest, [], [$line->id => ['days_1' => $days]]);
        });
    }

    /** Deux déclarations disent la même chose à l'OSAV (en-tête et préparations) */
    private static function sameContent(AntibioticDeclaration $a, AntibioticDeclaration $b): bool
    {
        $header = ['form', 'species_code', 'weight_kg', 'diagnosis_key', 'diagnosis_description', 'is_antibiogram_done', 'animals_count', 'animals_total', 'holding_number', 'holding_address',
            'postcode', 'identification', 'additional_instructions', 'av_treatment_days', 'av_daily_dose'];
        $lineKeys = ['preparation_code', 'route_code', 'dose_1', 'frequency_1', 'days_1', 'dose_2', 'frequency_2', 'days_2', 'total_quantity', 'given_quantity', 'is_critical_confirmed'];
        $pick = fn (Model $model, array $keys): array => array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $model->only($keys));
        $lines = fn (AntibioticDeclaration $d): array => $d->lines()->orderBy('id')->get()->map(fn ($line) => $pick($line, $lineKeys))->all();

        return $pick($a, $header) == $pick($b, $header) && $lines($a) == $lines($b);
    }

    /** L'unité d'un article (« cp », « ml ») et celle d'une préparation de l'OSAV (« Stück », « ml ») disent la même chose */
    public static function isSameUnit(string $productUnit, string $osavUnit): bool
    {
        $normalize = fn (string $unit): string => match (mb_strtolower(trim($unit, " .\t"))) {
            'cp', 'comprimé', 'comprimés', 'pièce', 'pièces', 'stück', 'capsule', 'capsules', 'gélule', 'gélules', 'dose', 'doses', 'tube', 'tubes', 'injecteur', 'injecteurs' => 'piece',
            default => mb_strtolower(trim($unit)),
        };

        return $productUnit !== '' && $normalize($productUnit) === $normalize($osavUnit);
    }

    /** La source annulée (ordonnance, ligne de facture) : ses déclarations s'effacent, ou se désactivent à l'OSAV si elles sont parties. */
    public function cancelFor(Model $source): void
    {
        // Sous verrou : une prise par l'envoi au même instant attend, ou est vue
        $declarations = AntibioticDeclaration::query()->withoutGlobalScopes()
            ->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->orderBy('id')->lockForUpdate()->get();
        $isSending = $declarations->isNotEmpty() && IsabvConnection::activeFor($declarations->first()->clinique_id) !== null;
        foreach ($declarations as $declaration) {
            if ($declaration->status === DeclarationStatus::EnteredOnWeb) {
                // Saisie sur le site de l'OSAV : elle se désactive là-bas aussi, le vétérinaire le lit ici
                $declaration->update(['source_id' => null, 'source_type' => null, 'osav_message' => 'La prescription a été annulée : désactivez aussi cette déclaration dans l’application web de l’OSAV.']);

                continue;
            }
            $isAtOsav = in_array($declaration->status, [DeclarationStatus::Sent, DeclarationStatus::Replaced], true) || $declaration->is_outcome_unknown;
            if ($isAtOsav && ! $isSending) {
                // En variante 2, rien ne part : le vétérinaire la désactive sur le site de l'OSAV, il le lit ici
                $declaration->update(['source_id' => null, 'source_type' => null, 'osav_message' => 'La prescription a été annulée : désactivez aussi cette déclaration dans l’application web de l’OSAV.']);

                continue;
            }
            // Envoyée, ou remplacée et pas encore désactivée, ou en route : l'OSAV l'a peut-être, elle se désactive
            if ($isAtOsav) {
                $declaration->update(['status' => DeclarationStatus::Replaced, 'source_id' => null, 'source_type' => null]);
                DB::afterCommit(fn () => SendAntibioticDeclaration::dispatch($declaration->id));
            } elseif ($declaration->status !== DeclarationStatus::Deactivated) {
                $declaration->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function headerFrom(array $input, ?Patient $patient, mixed $species, IsabvForm $form, ?AntibioticDeclaration $current = null): array
    {
        $client = $patient?->client;
        $value = fn (string $key, mixed $default) => array_key_exists($key, $input) ? $input[$key] : ($current?->getAttribute($key) ?? $default);
        $holdingAddress = trim((string) $client?->holding_address) !== ''
            ? $client->holding_address
            : implode(', ', array_filter([trim((string) $client?->rue), trim(trim((string) $client?->npa).' '.trim((string) $client?->localite))]));

        return [
            'species_code' => is_string($species) && $species !== '' ? $species : null,
            // Le poids : demandé pour les animaux de compagnie (annexe, ch. 2.2.3), la dernière pesée d'office
            'weight_kg' => self::positive($value('weight_kg', $form === IsabvForm::CompanionTreatment ? $patient?->dernierePesee?->poids_kg : null)),
            'diagnosis_key' => self::text($value('diagnosis_key', null), 24),
            'diagnosis_description' => self::text($value('diagnosis_description', null), 255),
            'is_antibiogram_done' => (bool) $value('is_antibiogram_done', false),
            'animals_count' => self::count($value('animals_count', null)),
            'animals_total' => $form === IsabvForm::LivestockTreatment ? self::count($value('animals_total', null)) : null,
            // Vides, ils reprennent ceux de la fiche du détenteur (complétée entre-temps, par exemple)
            'holding_number' => $form->isLivestock() ? (self::text($value('holding_number', null), 32) ?? self::text($client?->holding_number, 32)) : null,
            'holding_address' => in_array($form, [IsabvForm::LivestockTreatment, IsabvForm::StockSupply, IsabvForm::ZooLab], true)
                ? (self::text($value('holding_address', null), 255) ?? self::text($holdingAddress, 255)) : null,
            // Facultatifs au XSD, absents de la liste des données de l'O-SI ABV : jamais remplis d'office
            'postcode' => $form === IsabvForm::CompanionTreatment ? self::text($value('postcode', null), 8) : null,
            'identification' => $form === IsabvForm::StockSupply || $form === IsabvForm::ZooLab ? null : self::text($value('identification', null), 255),
            'additional_instructions' => self::text($value('additional_instructions', null), 2000),
            'av_treatment_days' => $form === IsabvForm::StockSupply ? self::count($value('av_treatment_days', null)) : null,
            'av_daily_dose' => $form === IsabvForm::StockSupply ? self::positive($value('av_daily_dose', null)) : null,
        ];
    }

    /**
     * Une ligne saisie : la consommation totale suit la formule de l'OSAV,
     * sauf si le vétérinaire l'a saisie ; la quantité remise, à défaut, est
     * la consommation totale.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function lineFrom(array $input, AntibioticDeclaration $declaration, ?string $defaultRoute, ?AntibioticDeclarationLine $current = null): array
    {
        $value = fn (string $key) => array_key_exists($key, $input) ? $input[$key] : $current?->getAttribute($key);
        $line = [
            // Vidée exprès, la voie reste vide ; jamais saisie, celle de la préparation
            'route_code' => array_key_exists('route_code', $input) ? self::text($input['route_code'], 4) : ($current !== null ? $current->route_code : self::text($defaultRoute, 4)),
            'dose_1' => self::positive($value('dose_1')),
            'frequency_1' => self::text($value('frequency_1'), 8),
            'days_1' => self::count($value('days_1')),
            'dose_2' => self::positive($value('dose_2')),
            'frequency_2' => self::text($value('frequency_2'), 8),
            'days_2' => self::count($value('days_2')),
            'starts_on' => self::text($value('starts_on'), 10),
            'ends_on' => self::text($value('ends_on'), 10),
            'is_critical_confirmed' => (bool) $value('is_critical_confirmed'),
        ];
        $animals = $declaration->animals_count > 0 ? $declaration->animals_count : 1;
        $perDay = fn (?string $code) => $code === null ? null : IsabvCode::query()->ofKind(IsabvCode::KIND_FREQUENCY)->where('code', $code)->value('per_day');
        $computed = DeclarationRules::totalQuantity($line['dose_1'], $line['days_1'], $perDay($line['frequency_1']), $animals);
        if ($computed !== null && $line['dose_2'] !== null) {
            $second = DeclarationRules::totalQuantity($line['dose_2'], $line['days_2'], $perDay($line['frequency_2']), $animals);
            $computed = $second !== null ? round($computed + $second, 4) : $computed;
        }
        // Saisie, elle fait foi (et le reste tant qu'elle n'est pas vidée) ; sinon la formule
        $isEntered = array_key_exists('total_quantity', $input) ? self::positive($input['total_quantity']) !== null : (bool) $current?->is_total_entered;
        $total = $isEntered ? (array_key_exists('total_quantity', $input) ? self::positive($input['total_quantity']) : $current?->total_quantity) : $computed;
        $line['total_quantity'] = $total ?? $current?->total_quantity;
        $line['is_total_entered'] = $isEntered;
        // Saisie, la quantité remise fait foi ; sinon elle suit la consommation totale
        $isGivenEntered = array_key_exists('given_quantity', $input) ? self::nonNegative($input['given_quantity']) !== null : (bool) $current?->is_given_entered;
        $line['given_quantity'] = $isGivenEntered ? (array_key_exists('given_quantity', $input) ? self::nonNegative($input['given_quantity']) : $current?->given_quantity) : $line['total_quantity'];
        $line['is_given_entered'] = $isGivenEntered;

        return $line;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 4) : null;
    }

    private static function nonNegative(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 4) : null;
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1 ? (int) $value : null;
    }
}
