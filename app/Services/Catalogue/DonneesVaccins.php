<?php

namespace App\Services\Catalogue;

/**
 * Données curées du catalogue des vaccins (16.10.2026). Tout ce que le flux
 * Swissmedic ne dit pas en français ni de façon structurée :
 * - VALENCES : les maladies couvertes (code → nom, nom court, agent) ;
 * - AGENTS : mots de la description ATCvet (anglais, Swissmedic) → valences ;
 * - PRODUITS : par numéro d'autorisation, valences explicites quand le code
 *   ATC est générique, espèces si le nom ne suffit pas, et le schéma
 *   d'emploi résumé depuis l'information professionnelle. Les schémas
 *   marqués « indicatif » restent à confirmer produit par produit sur
 *   swissmedicinfo.ch : ils ne remplacent pas la lecture de la notice.
 * Les valeurs ici ne sont jamais écrasées par un import.
 */
final class DonneesVaccins
{
    public const SOURCE_CUREE = 'Information professionnelle Swissmedic, résumé curé le 16.10.2026';

    public const SOURCE_INDICATIVE = 'Schéma indicatif (16.10.2026), à confirmer sur l information professionnelle';

    /** @var array<string, array{nom: string, court: string, agent: string}> */
    public const VALENCES = [
        // Chiens
        'carre' => ['nom' => 'Maladie de Carré', 'court' => 'Carré', 'agent' => 'Canine distemper virus'],
        'hepatite' => ['nom' => 'Hépatite de Rubarth (adénovirose)', 'court' => 'Hépatite', 'agent' => 'Canine adenovirus'],
        'parvovirose' => ['nom' => 'Parvovirose canine', 'court' => 'Parvovirose', 'agent' => 'Canine parvovirus'],
        'parainfluenza' => ['nom' => 'Parainfluenza canin (toux de chenil)', 'court' => 'Parainfluenza', 'agent' => 'Canine parainfluenza virus'],
        'leptospirose' => ['nom' => 'Leptospirose', 'court' => 'Leptospirose', 'agent' => 'Leptospira'],
        'bordetellose' => ['nom' => 'Bordetellose (toux de chenil)', 'court' => 'Bordetella', 'agent' => 'Bordetella bronchiseptica'],
        'rage' => ['nom' => 'Rage', 'court' => 'Rage', 'agent' => 'Rabies virus'],
        'borreliose' => ['nom' => 'Borréliose de Lyme', 'court' => 'Lyme', 'agent' => 'Borrelia burgdorferi'],
        'herpes_canin' => ['nom' => 'Herpèsvirose canine (mortalité néonatale)', 'court' => 'Herpès canin', 'agent' => 'Canine herpesvirus'],
        'babesiose' => ['nom' => 'Babésiose (piroplasmose)', 'court' => 'Babésiose', 'agent' => 'Babesia canis'],
        'dermatophytose' => ['nom' => 'Dermatophytose (teigne)', 'court' => 'Teigne', 'agent' => 'Trichophyton, Microsporum'],
        // Chats
        'panleucopenie' => ['nom' => 'Typhus du chat (panleucopénie)', 'court' => 'Typhus', 'agent' => 'Feline panleukopenia virus'],
        'herpes_felin' => ['nom' => 'Herpèsvirose féline (coryza)', 'court' => 'Coryza (herpès)', 'agent' => 'Feline herpesvirus 1'],
        'calicivirose' => ['nom' => 'Calicivirose féline (coryza)', 'court' => 'Coryza (calici)', 'agent' => 'Feline calicivirus'],
        'leucose' => ['nom' => 'Leucose féline (FeLV)', 'court' => 'Leucose', 'agent' => 'Feline leukaemia virus'],
        'chlamydiose_feline' => ['nom' => 'Chlamydiose féline', 'court' => 'Chlamydiose', 'agent' => 'Chlamydia felis'],
        'pif' => ['nom' => 'Péritonite infectieuse féline (PIF)', 'court' => 'PIF', 'agent' => 'Feline coronavirus'],
        // Lapins
        'rhd' => ['nom' => 'Maladie hémorragique virale du lapin (RHD/VHD)', 'court' => 'RHD', 'agent' => 'Rabbit haemorrhagic disease virus 1 et 2'],
        'myxomatose' => ['nom' => 'Myxomatose', 'court' => 'Myxomatose', 'agent' => 'Myxoma virus'],
        // Chevaux
        'grippe_equine' => ['nom' => 'Grippe équine', 'court' => 'Grippe', 'agent' => 'Equine influenza virus'],
        'tetanos' => ['nom' => 'Tétanos', 'court' => 'Tétanos', 'agent' => 'Clostridium tetani (toxoïde)'],
        'rhinopneumonie' => ['nom' => 'Rhinopneumonie (herpèsvirus équins 1 et 4)', 'court' => 'Rhinopneumonie', 'agent' => 'Equine herpesvirus 1, 4'],
        'west_nile' => ['nom' => 'Fièvre du Nil occidental', 'court' => 'West Nile', 'agent' => 'West Nile virus'],
        'botulisme' => ['nom' => 'Botulisme', 'court' => 'Botulisme', 'agent' => 'Clostridium botulinum (toxoïde)'],
        'septicemie_poulain' => ['nom' => 'Septicémie néonatale du poulain (sérum)', 'court' => 'Septicémie poulain', 'agent' => 'Actinobacillus equuli, Streptococcus equi, Salmonella, E. coli'],
        // Bovins
        'fco' => ['nom' => 'Fièvre catarrhale ovine (maladie de la langue bleue)', 'court' => 'FCO', 'agent' => 'Bluetongue virus'],
        'brsv' => ['nom' => 'Virus respiratoire syncytial bovin (BRSV)', 'court' => 'BRSV', 'agent' => 'Bovine respiratory syncytial virus'],
        'pi3' => ['nom' => 'Parainfluenza 3 bovin', 'court' => 'PI3', 'agent' => 'Bovine parainfluenza virus 3'],
        'pasteurellose' => ['nom' => 'Pasteurellose (Mannheimia, Pasteurella)', 'court' => 'Pasteurellose', 'agent' => 'Mannheimia haemolytica, Pasteurella'],
        'mycoplasma_bovis' => ['nom' => 'Mycoplasmose à Mycoplasma bovis', 'court' => 'M. bovis', 'agent' => 'Mycoplasma bovis'],
        'rotavirus_bovin' => ['nom' => 'Diarrhée néonatale à rotavirus', 'court' => 'Rotavirus', 'agent' => 'Bovine rotavirus'],
        'coronavirus_bovin' => ['nom' => 'Diarrhée néonatale à coronavirus', 'court' => 'Coronavirus', 'agent' => 'Bovine coronavirus'],
        'colibacillose' => ['nom' => 'Colibacillose (E. coli)', 'court' => 'E. coli', 'agent' => 'Escherichia coli'],
        'clostridioses' => ['nom' => 'Clostridioses (entérotoxémie, charbon symptomatique…)', 'court' => 'Clostridioses', 'agent' => 'Clostridium spp.'],
        'keratoconjonctivite' => ['nom' => 'Kératoconjonctivite infectieuse (pink eye)', 'court' => 'Pink eye', 'agent' => 'Moraxella bovis'],
        'mammite_uberis' => ['nom' => 'Mammite à Streptococcus uberis', 'court' => 'Mammite S. uberis', 'agent' => 'Streptococcus uberis'],
        'mammite_staph' => ['nom' => 'Mammites à staphylocoques et E. coli', 'court' => 'Mammites', 'agent' => 'Staphylococcus aureus, E. coli'],
        'dictyocaulose' => ['nom' => 'Dictyocaulose (bronchite vermineuse)', 'court' => 'Dictyocaulose', 'agent' => 'Dictyocaulus viviparus'],
        'cryptosporidiose' => ['nom' => 'Cryptosporidiose du veau', 'court' => 'Cryptosporidiose', 'agent' => 'Cryptosporidium parvum'],
        'diarrhees_neonatales' => ['nom' => 'Diarrhées néonatales (anticorps passifs)', 'court' => 'Diarrhées néonatales', 'agent' => 'Rotavirus, coronavirus, E. coli (sérum)'],
        // Ovins, caprins
        'pietin' => ['nom' => 'Piétin', 'court' => 'Piétin', 'agent' => 'Dichelobacter nodosus'],
        'chlamydiose_ovine' => ['nom' => 'Avortement enzootique des brebis', 'court' => 'Chlamydiose', 'agent' => 'Chlamydia abortus'],
        // Porcs
        'circovirose' => ['nom' => 'Circovirose porcine (PCV2)', 'court' => 'PCV2', 'agent' => 'Porcine circovirus 2'],
        'maladie_oedeme' => ['nom' => 'Maladie de l’œdème (E. coli shigatoxine)', 'court' => 'Œdème', 'agent' => 'E. coli (Stx2e)'],
        'rouget' => ['nom' => 'Rouget du porc', 'court' => 'Rouget', 'agent' => 'Erysipelothrix rhusiopathiae'],
        'glasser' => ['nom' => 'Maladie de Glässer', 'court' => 'Glässer', 'agent' => 'Glaesserella (Haemophilus) parasuis'],
        'ileite' => ['nom' => 'Iléite porcine', 'court' => 'Iléite', 'agent' => 'Lawsonia intracellularis'],
        'parvovirose_porcine' => ['nom' => 'Parvovirose porcine', 'court' => 'Parvo porcine', 'agent' => 'Porcine parvovirus'],
        // Volaille
        'gumboro' => ['nom' => 'Maladie de Gumboro (bursite infectieuse)', 'court' => 'Gumboro', 'agent' => 'Infectious bursal disease virus'],
        'bronchite_infectieuse' => ['nom' => 'Bronchite infectieuse aviaire', 'court' => 'Bronchite inf.', 'agent' => 'Avian infectious bronchitis virus'],
        'rhinotracheite_aviaire' => ['nom' => 'Rhinotrachéite aviaire', 'court' => 'Rhinotrachéite', 'agent' => 'Avian metapneumovirus'],
        'marek' => ['nom' => 'Maladie de Marek', 'court' => 'Marek', 'agent' => 'Avian herpesvirus (Marek)'],
        'anemie_infectieuse' => ['nom' => 'Anémie infectieuse du poulet', 'court' => 'Anémie inf.', 'agent' => 'Chicken anaemia virus'],
        'coccidiose' => ['nom' => 'Coccidiose', 'court' => 'Coccidiose', 'agent' => 'Eimeria spp.'],
        'paramyxovirose_pigeon' => ['nom' => 'Paramyxovirose du pigeon', 'court' => 'Paramyxovirose', 'agent' => 'Pigeon paramyxovirus 1'],
    ];

    /**
     * Mots de la description ATCvet (Swissmedic, anglais) → valences. La
     * description est découpée sur « + » ; chaque morceau est reconnu par
     * son premier mot-clé trouvé ici.
     *
     * @var array<string, string>
     */
    public const AGENTS = [
        'RABIES' => 'rage',
        'CANINE DISTEMPER' => 'carre',
        'CANINE ADENOVIRUS' => 'hepatite',
        'CANINE PARVOVIRUS' => 'parvovirose',
        'CANINE PARAINFLUENZA' => 'parainfluenza',
        'PARAINFLUENZA VIRUS' => 'parainfluenza',
        'LEPTOSPIRA' => 'leptospirose',
        'BORDETELLA' => 'bordetellose',
        'BORRELIA' => 'borreliose',
        'CANINE HERPESVIRUS' => 'herpes_canin',
        'FELINE LEUKAEMIA' => 'leucose',
        'FELINE INFECTIOUS PERITONITIS' => 'pif',
        'FELINE RHINOTRACHEITIS' => 'herpes_felin',
        'FELINE CALICIVIRUS' => 'calicivirose',
        'FELINE PANLEUCOPENIA' => 'panleucopenie',
        'CHLAMYDIA FELIS' => 'chlamydiose_feline',
        'RABBIT HAEMORRHAGIC' => 'rhd',
        'MYXOMA' => 'myxomatose',
        'EQUINE INFLUENZA' => 'grippe_equine',
        'CLOSTRIDIUM TETANI' => 'tetanos',
        'TETANUS' => 'tetanos',
        'EQUINE RHINOPNEUMONITIS' => 'rhinopneumonie',
        'EQUINE ABORTION' => 'rhinopneumonie',
        'WEST NILE' => 'west_nile',
        'BLUETONGUE' => 'fco',
        'BOVINE RESPIRATORY SYNCYTIAL' => 'brsv',
        'RESPIRATORY SYNCYTIAL' => 'brsv',
        'BOVINE PARAINFLUENZA' => 'pi3',
        'PASTEURELLA' => 'pasteurellose',
        'MYCOPLASMA' => 'mycoplasma_bovis',
        'BOVINE ROTAVIRUS' => 'rotavirus_bovin',
        'BOVINE CORONAVIRUS' => 'coronavirus_bovin',
        'ESCHERICHIA' => 'colibacillose',
        'CLOSTRIDIUM' => 'clostridioses',
        'DICTYOCAULUS' => 'dictyocaulose',
        'CRYPTOSPORIDIUM' => 'cryptosporidiose',
        'TRICHOPHYTON' => 'dermatophytose',
        'MICROSPORUM' => 'dermatophytose',
        'BACTEROIDES' => 'pietin',
        'CHLAMYDIA' => 'chlamydiose_ovine',
        'PORCINE CIRCOVIRUS' => 'circovirose',
        'ERYSIPELOTHRIX' => 'rouget',
        'ACTINOBACILLUS/HAEMOPHILUS' => 'glasser',
        'LAWSONIA' => 'ileite',
        'PORCINE PARVOVIRUS' => 'parvovirose_porcine',
        'GUMBORO' => 'gumboro',
        'BURSAL' => 'gumboro',
        'INFECTIOUS BRONCHITIS' => 'bronchite_infectieuse',
        'RHINOTRACHEITIS VIRUS' => 'rhinotracheite_aviaire',
        'MAREK' => 'marek',
        'CHICKEN ANAEMIA' => 'anemie_infectieuse',
        'COCCIDIA' => 'coccidiose',
        'PIGEON PARAMYXOVIRUS' => 'paramyxovirose_pigeon',
    ];

    /**
     * Mots des noms de produits (allemand, parfois français) → codes Espece.
     *
     * @var array<string, list<string>>
     */
    public const ESPECES_PAR_MOT = [
        'hunde' => ['CH'], 'hund' => ['CH'], 'chiens' => ['CH'],
        'katzen' => ['CT'], 'katze' => ['CT'], 'chats' => ['CT'],
        'pferde' => ['CV'], 'pferd' => ['CV'], 'ponies' => ['CV'], 'equiden' => ['CV'], 'fohlen' => ['CV'], 'jährlinge' => ['CV'], 'chevaux' => ['CV'],
        'rinder' => ['BV'], 'rind' => ['BV'], 'kälber' => ['BV'], 'kühe' => ['BV'], 'färsen' => ['BV'], 'bovins' => ['BV'], 'veaux' => ['BV'],
        'wiederkäuer' => ['BV', 'OV', 'CP'],
        'schafe' => ['OV'], 'schaf' => ['OV'], 'lämmer' => ['OV'], 'ovins' => ['OV'], 'agneaux' => ['OV'],
        'ziegen' => ['CP'], 'ziege' => ['CP'],
        'schweine' => ['PO'], 'schwein' => ['PO'], 'sauen' => ['PO'], 'ferkel' => ['PO'], 'porcins' => ['PO'], 'porcs' => ['PO'],
        'frettchen' => ['FU'],
        'kaninchen' => ['LP'],
        'hühner' => ['VO'], 'hühnereiern' => ['VO'], 'küken' => ['VO'], 'eintagsküken' => ['VO'], 'tauben' => ['VO'], 'geflügel' => ['VO'],
    ];

    /** Groupe ATCvet (4 caractères) → espèce par défaut quand le nom n'en cite aucune. @var array<string, list<string>> */
    public const ESPECES_PAR_ATC = [
        'QI01' => ['VO'], 'QI02' => ['BV'], 'QI03' => ['CP'], 'QI04' => ['OV'], 'QI05' => ['CV'],
        'QI06' => ['CT'], 'QI07' => ['CH'], 'QI08' => ['LP'], 'QI09' => ['PO'],
    ];

    /** Voies Swissmedic (APPLIKATIONSART_CODE) → libellé. @var array<string, string> */
    public const VOIES = [
        'SUBC' => 'sous-cutanée', 'IM' => 'intramusculaire', 'INTM' => 'intramusculaire', 'NAS' => 'intranasale',
        'OR' => 'orale', 'PO' => 'orale', 'ID' => 'intradermique', 'INTD' => 'intradermique', 'IV' => 'intraveineuse',
        'OCUL' => 'oculaire', 'INOV' => 'in ovo', 'SPRAY' => 'pulvérisation', 'TRAU' => 'eau de boisson',
    ];

    /**
     * Par numéro d'autorisation : `valences` (quand le code ATC est générique
     * ou incomplet), `especes` (quand le nom ne les dit pas), `schema`.
     * Schéma : age (semaines), primo (injections), intervalle (semaines),
     * premier_rappel (mois), rappel (mois), voie, remarques, source.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PRODUITS = [
        // ——— Chiens ———
        '1631' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Carré, hépatite et parvovirose protégés 3 ans après le rappel à 1 an ; parainfluenza 1 an. Primo dès 6 semaines possible (3 injections).', 'source' => self::SOURCE_CUREE]],
        '1648' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 12, 'rappel' => 36, 'voie' => 'sous-cutanée',
            'remarques' => 'Rappel à 1 an, puis tous les 3 ans.', 'source' => self::SOURCE_CUREE]],
        '1632' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1746' => ['schema' => ['age' => 6, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 12, 'rappel' => 36, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1531' => ['schema' => ['age' => 3, 'primo' => 1, 'rappel' => 12, 'voie' => 'intranasale',
            'remarques' => 'Une dose, au moins 3 semaines avant une exposition (pension, exposition).', 'source' => self::SOURCE_CUREE]],
        '1771' => ['schema' => ['age' => 8, 'primo' => 1, 'rappel' => 12, 'voie' => 'orale', 'source' => self::SOURCE_CUREE]],
        '1776' => ['schema' => ['age' => 8, 'primo' => 1, 'rappel' => 12, 'voie' => 'intranasale', 'source' => self::SOURCE_CUREE]],
        '1715' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1745' => ['schema' => ['age' => 6, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1759' => ['schema' => ['age' => 7, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1736' => ['schema' => ['age' => 6, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Leptospirose et parainfluenza 1 an ; Carré, hépatite, parvovirose 3 ans.', 'source' => self::SOURCE_CUREE]],
        '1522' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1758' => ['schema' => ['age' => 7, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Leptospirose et parainfluenza 1 an ; Carré, hépatite, parvovirose 3 ans.', 'source' => self::SOURCE_CUREE]],
        '1744' => ['schema' => ['age' => 6, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1734' => ['valences' => ['carre', 'hepatite', 'parvovirose', 'parainfluenza', 'leptospirose', 'rage'],
            'schema' => ['age' => 12, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
                'remarques' => 'Rage : une dose dès 12 semaines, valable 3 ans ; leptospirose et parainfluenza 1 an.', 'source' => self::SOURCE_CUREE]],
        '1738' => ['schema' => ['age' => 12, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Idéalement avant la saison des tiques.', 'source' => self::SOURCE_CUREE]],
        '1604' => ['schema' => ['primo' => 2, 'voie' => 'sous-cutanée',
            'remarques' => 'Chiennes gestantes : 1re injection pendant les chaleurs ou 7 à 10 jours après la saillie, 2e 1 à 2 semaines avant la mise bas ; à chaque gestation.', 'source' => self::SOURCE_CUREE]],
        '1171' => ['valences' => ['babesiose'], 'schema' => ['age' => 22, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Rappel tous les 6 mois en zone à forte pression de tiques.', 'source' => self::SOURCE_CUREE]],
        '1500' => ['valences' => ['dermatophytose'], 'schema' => ['primo' => 2, 'intervalle' => 2, 'voie' => 'intramusculaire',
            'remarques' => 'Prévention et traitement de la teigne : 2 injections à 14 jours ; pas de rappel régulier.', 'source' => self::SOURCE_CUREE]],
        // Rage (plusieurs espèces)
        '1337' => ['schema' => ['age' => 12, 'primo' => 1, 'rappel' => 36, 'voie' => 'sous-cutanée',
            'remarques' => 'Chiens et chats : 3 ans. Chevaux, bovins, ovins, caprins : 2 ans. Furets : 1 an. Voyage : 21 jours minimum après la primo, puce avant l injection.', 'source' => self::SOURCE_CUREE]],
        '1191' => ['schema' => ['age' => 12, 'primo' => 1, 'rappel_premier' => 12, 'rappel' => 36, 'voie' => 'sous-cutanée',
            'remarques' => 'Chiens et chats : rappel à 1 an puis tous les 3 ans. Autres espèces : rappel annuel.', 'source' => self::SOURCE_CUREE]],
        '1766' => ['schema' => ['age' => 12, 'primo' => 1, 'rappel' => 36, 'voie' => 'sous-cutanée',
            'remarques' => 'Chiens et chats : 3 ans ; autres espèces : 1 an.', 'source' => self::SOURCE_CUREE]],
        '1747' => ['schema' => ['age' => 12, 'primo' => 1, 'rappel_premier' => 12, 'rappel' => 36, 'voie' => 'sous-cutanée',
            'remarques' => 'Rappel à 1 an, puis tous les 3 ans.', 'source' => self::SOURCE_CUREE]],
        // ——— Chats ———
        '1331' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1588' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1430' => ['schema' => ['age' => 16, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'intranasale', 'source' => self::SOURCE_CUREE]],
        '1700' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1644' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1561' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Typhus protégé 3 ans ; coryza 1 an.', 'source' => self::SOURCE_CUREE]],
        '1645' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Typhus protégé 3 ans ; coryza 1 an.', 'source' => self::SOURCE_CUREE]],
        '1717' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1658' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1653' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Typhus protégé 3 ans après le rappel à 1 an ; coryza 1 an.', 'source' => self::SOURCE_CUREE]],
        '1742' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        '1654' => ['valences' => ['herpes_felin', 'calicivirose', 'panleucopenie', 'chlamydiose_feline'],
            'schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_CUREE]],
        // ——— Lapins ———
        '1768' => ['schema' => ['age' => 10, 'primo' => 1, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Rappel tous les 6 mois en zone à risque. Protection dès 7 jours.', 'source' => self::SOURCE_CUREE]],
        // ——— Chevaux ———
        '1650' => ['schema' => ['age' => 26, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 5, 'rappel' => 12, 'voie' => 'intramusculaire',
            'remarques' => 'Primo : 2 injections à 4 semaines, 3e à 5 mois ; puis annuel. Compétition : vérifier le délai exigé (souvent 6 mois).', 'source' => self::SOURCE_CUREE]],
        '1651' => ['schema' => ['age' => 26, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 5, 'rappel' => 12, 'voie' => 'intramusculaire',
            'remarques' => 'Grippe annuelle ; tétanos protégé 2 ans après le 3e injection (rappel tétanos tous les 2 ans).', 'source' => self::SOURCE_CUREE]],
        '1674' => ['schema' => ['age' => 24, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 5, 'rappel' => 12, 'voie' => 'intramusculaire',
            'remarques' => 'Primo : 2 injections à 4 à 6 semaines, 3e à 5 mois ; puis annuel.', 'source' => self::SOURCE_CUREE]],
        '1617' => ['schema' => ['age' => 24, 'primo' => 2, 'intervalle' => 4, 'rappel_premier' => 5, 'rappel' => 12, 'voie' => 'intramusculaire',
            'remarques' => 'Grippe annuelle ; tétanos tous les 2 ans.', 'source' => self::SOURCE_CUREE]],
        '1458' => ['schema' => ['age' => 20, 'primo' => 2, 'intervalle' => 5, 'rappel' => 6, 'voie' => 'intramusculaire',
            'remarques' => 'Juments gestantes : aux 5e, 7e et 9e mois de gestation.', 'source' => self::SOURCE_CUREE]],
        '1710' => ['schema' => ['age' => 26, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'intramusculaire', 'source' => self::SOURCE_CUREE]],
        '69278' => ['schema' => ['age' => 26, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'intramusculaire', 'source' => self::SOURCE_CUREE]],
        '1615' => ['valences' => ['botulisme'], 'schema' => ['primo' => 2, 'intervalle' => 5, 'rappel' => 12, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_INDICATIVE]],
        '1590' => ['valences' => ['tetanos'], 'especes' => ['CV', 'OV', 'CP'], 'schema' => ['primo' => 1, 'voie' => 'sous-cutanée',
            'remarques' => 'Sérum : protection passive immédiate d environ 3 semaines (plaie, mise bas, chirurgie). Pas de rappel.', 'source' => self::SOURCE_CUREE]],
        '1084' => ['valences' => ['septicemie_poulain'], 'especes' => ['CV'], 'schema' => ['primo' => 1, 'voie' => 'intraveineuse',
            'remarques' => 'Sérum pour poulains nouveau-nés ; une dose.', 'source' => self::SOURCE_INDICATIVE]],
        // ——— Bovins ———
        '69876' => ['valences' => ['fco'], 'especes' => ['OV', 'BV'], 'schema' => ['primo' => 2, 'intervalle' => 3, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Bovins : 2 injections ; ovins : 1 injection. Sérotypes selon le produit.', 'source' => self::SOURCE_INDICATIVE]],
        '70251' => ['valences' => ['fco'], 'especes' => ['OV', 'BV'], 'schema' => ['primo' => 2, 'intervalle' => 3, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Sérotype 3. Autorisation provisoire.', 'source' => self::SOURCE_INDICATIVE]],
        '1598' => ['valences' => ['keratoconjonctivite'], 'schema' => ['primo' => 1, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => '3 à 6 semaines avant la saison à risque.', 'source' => self::SOURCE_INDICATIVE]],
        '1772' => ['valences' => ['mammite_uberis'], 'schema' => ['primo' => 3, 'voie' => 'intramusculaire',
            'remarques' => 'Par lactation : 60 jours avant le vêlage, 21 jours avant, 15 jours après.', 'source' => self::SOURCE_CUREE]],
        '1728' => ['schema' => ['age' => 2, 'primo' => 2, 'intervalle' => 5, 'rappel' => 12, 'voie' => 'sous-cutanée',
            'remarques' => 'Rappel 6 à 12 mois ; vaches 2 à 8 semaines avant le vêlage pour protéger le veau.', 'source' => self::SOURCE_INDICATIVE]],
        '1708' => ['valences' => ['mammite_staph'], 'schema' => ['primo' => 3, 'voie' => 'intramusculaire',
            'remarques' => 'Par lactation : 45 jours avant le vêlage, 10 jours avant, 52 jours après.', 'source' => self::SOURCE_CUREE]],
        '1777' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 6, 'voie' => 'intranasale', 'source' => self::SOURCE_INDICATIVE]],
        '1202' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 6, 'voie' => 'intranasale', 'source' => self::SOURCE_INDICATIVE]],
        '1767' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 3, 'voie' => 'intranasale', 'remarques' => 'Protection environ 12 semaines.', 'source' => self::SOURCE_INDICATIVE]],
        '1769' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 3, 'voie' => 'intranasale', 'remarques' => 'Protection environ 12 semaines.', 'source' => self::SOURCE_INDICATIVE]],
        '69279' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 3, 'voie' => 'intranasale', 'source' => self::SOURCE_INDICATIVE]],
        '1666' => ['schema' => ['age' => 1, 'primo' => 1, 'rappel' => 3, 'voie' => 'intranasale', 'source' => self::SOURCE_INDICATIVE]],
        '69494' => ['schema' => ['age' => 1, 'primo' => 1, 'voie' => 'intranasale', 'remarques' => 'Durée de protection selon l information professionnelle.', 'source' => self::SOURCE_INDICATIVE]],
        '1210' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire',
            'remarques' => 'Vaches gestantes : 6 puis 3 semaines avant le vêlage ; à chaque gestation (1 injection).', 'source' => self::SOURCE_INDICATIVE]],
        '1584' => ['valences' => ['pi3', 'brsv', 'pasteurellose'], 'schema' => ['age' => 2, 'primo' => 2, 'intervalle' => 4, 'rappel' => 6, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_INDICATIVE]],
        '1754' => ['schema' => ['primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Vaches gestantes : 1 injection 12 à 3 semaines avant le vêlage ; à chaque gestation.', 'source' => self::SOURCE_CUREE]],
        '1580' => ['schema' => ['primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Vaches gestantes : 1 injection 12 à 3 semaines avant le vêlage ; à chaque gestation.', 'source' => self::SOURCE_CUREE]],
        '69281' => ['schema' => ['primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Vaches gestantes : 1 injection 12 à 3 semaines avant le vêlage ; à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1753' => ['schema' => ['age' => 2, 'primo' => 2, 'intervalle' => 3, 'rappel' => 6, 'voie' => 'sous-cutanée', 'source' => self::SOURCE_INDICATIVE]],
        '1637' => ['valences' => ['diarrhees_neonatales'], 'especes' => ['BV', 'OV'], 'schema' => ['primo' => 1, 'voie' => 'sous-cutanée', 'remarques' => 'Sérum pour nouveau-nés ; une dose.', 'source' => self::SOURCE_INDICATIVE]],
        '1636' => ['valences' => ['diarrhees_neonatales'], 'especes' => ['BV', 'OV'], 'schema' => ['primo' => 1, 'voie' => 'orale', 'remarques' => 'Sérum oral pour nouveau-nés ; une dose dès la naissance.', 'source' => self::SOURCE_INDICATIVE]],
        '1559' => ['schema' => ['age' => 8, 'primo' => 2, 'intervalle' => 4, 'rappel' => 12, 'voie' => 'orale', 'remarques' => '2 doses à 4 semaines, la 2e au moins 2 semaines avant la mise à l herbe.', 'source' => self::SOURCE_CUREE]],
        '69778' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Vaches gestantes avant le vêlage ; à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1486' => ['schema' => ['primo' => 2, 'intervalle' => 2, 'voie' => 'intramusculaire', 'remarques' => '2 injections à 14 jours ; pas de rappel régulier.', 'source' => self::SOURCE_INDICATIVE]],
        // ——— Ovins, caprins ———
        '1085' => ['schema' => ['primo' => 2, 'intervalle' => 6, 'rappel' => 6, 'voie' => 'sous-cutanée', 'remarques' => 'Rappel 6 à 12 mois, avant la saison à risque.', 'source' => self::SOURCE_INDICATIVE]],
        '1589' => ['schema' => ['age' => 3, 'primo' => 2, 'intervalle' => 5, 'rappel' => 12, 'voie' => 'sous-cutanée', 'remarques' => 'Brebis : rappel 4 à 6 semaines avant l agnelage.', 'source' => self::SOURCE_INDICATIVE]],
        '1460' => ['schema' => ['primo' => 1, 'rappel' => 24, 'voie' => 'sous-cutanée', 'remarques' => '1 injection 4 mois avant la mise à la reproduction ; rappel tous les 2 à 3 ans.', 'source' => self::SOURCE_INDICATIVE]],
        // ——— Porcs (schémas indicatifs) ———
        '69282' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1701' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Truies : 2 injections avant la mise bas puis rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1684' => ['schema' => ['age' => 2, 'primo' => 1, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1743' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intradermique', 'source' => self::SOURCE_INDICATIVE]],
        '1691' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intramusculaire', 'remarques' => '1 injection dès 3 semaines, ou 2 injections à 3 semaines d intervalle dès 2 semaines.', 'source' => self::SOURCE_INDICATIVE]],
        '1761' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1723' => ['valences' => ['maladie_oedeme'], 'schema' => ['age' => 1, 'primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Porcelets dès 4 jours.', 'source' => self::SOURCE_INDICATIVE]],
        '1619' => ['valences' => ['colibacillose'], 'schema' => ['primo' => 2, 'intervalle' => 4, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes : 6 puis 2 semaines avant la mise bas ; rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1760' => ['valences' => ['maladie_oedeme'], 'schema' => ['age' => 1, 'primo' => 1, 'voie' => 'intramusculaire', 'remarques' => 'Porcelets dès 4 jours.', 'source' => self::SOURCE_INDICATIVE]],
        '1525' => ['schema' => ['primo' => 2, 'intervalle' => 4, 'rappel' => 6, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1579' => ['schema' => ['age' => 5, 'primo' => 2, 'intervalle' => 2, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1773' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes avant la mise bas ; rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1778' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes avant la mise bas ; rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1714' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes : 6 puis 3 semaines avant la mise bas ; rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '1741' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes avant la mise bas ; rappel à chaque gestation.', 'source' => self::SOURCE_INDICATIVE]],
        '69421' => ['schema' => ['primo' => 2, 'intervalle' => 3, 'voie' => 'intramusculaire', 'remarques' => 'Truies gestantes avant la mise bas (immunité passive des porcelets).', 'source' => self::SOURCE_INDICATIVE]],
        '1775' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intradermique', 'source' => self::SOURCE_INDICATIVE]],
        '1774' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1638' => ['schema' => ['age' => 3, 'primo' => 1, 'voie' => 'orale', 'source' => self::SOURCE_INDICATIVE]],
        '1740' => ['schema' => ['primo' => 2, 'intervalle' => 4, 'rappel' => 6, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1164' => ['valences' => ['parvovirose_porcine', 'rouget'], 'schema' => ['primo' => 2, 'intervalle' => 4, 'rappel' => 6, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
        '1572' => ['schema' => ['primo' => 2, 'intervalle' => 4, 'rappel' => 6, 'voie' => 'intramusculaire', 'source' => self::SOURCE_INDICATIVE]],
    ];
}
