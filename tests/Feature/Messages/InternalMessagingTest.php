<?php

namespace Tests\Feature\Messages;

use App\Events\MessagesChangent;
use App\Models\ClinicMessage;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\StaffMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Messages entre collègues et entre cliniques (décisions de Jim, 01.11.2026) :
 * dans la même liste que les clients ; un collègue, à deux, que seuls ces
 * deux-là lisent ; une autre clinique, un canal pour toute son équipe, comme
 * un groupe, sans « À l'attention de ».
 */
class InternalMessagingTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $lausanne;

    private Clinique $morges;

    private Employe $anne;

    private string $anneToken;

    private Employe $marc;

    private string $marcToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lausanne = Clinique::factory()->create(['nom' => 'Deux Rives Lausanne', 'ville' => 'Lausanne']);
        $this->morges = Clinique::factory()->create(['nom' => 'Deux Rives Morges', 'ville' => 'Morges', 'societe_id' => $this->lausanne->societe_id]);
        ['employe' => $this->anne, 'jeton' => $this->anneToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dre Anne Meyer']);
        ['employe' => $this->marc, 'jeton' => $this->marcToken] = $this->employeAvecJeton($this->morges, ['nom' => 'Dr Marc Dubois']);
    }

    private function threads(string $token): array
    {
        return $this->getJson('/api/v1/messages', $this->enTetes($token))->assertOk()->json('data');
    }

    public function test_a_colleague_thread_is_read_by_the_two_colleagues_only(): void
    {
        Event::fake([MessagesChangent::class]);
        ['jeton' => $adminToken] = $this->administrateurAvecJeton($this->lausanne);
        ['jeton' => $lucieToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Lucie Maret']);

        $this->postJson('/api/v1/messages/e'.$this->marc->id, ['texte' => 'Tu peux reprendre mon 16 h ?'], $this->enTetes($this->anneToken))->assertCreated()
            ->assertJsonPath('data.is_mine', true)->assertJsonPath('data.signature', 'Dre Anne Meyer');
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e) => $e->canal === 'utilisateur.'.$this->marc->user_id);

        // Marc, dans une autre clinique de l'organisation, le voit non lu, sous le nom d'Anne
        $marcThreads = $this->threads($this->marcToken);
        $this->assertCount(1, $marcThreads);
        $this->assertSame(['e'.$this->anne->id, 'staff', 'Dre Anne Meyer', 'Collègue', 1, false], [
            $marcThreads[0]['cle'], $marcThreads[0]['kind'], $marcThreads[0]['name'], $marcThreads[0]['subtitle'], $marcThreads[0]['non_lus'], $marcThreads[0]['dernier']['is_mine'],
        ]);

        // L'ouvrir le marque lu ; sa réponse arrive chez Anne
        $this->getJson('/api/v1/messages/e'.$this->anne->id, $this->enTetes($this->marcToken))->assertOk()
            ->assertJsonPath('data.messages.0.texte', 'Tu peux reprendre mon 16 h ?')->assertJsonPath('data.messages.0.is_mine', false);
        $this->assertNotNull(StaffMessage::query()->sole()->read_at);
        $this->postJson('/api/v1/messages/e'.$this->anne->id, ['texte' => 'Oui, sans problème.'], $this->enTetes($this->marcToken))->assertCreated();
        $this->assertSame(1, $this->threads($this->anneToken)[0]['non_lus']);

        // Ni l'administrateur ni une autre collègue ne le voient ; « e<Anne> » chez Lucie, c'est sa conversation à elle avec Anne, vide
        $this->assertSame([], $this->threads($adminToken));
        $this->assertSame([], $this->threads($lucieToken));
        $this->getJson('/api/v1/messages/e'.$this->anne->id, $this->enTetes($lucieToken))->assertOk()->assertJsonPath('data.messages', []);
    }

    public function test_one_writes_only_to_a_colleague_of_the_organisation_who_reads_messages(): void
    {
        $ailleurs = Clinique::factory()->create();
        ['employe' => $etranger] = $this->employeAvecJeton($ailleurs);
        ['employe' => $sansPatients] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Paul Comptable']);
        DerogationPermission::query()->create(['employe_id' => $sansPatients->id, 'domaine' => 'patients', 'est_accordee' => false]);
        ['employe' => $suspendu] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dr Paul Rochat']);
        $suspendu->update(['acces_suspendu' => true]);

        $this->postJson('/api/v1/messages/e'.$etranger->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertNotFound();
        $this->postJson('/api/v1/messages/e'.$this->anne->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertNotFound();
        $this->postJson('/api/v1/messages/e'.$sansPatients->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertUnprocessable()->assertJsonValidationErrors('texte');
        $this->postJson('/api/v1/messages/e'.$suspendu->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertUnprocessable()->assertJsonValidationErrors('texte');
        $this->assertSame(0, StaffMessage::query()->count());
    }

    public function test_another_clinic_is_a_channel_for_its_whole_team_like_a_group(): void
    {
        Event::fake([MessagesChangent::class]);
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire de Morges', 'ville' => 'Morges']);
        ['jeton' => $julieToken] = $this->employeAvecJeton($centre, ['nom' => 'Julie Perret']);
        ['jeton' => $claireToken] = $this->employeAvecJeton($centre, ['nom' => 'Dre Claire Vautier']);

        $this->postJson('/api/v1/messages/k'.$centre->id, ['texte' => 'Nous vous adressons Félix pour une échographie.'], $this->enTetes($this->anneToken))->assertCreated()
            ->assertJsonPath('data.signature', 'Dre Anne Meyer, Deux Rives Lausanne');
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e) => $e->canal === 'clinique.'.$centre->id);
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e) => $e->canal === 'clinique.'.$this->lausanne->id);

        // Toute l'équipe du centre le voit, sous le nom de la clinique qui écrit, sans personne « à l'attention de »
        foreach ([$julieToken, $claireToken] as $token) {
            $thread = $this->threads($token)[0];
            $this->assertSame(['k'.$this->lausanne->id, 'clinic', 'Deux Rives Lausanne', 'Lausanne', 1], [$thread['cle'], $thread['kind'], $thread['name'], $thread['subtitle'], $thread['non_lus']]);
            $this->assertArrayNotHasKey('attention', $thread);
        }

        // Julie l'ouvre : lu pour toute la clinique ; sa réponse arrive chez toute l'équipe de Lausanne
        $this->getJson('/api/v1/messages/k'.$this->lausanne->id, $this->enTetes($julieToken))->assertOk()
            ->assertJsonPath('data.messages.0.signature', 'Dre Anne Meyer, Deux Rives Lausanne');
        $this->assertSame(0, $this->threads($claireToken)[0]['non_lus']);
        $this->postJson('/api/v1/messages/k'.$this->lausanne->id, ['texte' => 'Nous pouvons le voir jeudi à 14 h.'], $this->enTetes($julieToken))->assertCreated();
        ['jeton' => $lucieToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Lucie Maret']);
        $this->assertSame(1, $this->threads($lucieToken)[0]['non_lus']);
        $this->getJson('/api/v1/messages/k'.$centre->id, $this->enTetes($lucieToken))->assertOk()
            ->assertJsonPath('data.messages.1.signature', 'Julie Perret, Centre vétérinaire de Morges')
            ->assertJsonPath('data.messages.1.is_mine', false)->assertJsonPath('data.messages.0.is_mine', true);

        // Une clinique sœur ne lit pas le canal de Lausanne
        $this->assertSame([], $this->threads($this->marcToken));
    }

    public function test_a_clinic_that_is_not_open_cannot_be_written_to(): void
    {
        $fermee = Clinique::factory()->create(['nom' => 'Clinique en attente']);
        $fermee->societe->organisation->forceFill(['verification' => 'en_attente'])->save();

        $this->postJson('/api/v1/messages/k'.$fermee->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertNotFound();
        $this->postJson('/api/v1/messages/k'.$this->lausanne->id, ['texte' => 'Bonjour'], $this->enTetes($this->anneToken))->assertNotFound();
        $this->assertSame(0, ClinicMessage::query()->count());
    }

    public function test_to_finds_colleagues_and_open_clinics_by_name_or_town(): void
    {
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire', 'ville' => 'Morges']);
        $fermee = Clinique::factory()->create(['nom' => 'Cabinet de Morges', 'ville' => 'Morges']);
        $fermee->societe->organisation->forceFill(['verification' => 'en_attente'])->save();
        $ailleurs = Clinique::factory()->create(['nom' => 'Clinique du Marais', 'ville' => 'Yverdon-les-Bains']);
        $this->employeAvecJeton($ailleurs, ['nom' => 'Dr Marc Etranger']);

        $data = $this->getJson('/api/v1/messages/contacts?q=marc', $this->enTetes($this->anneToken))->assertOk()->json('data');
        $this->assertSame(['e'.$this->marc->id], array_column($data['staff'], 'cle'));
        $this->assertStringContainsString('Deux Rives Morges', $data['staff'][0]['subtitle']);

        // Par la ville : les cliniques ouvertes, sauf la mienne ; une clinique sœur aussi
        $clinics = $this->getJson('/api/v1/messages/contacts?q=morges', $this->enTetes($this->anneToken))->assertOk()->json('data.clinics');
        $this->assertSame(['k'.$centre->id, 'k'.$this->morges->id], array_column($clinics, 'cle'));
        $this->assertSame([], $this->getJson('/api/v1/messages/contacts?q=lausanne', $this->enTetes($this->anneToken))->assertOk()->json('data.clinics'));
    }

    public function test_the_list_mixes_clients_colleagues_and_clinics_most_recent_first(): void
    {
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire', 'ville' => 'Morges']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        $this->postJson('/api/v1/messages/e'.$this->marc->id, ['texte' => 'Premier'], $this->enTetes($this->anneToken))->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->postJson('/api/v1/messages/k'.$centre->id, ['texte' => 'Second'], $this->enTetes($this->anneToken))->assertCreated();

        $this->assertSame(['k'.$centre->id, 'e'.$this->marc->id], array_column($this->threads($this->anneToken), 'cle'));
    }
}
