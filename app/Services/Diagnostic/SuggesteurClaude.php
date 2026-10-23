<?php

namespace App\Services\Diagnostic;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) propose un diagnostic différentiel en français,
 * en sortie JSON imposée par un schéma (`output_config.format`), avec un
 * effort de réflexion élevé (`output_config.effort`). Il ne voit ni le nom
 * de l'animal ni celui du propriétaire : le signalement, les comptes rendus
 * précédents, celui en cours et la transcription suffisent. Une erreur
 * temporaire (429, 5xx, surcharge) remonte pour être réessayée. Rien n'est
 * journalisé.
 */
final class SuggesteurClaude implements Suggesteur
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string CONSIGNES = <<<'TXT'
Tu assistes un vétérinaire praticien en Suisse pendant qu'il rédige le compte rendu d'une consultation. Tu reçois le signalement de l'animal, quelques antécédents du dossier, le compte rendu SOAP en cours et parfois la transcription automatique de la consultation. Tu proposes un diagnostic différentiel raisonné pour l'aider à réfléchir. Ce n'est qu'une suggestion : le vétérinaire a examiné l'animal, toi non, et c'est lui qui décide.

Règles :
1. Raisonne seulement à partir des éléments fournis. N'invente aucun signe clinique, résultat d'examen ni antécédent. La transcription automatique peut se tromper sur les mots, les chiffres et les noms de médicaments ; en cas de contradiction, le compte rendu du vétérinaire l'emporte.
2. Classe les hypothèses de la plus probable à la moins probable, en tenant compte de l'espèce, de la race, de l'âge, du sexe, du statut stérilisé et des antécédents. En général trois à six hypothèses ; moins si le tableau est évident.
3. Pour chaque hypothèse, donne les éléments du dossier qui vont dans son sens, ceux qui vont contre ou qui manquent, et les examens qui la confirmeraient ou l'écarteraient.
4. Signale à part les affections graves ou urgentes à ne pas manquer, même peu probables, avec ce qui doit les faire craindre.
5. Donne les examens à faire en premier, tous diagnostics confondus, du plus utile au moins utile.
6. Liste les informations qui manquent et qui changeraient le raisonnement (question au propriétaire, point d'examen clinique).
7. Ne propose ni traitement ni posologie : ce n'est pas l'objet.
8. Le diagnostic déjà écrit par le vétérinaire (rubrique A), s'il y en a un, fait partie des hypothèses à discuter, sans complaisance.
9. Si les éléments ne suffisent pas pour raisonner, laisse les hypothèses vides et dis dans la synthèse ce qu'il faudrait savoir.

Style : français, vocabulaire vétérinaire, concis. Une idée par élément de liste, en quelques mots (pas de phrase complète), sans phrase d'introduction. La synthèse tient en deux phrases au plus. Pas de Markdown, pas de tiret de ponctuation. Noms de maladies en français usuel, avec l'abréviation courante entre parenthèses quand elle existe.
TXT;

    public function suggerer(array $soap, array $signalement, array $antecedents, array $segments): ResultatSuggestion
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
                'max_tokens' => (int) config('diagnostic.max_tokens'),
                'system' => self::CONSIGNES,
                'messages' => [['role' => 'user', 'content' => $this->message($soap, $signalement, $antecedents, $segments)]],
                'output_config' => [
                    'effort' => config('diagnostic.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
            ]);

        if (in_array($reponse->status(), [429, 529], true) || $reponse->serverError()) {
            throw new RuntimeException('Claude indisponible (HTTP '.$reponse->status().', '.$reponse->json('error.type').').');
        }
        if (! $reponse->successful()) {
            throw new SuggestionImpossible('Claude refuse la requête (HTTP '.$reponse->status().', '.$reponse->json('error.type').').');
        }

        $fin = $reponse->json('stop_reason');
        if ($fin !== 'end_turn') {
            throw new SuggestionImpossible('Suggestion interrompue ('.$fin.').');
        }

        $texte = collect($reponse->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($texte) ? json_decode($texte, true) : null;
        if (! is_array($json)) {
            throw new SuggestionImpossible('Réponse illisible.');
        }

        return ResultatSuggestion::depuisJson(
            $json,
            (string) ($reponse->json('model') ?? config('services.anthropic.modele')),
            (int) $reponse->json('usage.input_tokens'),
            (int) $reponse->json('usage.output_tokens'),
        );
    }

    /**
     * @param  array{s: string, o: string, a: string, p: string}  $soap
     * @param  array<string, string>  $signalement
     * @param  list<array{date: string, motif: string, a: string, p: string}>  $antecedents
     * @param  list<array{partie: int, voix: int, debut: float, texte: string}>  $segments
     */
    private function message(array $soap, array $signalement, array $antecedents, array $segments): string
    {
        $blocs = ["Signalement de l'animal :\n".(collect($signalement)->map(fn (string $valeur, string $cle) => $cle.' : '.$valeur)->implode("\n") ?: 'non renseigné')];

        if ($antecedents !== []) {
            $blocs[] = "Comptes rendus précédents, du plus récent au plus ancien :\n".collect($antecedents)->map(fn (array $a) => implode("\n", array_filter([
                $a['date'].($a['motif'] !== '' ? ', motif : '.$a['motif'] : ''),
                $a['a'] !== '' ? 'Diagnostic : '.$a['a'] : '',
                $a['p'] !== '' ? 'Plan : '.$a['p'] : '',
            ])))->implode("\n\n");
        }

        $rubrique = fn (string $titre, string $texte) => $titre." :\n".($texte !== '' ? $texte : '(vide)');
        $blocs[] = "Compte rendu de la consultation en cours :\n".implode("\n", [
            $rubrique('S, motif et anamnèse', $soap['s']),
            $rubrique('O, examen clinique', $soap['o']),
            $rubrique('A, diagnostic du vétérinaire', $soap['a']),
            $rubrique('P, traitement et plan', $soap['p']),
        ]);

        if ($segments !== []) {
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
            $blocs[] = "Transcription automatique de la consultation :\n".implode("\n", $lignes);
        }

        return implode("\n\n", $blocs);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $liste = fn (string $description) => ['type' => 'array', 'description' => $description, 'items' => ['type' => 'string']];
        $objet = fn (array $proprietes) => [
            'type' => 'object',
            'properties' => $proprietes,
            'required' => array_keys($proprietes),
            'additionalProperties' => false,
        ];

        return $objet([
            'synthese' => ['type' => 'string', 'description' => 'Le tableau clinique en deux phrases au plus.'],
            'hypotheses' => [
                'type' => 'array',
                'description' => 'Diagnostics différentiels, du plus probable au moins probable. Liste vide si les éléments ne suffisent pas.',
                'items' => $objet([
                    'nom' => ['type' => 'string', 'description' => 'Le diagnostic, en français usuel.'],
                    'probabilite' => ['type' => 'string', 'enum' => ['elevee', 'moyenne', 'faible']],
                    'pour' => $liste('Éléments du dossier en faveur.'),
                    'contre' => $liste('Éléments du dossier contre, ou qui manquent pour la retenir.'),
                    'examens' => $liste('Examens qui la confirmeraient ou l\'écarteraient.'),
                ]),
            ],
            'alertes' => [
                'type' => 'array',
                'description' => 'Affections graves ou urgentes à ne pas manquer, même peu probables. Liste vide s\'il n\'y en a pas.',
                'items' => $objet([
                    'nom' => ['type' => 'string'],
                    'raison' => ['type' => 'string', 'description' => 'Ce qui doit la faire craindre ou l\'écarter, en une phrase.'],
                ]),
            ],
            'examens' => [
                'type' => 'array',
                'description' => 'Examens à faire en premier, tous diagnostics confondus, du plus utile au moins utile.',
                'items' => $objet([
                    'examen' => ['type' => 'string'],
                    'raison' => ['type' => 'string', 'description' => 'Ce qu\'il apporte, en une phrase.'],
                ]),
            ],
            'manques' => $liste('Informations qui manquent et changeraient le raisonnement.'),
        ]);
    }
}
