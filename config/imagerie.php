<?php

/*
 * Radios annotées (décision de Jim, 28.10.2026) : listes fixes, servies
 * à l'app par GET /referentiels/imagerie. Des codes stables : un modèle
 * entraîné dans trois ans doit pouvoir trier les radios sans relire du
 * texte libre. Ajouter un code est sans risque ; en renommer un, jamais
 * (il est gardé tel quel sur les annotations déjà faites).
 */
return [

    'regions' => [
        'thorax' => 'Thorax',
        'abdomen' => 'Abdomen',
        'bassin' => 'Bassin',
        'colonne' => 'Colonne vertébrale',
        'crane' => 'Crâne',
        'dents' => 'Dents',
        'cou' => 'Cou',
        'membre_anterieur_gauche' => 'Membre antérieur gauche',
        'membre_anterieur_droit' => 'Membre antérieur droit',
        'membre_posterieur_gauche' => 'Membre postérieur gauche',
        'membre_posterieur_droit' => 'Membre postérieur droit',
        'corps_entier' => 'Corps entier',
        'autre' => 'Autre',
    ],

    'incidences' => [
        'lateral_droit' => 'Latéral droit',
        'lateral_gauche' => 'Latéral gauche',
        'ventro_dorsal' => 'Ventro-dorsal (VD)',
        'dorso_ventral' => 'Dorso-ventral (DV)',
        'cranio_caudal' => 'Cranio-caudal',
        'caudo_cranial' => 'Caudo-crânial',
        'medio_lateral' => 'Médio-latéral',
        'oblique' => 'Oblique',
        'autre' => 'Autre',
    ],

    /*
     * Le DICOM porte parfois la région (0018,0015) et l'incidence
     * (0018,5101) : seules les valeurs sans ambiguïté pré-remplissent,
     * un humain valide toujours. « LAT » ou « LEG » ne disent pas le côté :
     * rien n'est deviné.
     */
    'depuis_dicom' => [
        'regions' => [
            'CHEST' => 'thorax', 'THORAX' => 'thorax',
            'ABDOMEN' => 'abdomen',
            'PELVIS' => 'bassin',
            'SPINE' => 'colonne', 'CSPINE' => 'colonne', 'TSPINE' => 'colonne', 'LSPINE' => 'colonne', 'SSPINE' => 'colonne',
            'SKULL' => 'crane', 'HEAD' => 'crane',
            'NECK' => 'cou',
            'JAW' => 'dents', 'TEETH' => 'dents', 'DENTAL' => 'dents', 'TOOTH' => 'dents',
            'WHOLEBODY' => 'corps_entier',
        ],
        'incidences' => [
            'RL' => 'lateral_droit', 'RLD' => 'lateral_droit',
            'LL' => 'lateral_gauche', 'LLD' => 'lateral_gauche',
            'VD' => 'ventro_dorsal',
            'DV' => 'dorso_ventral',
        ],
    ],

    /* Ce qu'on dessine : le nombre de points de chaque géométrie (en pixels de l'image d'origine) */
    'types' => [
        'rectangle' => ['libelle' => 'Rectangle', 'points' => [2, 2]],
        'ellipse' => ['libelle' => 'Ellipse', 'points' => [2, 2]],
        'point' => ['libelle' => 'Point', 'points' => [1, 1]],
        'fleche' => ['libelle' => 'Flèche', 'points' => [2, 2]],
        'longueur' => ['libelle' => 'Longueur', 'points' => [2, 2]],
        'angle' => ['libelle' => 'Angle', 'points' => [3, 3]],
        'contour' => ['libelle' => 'Contour', 'points' => [3, 500]],
    ],

    'certitudes' => [
        'sur' => 'Sûr',
        'suspect' => 'Suspect',
    ],

    /* Étiquettes : code => [libellé, régions où on la propose d'abord (vide : partout)] */
    'etiquettes' => [
        'normal' => ['Sans lésion visible', []],
        // Thorax
        'cardiomegalie' => ['Cardiomégalie', ['thorax']],
        'mesure_vhs' => ['Mesure du VHS', ['thorax']],
        'epanchement_pleural' => ['Épanchement pleural', ['thorax']],
        'pneumothorax' => ['Pneumothorax', ['thorax']],
        'oedeme_pulmonaire' => ['Œdème pulmonaire', ['thorax']],
        'pneumonie' => ['Pneumonie', ['thorax']],
        'nodule_pulmonaire' => ['Nodule pulmonaire', ['thorax']],
        'masse_thoracique' => ['Masse thoracique', ['thorax']],
        'megaoesophage' => ['Mégaœsophage', ['thorax', 'cou']],
        'collapsus_tracheal' => ['Collapsus trachéal', ['thorax', 'cou']],
        'hernie_diaphragmatique' => ['Hernie diaphragmatique', ['thorax', 'abdomen']],
        // Abdomen
        'corps_etranger_gastrique' => ['Corps étranger gastrique', ['abdomen']],
        'corps_etranger_intestinal' => ['Corps étranger intestinal', ['abdomen']],
        'occlusion_intestinale' => ['Occlusion intestinale', ['abdomen']],
        'dilatation_torsion_estomac' => ['Dilatation-torsion de l’estomac', ['abdomen']],
        'ascite' => ['Ascite, épanchement abdominal', ['abdomen']],
        'masse_abdominale' => ['Masse abdominale', ['abdomen']],
        'splenomegalie' => ['Splénomégalie', ['abdomen']],
        'hepatomegalie' => ['Hépatomégalie', ['abdomen']],
        'calcul_renal' => ['Calcul rénal', ['abdomen']],
        'calcul_vesical' => ['Calcul vésical', ['abdomen', 'bassin']],
        'calcul_uretral' => ['Calcul urétral', ['abdomen', 'bassin']],
        'fecalome' => ['Fécalome, constipation', ['abdomen', 'bassin']],
        'gestation' => ['Gestation', ['abdomen']],
        // Squelette
        'fracture' => ['Fracture', []],
        'luxation' => ['Luxation', []],
        'dysplasie_hanche' => ['Dysplasie de la hanche', ['bassin']],
        'angle_norberg' => ['Angle de Norberg-Olsson', ['bassin']],
        'dysplasie_coude' => ['Dysplasie du coude', ['membre_anterieur_gauche', 'membre_anterieur_droit']],
        'arthrose' => ['Arthrose', []],
        'lyse_osseuse' => ['Lyse osseuse', []],
        'spondylose' => ['Spondylose', ['colonne']],
        'hernie_discale' => ['Hernie discale', ['colonne']],
        // Dents
        'resorption_dentaire' => ['Résorption dentaire', ['dents']],
        'abces_dentaire' => ['Abcès dentaire', ['dents']],
        'fracture_dentaire' => ['Fracture dentaire', ['dents']],
        'parodontite' => ['Parodontite', ['dents']],
        // Partout
        'corps_etranger' => ['Corps étranger', []],
        'masse' => ['Masse', []],
        'autre' => ['Autre (préciser en note)', []],
    ],

    /* Ce qui confirme (ou infirme) un diagnostic, après coup */
    'sources' => [
        'chirurgie' => 'Chirurgie',
        'histologie' => 'Histologie',
        'cytologie' => 'Cytologie',
        'echographie' => 'Échographie',
        'scanner' => 'Scanner',
        'irm' => 'IRM',
        'evolution' => 'Évolution clinique',
        'necropsie' => 'Nécropsie',
        'autre' => 'Autre',
    ],

    'resultats' => [
        'confirme' => 'Confirmé',
        'infirme' => 'Infirmé',
    ],

    /* Taille maximale d'un fichier DICOM envoyé (Ko) ; la production accepte 100 Mo */
    'taille_max_ko' => 100 * 1024,
];
