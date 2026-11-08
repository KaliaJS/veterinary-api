<?php

namespace Tests\Feature\Messages;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\MessageAttachment;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\User;
use App\Services\Messages\MessageAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\EnregistrementFactice;
use Tests\TestCase;

/**
 * Les pièces jointes des messages (décisions de Jim, 08.11.2026) : photos
 * (JPEG, PNG, HEIC converti) et PDF, dix par message, 20 Mo chacun ; tout
 * le monde en envoie, propriétaires compris ; « Document du dossier » côté
 * clinique ; un plafond par jour pour le propriétaire ; un compte effacé
 * emporte ses pièces jointes.
 */
class MessageAttachmentsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $julieToken;

    private User $owner;

    private string $ownerToken;

    private Client $fiche;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives Lausanne', 'ville' => 'Lausanne']);
        ['jeton' => $this->julieToken] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller'], 'asv');
        ['user' => $this->owner, 'jeton' => $this->ownerToken] = $this->proprietaireAvecJeton();
        $this->fiche = Client::factory()->create(['user_id' => $this->owner->id, 'organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Edward', 'nom' => 'Fournier']);
    }

    /** Un JPEG avec un bloc EXIF : une orientation, et un texte qui ne doit jamais être rangé */
    private static function jpeg(int $w, int $h, int $orientation = 1, string $secret = 'GPS 46.52N 6.63E'): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 60, 60));
        ob_start();
        imagejpeg($im);
        $jpg = (string) ob_get_clean();
        $desc = $secret."\0";
        $ifd = pack('v', 2).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('vvVV', 0x010E, 2, strlen($desc), 8 + 2 + 24 + 4).pack('V', 0);
        $app1 = "Exif\0\0".'II*'."\0".pack('V', 8).$ifd.$desc;

        return substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
    }

    private static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** Un PNG de bruit, qui ne se compresse pas (quelques dizaines de Ko) */
    private static function noise(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private static function upload(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pj');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private static function pdf(string $name = 'bilan.pdf'): UploadedFile
    {
        return self::upload("%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF", $name);
    }

    private function sendAsClinic(array $data, string $cle = ''): TestResponse
    {
        return $this->post('/api/v1/messages/'.($cle ?: 'u'.$this->owner->id).'/pieces', $data, [...$this->enTetes($this->julieToken), 'Accept' => 'application/json']);
    }

    public function test_photos_are_reencoded_without_their_metadata_turned_upright_and_shrunk_with_a_thumbnail(): void
    {
        $response = $this->sendAsClinic([
            'texte' => 'Voici la plaie avant et après.',
            'files' => [self::upload(self::jpeg(3000, 1200, 6), 'IMG_0042.HEIC.jpg'), self::upload(self::png(300, 200), 'capture.png')],
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertSame('Voici la plaie avant et après.', $data['texte']);
        $this->assertSame('Voici la plaie avant et après.', $data['caption']);
        [$photo, $capture] = $data['attachments'];
        // Orientation 6 : tournée d'un quart ; réduite à 2000 px de côté
        $this->assertSame(['image', 'image/jpeg', 'IMG_0042.HEIC.jpg', 800, 2000, true], [$photo['kind'], $photo['mime'], $photo['name'], $photo['width'], $photo['height'], $photo['has_thumb']]);
        $this->assertSame(['image/png', 'capture.png', 300, 200], [$capture['mime'], $capture['name'], $capture['width'], $capture['height']]);

        $rangee = MessageAttachment::query()->findOrFail($photo['id']);
        $octets = Storage::disk('prive')->get($rangee->path);
        $this->assertStringNotContainsString('GPS 46.52N', $octets);
        $this->assertStringNotContainsString('Exif', $octets);
        $apercu = getimagesizefromstring(Storage::disk('prive')->get($rangee->thumb_path));
        $this->assertSame([192, 480], [$apercu[0], $apercu[1]]);
    }

    public function test_a_pdf_keeps_its_bytes_and_a_message_without_caption_says_what_it_is(): void
    {
        $data = $this->sendAsClinic(['files' => [self::pdf('Bilan sanguin.pdf')]])->assertCreated()->json('data');

        $this->assertSame('Document : Bilan sanguin.pdf', $data['texte']);
        $this->assertNull($data['caption']);
        $this->assertSame(['pdf', 'application/pdf', false], [$data['attachments'][0]['kind'], $data['attachments'][0]['mime'], $data['attachments'][0]['has_thumb']]);
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('prive')->get(MessageAttachment::query()->sole()->path));

        $this->assertSame('2 photos', $this->sendAsClinic(['files' => [self::upload(self::png(10, 10), 'a.png'), self::upload(self::png(10, 10), 'b.png')]])->json('data.texte'));
        $this->assertSame('2 fichiers', $this->sendAsClinic(['files' => [self::upload(self::png(10, 10), 'a.png'), self::pdf()]])->json('data.texte'));
    }

    public function test_only_photos_and_pdfs_ten_at_most_twenty_megabytes_each(): void
    {
        // Le genre se lit dans les octets, jamais dans le nom
        $refus = $this->sendAsClinic(['files' => [self::upload('MZ'.str_repeat('x', 100), 'facture.pdf')]])->assertUnprocessable();
        $this->assertSame('« facture » n’est ni une photo (JPEG, PNG, HEIC) ni un PDF.', $refus->json('errors')['files.0'][0]);
        $this->sendAsClinic(['files' => [self::upload("PK\x03\x04", 'rapport.docx')]])->assertUnprocessable();
        $this->sendAsClinic(['files' => array_map(fn (int $i): UploadedFile => self::pdf('d'.$i.'.pdf'), range(1, 11))])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->sendAsClinic(['files' => [UploadedFile::fake()->create('lourd.pdf', 20 * 1024 + 1, 'application/pdf')]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->sendAsClinic([])->assertUnprocessable()->assertJsonPath('errors.files.0', 'Choisissez au moins une photo ou un document.');
        // Un fichier refusé au milieu : rien n'est rangé, aucun message
        $this->sendAsClinic(['files' => [self::pdf(), self::upload('texte', 'notes.pdf')]])->assertUnprocessable();
        $this->assertSame(0, MessageClientele::query()->count());
        $this->assertSame([], Storage::disk('prive')->allFiles('messages'));
    }

    public function test_a_heic_from_the_iphone_becomes_a_jpeg(): void
    {
        if (! MessageAttachmentService::heifSupported()) {
            $this->markTestSkipped('Imagick sans HEIC sur cette machine (Laravel Cloud l’a, vérifié le 08.11.2026).');
        }
        // Un vrai HEIC (libheif, fait sur Laravel Cloud le 08.11.2026), 300 × 200
        $heic = self::upload((string) file_get_contents(base_path('tests/Fixtures/photo.heic')), 'IMG_1234.HEIC');

        $piece = $this->sendAsClinic(['files' => [$heic]])->assertCreated()->json('data.attachments.0');

        $this->assertSame(['image', 'image/jpeg', 'IMG_1234.jpg', 300, 200, true], [$piece['kind'], $piece['mime'], $piece['name'], $piece['width'], $piece['height'], $piece['has_thumb']]);
        $this->assertStringStartsWith("\xFF\xD8\xFF", Storage::disk('prive')->get(MessageAttachment::query()->sole()->path));
    }

    public function test_a_heic_that_cannot_be_read_is_refused_with_its_reason_never_stored(): void
    {
        $heic = self::upload("\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 64), 'IMG_1234.HEIC');
        $response = $this->sendAsClinic(['files' => [$heic]])->assertUnprocessable();

        $this->assertMatchesRegularExpression('/^« IMG_1234 » : cette photo HEIC (ne s’ouvre pas ici|n’a pas pu être lue)/', $response->json('errors')['files.0'][0]);
        $this->assertSame([], Storage::disk('prive')->allFiles('messages'));
    }

    public function test_sixty_megabytes_at_most_per_message_record_documents_included(): void
    {
        config(['messages.attachments.max_total_kb' => 1]);
        $refus = $this->sendAsClinic(['files' => [self::upload(self::noise(40, 40), 'a.png'), self::upload(self::noise(40, 40), 'b.png')]])->assertUnprocessable();
        $this->assertSame('0 Mo au plus par message : envoyez le reste dans un autre message.', $refus->json('errors.files.0'));

        // Les documents du dossier comptent aussi
        $bagheera = Patient::factory()->create(['client_id' => $this->fiche->id, 'organisation_id' => $this->fiche->organisation_id]);
        Storage::disk('prive')->put('documents/gros.pdf', '%PDF-1.4'.str_repeat('x', 2000));
        $document = Document::query()->create(['patient_id' => $bagheera->id, 'nom' => 'Gros', 'type' => 'rapport', 'chemin' => 'documents/gros.pdf', 'etabli_le' => now()]);
        $this->sendAsClinic(['document_ids' => [$document->id]])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertSame(0, MessageClientele::query()->count());
    }

    public function test_an_image_too_big_for_the_memory_is_refused_never_a_server_error(): void
    {
        config(['messages.attachments.max_pixels' => 10_000]);
        $refus = $this->sendAsClinic(['files' => [self::upload(self::png(200, 200), 'immense.png')]])->assertUnprocessable();

        $this->assertSame('« immense » : cette photo est trop grande.', $refus->json('errors')['files.0'][0]);
        $this->assertSame([], Storage::disk('prive')->allFiles('messages'));
    }

    public function test_the_owner_and_the_clinic_read_the_files_and_no_one_else(): void
    {
        ['jeton' => $ailleurs] = $this->employeAvecJeton(Clinique::factory()->create());
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $soeurToken] = $this->employeAvecJeton($soeur);
        $id = $this->sendAsClinic(['files' => [self::upload(self::jpeg(400, 300), 'photo.jpg')]])->assertCreated()->json('data.attachments.0.id');

        $this->get('/api/v1/messages/pieces/'.$id, $this->enTetes($this->julieToken))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/v1/messages/pieces/'.$id.'/apercu', $this->enTetes($this->julieToken))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/api/v1/espace/messages/pieces/'.$id, $this->enTetes($this->ownerToken))->assertOk();
        $this->get('/api/v1/espace/messages/pieces/'.$id.'/apercu', $this->enTetes($this->ownerToken))->assertOk();
        // Le propriétaire les voit dans son fil
        $this->getJson('/api/v1/espace/messages?clinique_id='.$this->clinique->id, $this->enTetes($this->ownerToken))
            ->assertJsonPath('data.messages.0.attachments.0.id', $id);

        ['jeton' => $autre] = $this->proprietaireAvecJeton();
        $this->get('/api/v1/espace/messages/pieces/'.$id, $this->enTetes($autre))->assertNotFound();
        $this->get('/api/v1/messages/pieces/'.$id, $this->enTetes($ailleurs))->assertNotFound();
        // Une clinique sœur de la même organisation ne lit pas les fils de l'autre
        $this->get('/api/v1/messages/pieces/'.$id, $this->enTetes($soeurToken))->assertNotFound();
    }

    public function test_a_document_of_the_record_is_copied_into_the_message_only_from_this_client_s_animals(): void
    {
        $bagheera = Patient::factory()->create(['client_id' => $this->fiche->id, 'organisation_id' => $this->fiche->organisation_id, 'nom' => 'Bagheera']);
        Storage::disk('prive')->put('documents/'.$this->fiche->organisation_id.'/cr.pdf', "%PDF-1.4\ncompte rendu\n%%EOF");
        $document = Document::query()->create(['patient_id' => $bagheera->id, 'nom' => 'Compte rendu du 3 novembre', 'type' => 'compte_rendu', 'chemin' => 'documents/'.$this->fiche->organisation_id.'/cr.pdf', 'etabli_le' => now()]);
        $autreFiche = Client::factory()->create(['organisation_id' => $this->fiche->organisation_id]);
        $autreAnimal = Patient::factory()->create(['client_id' => $autreFiche->id, 'organisation_id' => $this->fiche->organisation_id]);
        $autreDocument = Document::query()->create(['patient_id' => $autreAnimal->id, 'nom' => 'Autre', 'type' => 'rapport', 'chemin' => 'documents/'.$this->fiche->organisation_id.'/cr.pdf', 'etabli_le' => now()]);

        $data = $this->sendAsClinic(['document_ids' => [$document->id], 'files' => [self::upload(self::png(20, 20), 'plaie.png')]])->assertCreated()->json('data');
        $this->assertSame(['plaie.png', 'Compte rendu du 3 novembre.pdf'], array_column($data['attachments'], 'name'));
        $copie = MessageAttachment::query()->where('kind', 'pdf')->sole();
        $this->assertNotSame($document->chemin, $copie->path);
        $this->assertSame("%PDF-1.4\ncompte rendu\n%%EOF", Storage::disk('prive')->get($copie->path));

        $refus = $this->sendAsClinic(['document_ids' => [$autreDocument->id]])->assertUnprocessable();
        $this->assertSame('Ce document n’est pas dans le dossier de ce client.', $refus->json('errors')['document_ids.0'][0]);
        // Un document dont le fichier a disparu du disque : refusé proprement, jamais une erreur 500
        $perdu = Document::query()->create(['patient_id' => $bagheera->id, 'nom' => 'Perdu', 'type' => 'rapport', 'chemin' => 'documents/perdu.pdf', 'etabli_le' => now()]);
        $refus = $this->sendAsClinic(['document_ids' => [$perdu->id]])->assertUnprocessable();
        $this->assertSame('Ce document n’est pas dans le dossier de ce client.', $refus->json('errors')['document_ids.0'][0]);
        // Pas de document du dossier entre collègues
        ['employe' => $anne] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        $this->sendAsClinic(['document_ids' => [$document->id]], 'e'.$anne->id)->assertUnprocessable()->assertJsonValidationErrors('document_ids');
        // Le propriétaire n'en envoie pas
        $this->post('/api/v1/espace/messages/pieces', ['clinique_id' => $this->clinique->id, 'document_ids' => [$document->id]], [...$this->enTetes($this->ownerToken), 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('document_ids');
    }

    public function test_colleagues_and_other_clinics_send_attachments_read_by_them_only(): void
    {
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire de Morges']);
        ['jeton' => $claireToken] = $this->employeAvecJeton($centre, ['nom' => 'Dre Claire Vautier']);
        ['employe' => $anne, 'jeton' => $anneToken] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        ['jeton' => $lucieToken] = $this->employeAvecJeton($this->clinique, ['nom' => 'Lucie Maret']);
        $id = $this->post('/api/v1/messages/e'.$anne->id.'/pieces', ['files' => [self::pdf('planning.pdf')]], [...$this->enTetes($this->julieToken), 'Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.texte', 'Document : planning.pdf')->json('data.attachments.0.id');
        $this->getJson('/api/v1/messages/e'.MessageAttachment::query()->sole()->staffMessage->sender_employee_id, $this->enTetes($anneToken))
            ->assertJsonPath('data.messages.0.attachments.0.name', 'planning.pdf')->assertJsonPath('data.media_count', 1);
        $this->get('/api/v1/messages/pieces/'.$id, $this->enTetes($anneToken))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/v1/messages/pieces/'.$id, $this->enTetes($lucieToken))->assertNotFound();

        $k = $this->post('/api/v1/messages/k'.$centre->id.'/pieces', ['files' => [self::upload(self::png(50, 50), 'radio.png')], 'texte' => 'La radio de Félix'], [...$this->enTetes($this->julieToken), 'Accept' => 'application/json'])
            ->assertCreated()->json('data.attachments.0.id');
        $this->get('/api/v1/messages/pieces/'.$k, $this->enTetes($claireToken))->assertOk();
        $this->getJson('/api/v1/messages/k'.$this->clinique->id, $this->enTetes($claireToken))->assertJsonPath('data.messages.0.caption', 'La radio de Félix');
    }

    public function test_the_owner_sends_photos_to_the_clinic_within_his_limits(): void
    {
        config(['messages.attachments.owner_files_per_hour' => 3]);
        $envoyer = fn (array $files) => $this->post('/api/v1/espace/messages/pieces', ['clinique_id' => $this->clinique->id, 'files' => $files], [...$this->enTetes($this->ownerToken), 'Accept' => 'application/json']);

        $envoyer([self::upload(self::jpeg(200, 100), 'plaie.jpg'), self::upload(self::jpeg(200, 100), 'plaie2.jpg')])->assertCreated()
            ->assertJsonPath('data.de_moi', true)->assertJsonPath('data.status', 'sent')->assertJsonPath('data.texte', '2 photos');
        $envoyer([self::pdf(), self::pdf('b.pdf')])->assertUnprocessable()->assertJsonPath('errors.files.0', '3 fichiers par heure au plus : réessayez un peu plus tard.');

        // Le poids du jour compte avec l'audio et la vidéo
        config(['messages.attachments.owner_files_per_hour' => 60, 'messages.media.owner_bytes_per_day' => 1000]);
        $envoyer([self::pdf()])->assertUnprocessable()->assertJsonPath('errors.files.0', '200 Mo d’envois par jour au plus : réessayez demain.');
        // Et dans l'autre sens : les pièces du jour comptent pour l'audio et la vidéo (vérification du 08.11.2026)
        config(['messages.media.owner_bytes_per_day' => (int) MessageAttachment::query()->sum('size_bytes') + 100]);
        $this->post('/api/v1/espace/messages/media', ['clinique_id' => $this->clinique->id, 'kind' => 'audio', 'duration_ms' => 3000, 'peaks' => [0.1, 0.5],
            'file' => UploadedFile::fake()->createWithContent('a.webm', EnregistrementFactice::webm(3000))], [...$this->enTetes($this->ownerToken), 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', '200 Mo d’envois par jour au plus : réessayez demain.');
        // La clinique les voit
        $this->getJson('/api/v1/messages/u'.$this->owner->id, $this->enTetes($this->julieToken))->assertJsonPath('data.messages.0.attachments.1.name', 'plaie2.jpg');
    }

    public function test_an_erased_account_takes_its_attachments_the_caption_stays(): void
    {
        $this->post('/api/v1/espace/messages/pieces', ['clinique_id' => $this->clinique->id, 'texte' => 'Sa patte ce matin', 'files' => [self::upload(self::jpeg(100, 100), 'patte.jpg')]], [...$this->enTetes($this->ownerToken), 'Accept' => 'application/json'])->assertCreated();
        $deLaClinique = $this->sendAsClinic(['files' => [self::pdf()]])->assertCreated()->json('data.attachments.0.id');
        $sienne = MessageAttachment::query()->where('kind', 'image')->sole();
        [$chemin, $apercu] = [$sienne->path, $sienne->thumb_path];

        $this->owner->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->artisan('comptes:effacer')->assertSuccessful();

        Storage::disk('prive')->assertMissing([$chemin, $apercu]);
        Storage::disk('prive')->assertExists(MessageAttachment::query()->findOrFail($deLaClinique)->path);
        $messages = $this->getJson('/api/v1/messages/c'.$this->fiche->id, $this->enTetes($this->julieToken))->assertOk()->json('data.messages');
        $this->assertSame('Sa patte ce matin', $messages[0]['texte']);
        $this->assertTrue($messages[0]['attachments'][0]['erased']);
        $this->get('/api/v1/messages/pieces/'.$sienne->id, $this->enTetes($this->julieToken))->assertStatus(410);
    }

    public function test_files_leave_with_their_message_and_the_night_purge_keeps_those_still_attached(): void
    {
        $morges = Clinique::factory()->create();
        $this->sendAsClinic(['files' => [self::upload(self::jpeg(100, 100), 'garde.jpg')]])->assertCreated();
        $gardee = MessageAttachment::query()->sole();
        $this->sendAsClinic(['files' => [self::upload(self::jpeg(100, 100), 'orpheline.jpg')]], 'k'.$morges->id)->assertCreated();
        $orpheline = MessageAttachment::query()->latest('id')->first();

        // Effacée en cascade par la base : ses fichiers restent jusqu'à la purge de la nuit
        DB::table('cliniques')->where('id', $morges->id)->delete();
        foreach ([$gardee->path, $gardee->thumb_path, $orpheline->path, $orpheline->thumb_path] as $chemin) {
            touch(Storage::disk('prive')->path($chemin), now()->getTimestamp() - 7200);
        }
        $this->artisan('messages:purge-media')->expectsOutput('2 fichier(s) effacé(s).')->assertSuccessful();
        Storage::disk('prive')->assertMissing([$orpheline->path, $orpheline->thumb_path]);
        Storage::disk('prive')->assertExists([$gardee->path, $gardee->thumb_path]);

        // Effacé par le modèle : ses fichiers partent tout de suite
        MessageClientele::query()->sole()->delete();
        Storage::disk('prive')->assertMissing([$gardee->path, $gardee->thumb_path]);
        $this->assertSame(0, MessageAttachment::query()->count());
    }

    public function test_the_clean_name_has_no_path_nor_control_characters(): void
    {
        // Une extension inconnue reste dans le nom (« Bilan 12.11 » garde son « .11 »)
        $this->assertSame('Bilan 12.11.pdf', MessageAttachmentService::cleanName('Bilan 12.11', 'pdf'));
        $this->assertSame('Bilan 12.11.pdf', MessageAttachmentService::cleanName('Bilan 12.11.PDF', 'pdf'));
        $this->assertSame('passwd.pdf', MessageAttachmentService::cleanName("../../etc/passwd\x00.pdf", 'pdf'));
        $this->assertSame('Bilan sanguin.pdf', MessageAttachmentService::cleanName("Bilan\tsanguin.pdf", 'pdf'));
        $this->assertSame('Photo.jpg', MessageAttachmentService::cleanName('', 'jpg'));
    }
}
