<?php

namespace App\Services\Transcription;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) rédige le brouillon SOAP en français, en sortie
 * JSON imposée par un schéma (`output_config.format`). Le modèle n'a que la
 * transcription pour source ; le dossier sert à comprendre, jamais à
 * remplir. Une erreur temporaire (429, 5xx, surcharge) remonte pour être
 * réessayée. Rien de la conversation n'est journalisé.
 */
final class RedacteurClaude implements Redacteur
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string CONSIGNES = <<<'TXT'
Tu aides un vétérinaire à rédiger le compte rendu de la consultation qu'il vient de faire. Tu reçois la transcription automatique de la conversation, voix numérotées, et quelques informations du dossier. Tu écris un brouillon au format SOAP que le vétérinaire relira, corrigera et validera.

Règles absolues :
1. N'invente rien. Chaque information du compte rendu doit être dite dans la transcription. Aucun diagnostic, examen, dose, traitement ou conseil supposé, même s'il est habituel.
2. Si une rubrique n'a rien dans la transcription, laisse-la vide (chaîne vide). Un compte rendu incomplet vaut mieux qu'un compte rendu faux.
3. Les informations du dossier servent seulement à comprendre la conversation (nom de l'animal, espèce, orthographe). Ne les recopie jamais dans les rubriques.
4. La transcription automatique peut se tromper, surtout sur les noms de médicaments, les doses, les chiffres et les mots qui se ressemblent. Écris ce que tu comprends le plus probablement. Chaque fois que tu as corrigé ou interprété un mot de la transcription, ajoute un doute :
   rubrique : s, o, a ou p ;
   passage : seulement le ou les mots que tu as corrigés (un à trois mots), recopiés exactement comme tu les as écrits dans la rubrique, jamais la phrase entière ;
   entendu : seulement les mots correspondants de la transcription, tels quels (par exemple « boîte fixe » pour le passage « boiterie ») ;
   raison : pourquoi tu doutes, en une phrase courte.
5. Une information qui manque ou reste ambiguë sans qu'aucun mot ait été corrigé (le côté d'une patte non dit, une voie d'administration non précisée, une dose inaudible) est aussi un doute, avec passage et entendu vides. N'en ajoute que si cela compte vraiment pour le dossier.

Rubriques :
s (Subjectif) : motif de la consultation, anamnèse, ce que rapporte le propriétaire (symptômes, durée, comportement, alimentation, traitements en cours).
o (Objectif) : constats de l'examen clinique énoncés par le vétérinaire (poids, température, fréquences, auscultation, palpation, muqueuses, examens réalisés et leurs résultats).
a (Évaluation) : diagnostic, hypothèses ou diagnostics différentiels énoncés par le vétérinaire.
p (Plan) : traitements administrés ou prescrits (produit, dose, voie, durée), examens à prévoir, conseils donnés, suivi et prochain rendez-vous.

Les voix sont numérotées automatiquement : déduis du contenu laquelle est celle du vétérinaire et laquelle celle du propriétaire. Une voix peut changer de numéro d'une partie à l'autre, et la séparation des voix peut être imparfaite.

Style : français, langage médical vétérinaire, concis. Phrases courtes, une information par ligne. Pas de titre, pas de mise en forme Markdown, pas de tiret de ponctuation. Nombres en chiffres avec leur unité (kg, °C, mg/kg, ml), y compris quand ils sont dits à la manière suisse romande (septante, huitante, nonante). Ignore les échanges sans intérêt médical (salutations, paiement, prise de rendez-vous), sauf le rendez-vous de suivi.
TXT;

    public function rediger(array $segments, array $contexte): BrouillonSoap
    {
        $reponse = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(300)
            ->post(self::URL, [
                'model' => config('services.anthropic.modele'),
                'max_tokens' => 4096,
                'system' => self::CONSIGNES,
                'messages' => [['role' => 'user', 'content' => $this->message($segments, $contexte)]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ]);

        if (in_array($reponse->status(), [429, 529], true) || $reponse->serverError()) {
            throw new RuntimeException('Claude indisponible (HTTP '.$reponse->status().', '.$reponse->json('error.type').').');
        }
        if (! $reponse->successful()) {
            throw new RedactionImpossible('Claude refuse la requête (HTTP '.$reponse->status().', '.$reponse->json('error.type').').');
        }

        $fin = $reponse->json('stop_reason');
        if ($fin !== 'end_turn') {
            throw new RedactionImpossible('Rédaction interrompue ('.$fin.').');
        }

        $texte = collect($reponse->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($texte) ? json_decode($texte, true) : null;
        if (! is_array($json)) {
            throw new RedactionImpossible('Réponse illisible.');
        }

        return BrouillonSoap::avecDoutes(
            trim((string) ($json['s'] ?? '')),
            trim((string) ($json['o'] ?? '')),
            trim((string) ($json['a'] ?? '')),
            trim((string) ($json['p'] ?? '')),
            (array) ($json['doutes'] ?? []),
        );
    }

    /**
     * @param  list<array{partie: int, voix: int, debut: float, texte: string}>  $segments
     * @param  array<string, string>  $contexte
     */
    private function message(array $segments, array $contexte): string
    {
        $dossier = collect($contexte)->map(fn (string $valeur, string $cle) => $cle.' : '.$valeur)->implode("\n");

        $lignes = [];
        $partie = null;
        foreach ($segments as $segment) {
            if ($segment['partie'] !== $partie) {
                $partie = $segment['partie'];
                $lignes[] = ($lignes === [] ? '' : "\n").'Partie '.$partie;
            }
            $secondes = (int) $segment['debut'];
            $lignes[] = sprintf('[%d:%02d] Voix %d : %s', intdiv($secondes, 60), $secondes % 60, $segment['voix'] + 1, $segment['texte']);
        }

        return "Informations du dossier, pour comprendre seulement :\n".($dossier ?: 'aucune')
            ."\n\nTranscription de la consultation :\n".implode("\n", $lignes);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $rubrique = fn (string $description) => ['type' => 'string', 'description' => $description.' Chaîne vide si la transcription n\'en dit rien.'];

        return [
            'type' => 'object',
            'properties' => [
                's' => $rubrique('Subjectif : motif, anamnèse, ce que rapporte le propriétaire.'),
                'o' => $rubrique('Objectif : constats de l\'examen clinique énoncés par le vétérinaire.'),
                'a' => $rubrique('Évaluation : diagnostic ou hypothèses énoncés par le vétérinaire.'),
                'p' => $rubrique('Plan : traitements, examens à prévoir, conseils, suivi.'),
                'doutes' => [
                    'type' => 'array',
                    'description' => 'Passages incertains à vérifier par le vétérinaire. Liste vide s\'il n\'y en a pas.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rubrique' => ['type' => 'string', 'enum' => ['s', 'o', 'a', 'p']],
                            'passage' => ['type' => 'string', 'description' => 'Le ou les mots corrigés (un à trois), recopiés exactement depuis la rubrique. Vide pour une information manquante ou ambiguë.'],
                            'entendu' => ['type' => 'string', 'description' => 'Les mots correspondants de la transcription, tels quels. Vide pour une information manquante ou ambiguë.'],
                            'raison' => ['type' => 'string', 'description' => 'Pourquoi ce passage est incertain, en une phrase courte.'],
                        ],
                        'required' => ['rubrique', 'passage', 'entendu', 'raison'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['s', 'o', 'a', 'p', 'doutes'],
            'additionalProperties' => false,
        ];
    }
}
