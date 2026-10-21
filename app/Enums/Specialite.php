<?php

namespace App\Enums;

/**
 * Spécialités d'un vétérinaire, cochées sur sa fiche par
 * l'administrateur. Celles d'une clinique sont celles des vétérinaires
 * qui y travaillent (rien à saisir pour la clinique). Les mots-clés
 * sont ceux d'un propriétaire : « hanche » trouve l'orthopédie.
 */
enum Specialite: string
{
    case Orthopedie = 'orthopedie';
    case TissusMous = 'tissus_mous';
    case Ophtalmologie = 'ophtalmologie';
    case Dentisterie = 'dentisterie';
    case Dermatologie = 'dermatologie';
    case Cardiologie = 'cardiologie';
    case Neurologie = 'neurologie';
    case Oncologie = 'oncologie';
    case MedecineInterne = 'medecine_interne';
    case Comportement = 'comportement';
    case Nac = 'nac';
    case Reproduction = 'reproduction';

    public function libelle(): string
    {
        return match ($this) {
            self::Orthopedie => 'Orthopédie',
            self::TissusMous => 'Chirurgie des tissus mous',
            self::Ophtalmologie => 'Ophtalmologie',
            self::Dentisterie => 'Dentisterie',
            self::Dermatologie => 'Dermatologie',
            self::Cardiologie => 'Cardiologie',
            self::Neurologie => 'Neurologie',
            self::Oncologie => 'Oncologie',
            self::MedecineInterne => 'Médecine interne',
            self::Comportement => 'Comportement',
            self::Nac => 'Nouveaux animaux de compagnie',
            self::Reproduction => 'Reproduction',
        };
    }

    /** Spécialité qui s'exerce au bloc (la clinique doit avoir la chirurgie pour opérer). */
    public function estChirurgicale(): bool
    {
        return in_array($this, [self::Orthopedie, self::TissusMous, self::Ophtalmologie, self::Dentisterie], true);
    }

    /** @return list<string> mots d'un propriétaire qui mènent à cette spécialité */
    public function motsCles(): array
    {
        return match ($this) {
            self::Orthopedie => ['os', 'hanche', 'genou', 'ligament', 'ligaments croisés', 'fracture', 'boiterie', 'dysplasie', 'articulation'],
            self::TissusMous => ['opération', 'stérilisation', 'castration', 'abdomen', 'tumeur', 'hernie'],
            self::Ophtalmologie => ['yeux', 'œil', 'oeil', 'cataracte', 'vue', 'paupière'],
            self::Dentisterie => ['dents', 'dent', 'détartrage', 'bouche', 'gencives'],
            self::Dermatologie => ['peau', 'allergie', 'démangeaisons', 'poils', 'oreilles'],
            self::Cardiologie => ['cœur', 'coeur', 'souffle'],
            self::Neurologie => ['nerfs', 'dos', 'colonne', 'épilepsie', 'paralysie'],
            self::Oncologie => ['cancer', 'tumeur', 'chimiothérapie'],
            self::MedecineInterne => ['reins', 'foie', 'diabète', 'thyroïde', 'digestion'],
            self::Comportement => ['agressivité', 'anxiété', 'éducation', 'stress'],
            self::Nac => ['lapin', 'furet', 'rongeur', 'oiseau', 'reptile', 'cochon d’inde', 'hamster', 'perroquet', 'tortue'],
            self::Reproduction => ['gestation', 'mise bas', 'saillie', 'insémination', 'chaleurs'],
        };
    }
}
