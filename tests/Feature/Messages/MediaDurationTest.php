<?php

namespace Tests\Feature\Messages;

use App\Services\Messages\ByteSource;
use App\Services\Messages\MediaDuration;
use Tests\Doubles\EnregistrementFactice;
use Tests\TestCase;

/**
 * La vraie durée d'un enregistrement (deuxième vérification, 07.11.2026) :
 * un fichier forgé de quelques Ko ou Mo répond en moins d'une seconde, et
 * une durée forgée ne cache pas la vraie.
 */
class MediaDurationTest extends TestCase
{
    /** @return array{0: ?float, 1: float} la durée lue et le temps de lecture, en secondes */
    private function lire(string $bytes, string $container): array
    {
        $debut = hrtime(true);
        $secondes = MediaDuration::seconds($bytes, $container);

        return [$secondes, (hrtime(true) - $debut) / 1e9];
    }

    private static function box(string $type, string $data): string
    {
        return pack('N', 8 + strlen($data)).$type.$data;
    }

    /* ————— G1 : rien ne bloque un worker ————— */

    public function test_des_milliers_de_trun_qui_annoncent_quatre_milliards_d_echantillons_se_lisent_en_moins_d_une_seconde(): void
    {
        $trun = self::box('trun', "\x00\x00\x01\x00".pack('N', 0xFFFFFFFF));
        $moof = self::box('moof', self::box('traf', self::box('tfhd', "\x00\x02\x00\x00".pack('N', 1)).str_repeat($trun, 65000)));
        $fichier = self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5').$moof;
        $this->assertGreaterThan(1000000, strlen($fichier));

        [, $temps] = $this->lire($fichier, 'mp4');

        $this->assertLessThan(1.0, $temps);
    }

    public function test_un_trun_sans_duree_par_echantillon_se_calcule_sans_boucle(): void
    {
        // Durée par défaut 960 (20 ms à 48 kHz), 3000 échantillons : 60 s
        $tfhd = self::box('tfhd', "\x00\x02\x00\x08".pack('NN', 1, 960));
        $trun = self::box('trun', "\x00\x00\x00\x00".pack('N', 3000));
        $mdhd = self::box('mdhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 48000, 0).pack('N', 0));
        $tkhd = self::box('tkhd', "\x00\x00\x00\x07".pack('NNN', 0, 0, 1).str_repeat("\x00", 68));
        $fichier = self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5')
            .self::box('moov', self::box('trak', $tkhd.self::box('mdia', $mdhd)))
            .self::box('moof', self::box('traf', $tfhd.$trun));

        $this->assertEqualsWithDelta(60.0, MediaDuration::seconds($fichier, 'mp4'), 0.01);
    }

    public function test_des_moov_imbriques_sans_fin_sont_refuses_vite(): void
    {
        // 200 000 boîtes moov imbriquées (1,6 Mo)
        $profondeur = 200000;
        $fichier = '';
        for ($i = 0; $i < $profondeur; $i++) {
            $fichier .= pack('N', ($profondeur - $i) * 8).'moov';
        }
        $this->assertSame(1600000, strlen($fichier));

        [$secondes, $temps] = $this->lire(self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5').$fichier, 'mp4');

        $this->assertNull($secondes);
        $this->assertLessThan(1.0, $temps);

        // Huit niveaux au plus : une durée cachée au dixième n'est pas lue
        $cache = self::box('mvhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 1000, 7400).str_repeat("\x00", 80));
        for ($i = 0; $i < 10; $i++) {
            $cache = self::box('moov', $cache);
        }
        $this->assertNull(MediaDuration::seconds(self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5').$cache, 'mp4'));
    }

    public function test_un_element_webm_geant_ou_mal_forme_est_refuse_vite(): void
    {
        $tete = "\x1A\x45\xDF\xA3\x87\x42\x82\x84webm"."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
        // Un Timecode de 3 Mo
        $geant = $tete."\xE7\x01".substr(pack('J', 3 * 1024 * 1024), 1).str_repeat("\xFF", 3 * 1024 * 1024);
        [$secondes, $temps] = $this->lire($geant, 'webm');
        $this->assertNull($secondes);
        $this->assertLessThan(1.0, $temps);

        $bloc = "\xE7\x82\x1C\xE8"."\xA3\x88\x81\x00\x00\x80opus";
        $this->assertEqualsWithDelta(7.4, MediaDuration::seconds($tete.$bloc, 'webm'), 0.01);
        // Un entier EBML de plus de 8 octets (premier octet nul)
        $this->assertNull(MediaDuration::seconds($tete.$bloc."\x00\x00\x81\x01", 'webm'));
        // Un élément plus long que le reste du fichier
        $this->assertNull(MediaDuration::seconds($tete.$bloc."\xEC\x88\x00\x00\x00\x00", 'webm'));
        // Un nombre de plus de 8 octets
        $this->assertNull(MediaDuration::seconds($tete."\xE7\x89".str_repeat("\x00", 8)."\x01"."\xA3\x88\x81\x00\x00\x80opus", 'webm'));
    }

    public function test_plus_de_vingt_pistes_rendent_le_fichier_illisible_sans_garder_de_trace_par_piste(): void
    {
        $fichier = EnregistrementFactice::webmPistesInnombrables(400000);
        memory_reset_peak_usage();
        $avant = memory_get_usage();

        [$secondes, $temps] = $this->lire($fichier, 'webm');

        $this->assertNull($secondes);
        $this->assertLessThan(1.0, $temps);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $avant);
        // Une piste non déclarée dans Tracks : illisible aussi
        $this->assertNull(MediaDuration::seconds(EnregistrementFactice::webmPistesInnombrables(1, declarer: true, pisteDuBloc: 2), 'webm'));
        // Une ou deux pistes déclarées (son, image) : lisible
        $this->assertEqualsWithDelta(7.4, MediaDuration::seconds(EnregistrementFactice::webm(7400), 'webm'), 0.01);
    }

    public function test_plus_de_vingt_pistes_mp4_rendent_le_fichier_illisible(): void
    {
        $tkhd = fn (int $id) => self::box('tkhd', "\x00\x00\x00\x07".pack('NNN', 0, 0, $id).str_repeat("\x00", 68));
        $mdhd = self::box('mdhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 48000, 48000).pack('N', 0));
        $traks = '';
        for ($id = 1; $id <= 21; $id++) {
            $traks .= self::box('trak', $tkhd($id).self::box('mdia', $mdhd));
        }
        $this->assertNull(MediaDuration::seconds(self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5').self::box('moov', $traks), 'mp4'));

        // Des fragments de 21 pistes différentes
        $fragments = '';
        for ($id = 1; $id <= 21; $id++) {
            $fragments .= self::box('moof', self::box('traf', self::box('tfhd', "\x00\x02\x00\x08".pack('NN', $id, 960)).self::box('trun', "\x00\x00\x00\x00".pack('N', 10))));
        }
        $this->assertNull(MediaDuration::seconds(self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5').$fragments, 'mp4'));
    }

    /** Quatrième vérification : une boîte lue de 20 Mo n'est plus copiée en entier */
    public function test_une_boite_mp4_geante_ne_fait_pas_monter_la_memoire(): void
    {
        foreach (['stts', 'trun', 'mdhd'] as $type) {
            $fichier = EnregistrementFactice::mp4BoiteGeante($type, 20 * 1000 * 1000);
            gc_collect_cycles();
            memory_reset_peak_usage();
            $avant = memory_get_usage();

            [, $temps] = $this->lire($fichier, 'mp4');

            $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $avant, $type);
            $this->assertLessThan(1.0, $temps, $type);
        }
    }

    /** Cinquième vérification : la poignée se ferme si la taille ne se lit pas après l'ouverture */
    public function test_une_source_dont_la_taille_ne_se_lit_pas_ne_laisse_aucune_poignee_ouverte(): void
    {
        $avant = count(get_resources('stream'));

        $this->assertNull(ByteSource::fromFile('php://memory'));

        $this->assertSame($avant, count(get_resources('stream')));
    }

    /* ————— M1 : une durée forgée ne cache pas la vraie ————— */

    public function test_ogg_une_fausse_derniere_page_ne_cache_pas_l_heure_enregistree(): void
    {
        $uneHeure = 3600000;
        // Ajoutée à la fin, une page au granule bas (CRC juste ou faux)
        foreach ([true, false] as $crcJuste) {
            $fichier = EnregistrementFactice::ogg($uneHeure, 20000, EnregistrementFactice::pageOgg(312 + 390 * 48, $crcJuste));
            $this->assertEqualsWithDelta(3600.0, MediaDuration::seconds($fichier, 'ogg'), 0.1);
        }
        // Une page au CRC faux n'est jamais crue, même au granule le plus haut
        $fichier = EnregistrementFactice::ogg(7400, 4000, EnregistrementFactice::pageOgg(312 + 3600000 * 48, false));
        $this->assertEqualsWithDelta(7.4, MediaDuration::seconds($fichier, 'ogg'), 0.01);
    }

    public function test_webm_des_blocs_tous_au_temps_zero_ne_disent_pas_zero_seconde(): void
    {
        $this->assertNull(MediaDuration::seconds(EnregistrementFactice::webmBlocsAuTempsZero(), 'webm'));
    }

    public function test_mp4_la_table_stts_dit_la_vraie_longueur(): void
    {
        $this->assertEqualsWithDelta(3600.0, MediaDuration::seconds(EnregistrementFactice::mp4Stts(2000, 3600000), 'mp4'), 0.1);
    }
}
