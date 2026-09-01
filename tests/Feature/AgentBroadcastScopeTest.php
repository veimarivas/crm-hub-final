<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El agente puede difundir, pero solo a su cartera.
 *
 * Antes `/broadcasts` era admin-only entero, así que un asesor tenía que
 * pedirle al administrador cada mensaje masivo a sus propios contactos. El
 * corte correcto no es quién entra sino a quién le puede escribir — y va en
 * el servidor: ocultar el filtro en la pantalla no impide mandar un id ajeno.
 */
class AgentBroadcastScopeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private User $otroAgente;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
        $this->otroAgente = User::create([
            'name' => 'Rosa', 'email' => 'rosa@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

        $this->fakeWacrmBroadcasts($this->account->id);
    }

    private function lead(string $name, ?User $responsable): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
            'phone_normalized' => '5917'.random_int(1000000, 9999999),
        ]);

        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => $name,
            'source' => 'whatsapp',
            'responsible_user_id' => $responsable?->id,
        ]);

        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_in',
            'payload' => ['text' => 'Hola'],
        ]);

        return $lead;
    }

    private function tagId(): string
    {
        return Tag::forAccount($this->account->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(Tag::NEW_LEAD)])->firstOrFail()->id;
    }

    public function test_el_agente_entra_a_broadcasts(): void
    {
        $this->actingAs($this->agente)->get(route('broadcasts.index'))->assertOk();
        $this->actingAs($this->agente)->get(route('broadcasts.create'))->assertOk();
    }

    public function test_la_vista_previa_del_agente_solo_trae_sus_leads(): void
    {
        $this->lead('Mio', $this->agente);
        $this->lead('De Rosa', $this->otroAgente);
        $this->lead('Sin asignar', null);

        $data = $this->actingAs($this->agente)
            ->postJson(route('broadcasts.preview'), ['filters' => ['tags' => [$this->tagId()]]])
            ->assertOk()
            ->json();

        $this->assertSame(1, $data['count']);
        $this->assertSame('Mio', $data['recipients'][0]['name']);
    }

    public function test_el_admin_los_ve_a_todos(): void
    {
        $this->lead('Mio', $this->agente);
        $this->lead('De Rosa', $this->otroAgente);
        $this->lead('Sin asignar', null);

        $data = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => ['tags' => [$this->tagId()]]])
            ->assertOk()
            ->json();

        $this->assertSame(3, $data['count']);
    }

    public function test_el_agente_no_puede_enviar_a_un_lead_ajeno_ni_mandando_su_id(): void
    {
        $mio = $this->lead('Mio', $this->agente);
        $ajeno = $this->lead('De Rosa', $this->otroAgente);

        $this->actingAs($this->agente)
            ->post(route('broadcasts.store'), [
                'name' => 'Prueba',
                'message' => 'Hola',
                'filters' => ['tags' => [$this->tagId()]],
                'lead_ids' => [$mio->id, $ajeno->id],
            ])
            ->assertRedirect();

        $broadcast = Broadcast::forAccount($this->account->id)->firstOrFail();

        $this->assertSame(1, $broadcast->total_recipients);
        $this->assertSame($mio->id, $broadcast->recipients()->first()->lead_id);
    }

    public function test_un_filtro_por_responsable_ajeno_no_le_abre_la_cartera_del_otro(): void
    {
        $this->lead('Mio', $this->agente);
        $this->lead('De Rosa', $this->otroAgente);

        $data = $this->actingAs($this->agente)
            ->postJson(route('broadcasts.preview'), [
                'filters' => ['tags' => [$this->tagId()], 'responsible' => $this->otroAgente->id],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(1, $data['count']);
        $this->assertSame('Mio', $data['recipients'][0]['name']);
    }

    public function test_el_agente_no_ve_el_detalle_de_un_envio_ajeno(): void
    {
        $ajeno = Broadcast::create([
            'account_id' => $this->account->id,
            'user_id' => $this->otroAgente->id,
            'name' => 'De Rosa',
            'message' => 'Hola',
            'filters' => [],
            'status' => 'completed',
            'total_recipients' => 0,
        ]);

        $this->actingAs($this->agente)->get(route('broadcasts.show', $ajeno))->assertForbidden();
        $this->actingAs($this->owner)->get(route('broadcasts.show', $ajeno))->assertOk();
    }

    public function test_el_listado_del_agente_solo_muestra_los_suyos(): void
    {
        Broadcast::create([
            'account_id' => $this->account->id, 'user_id' => $this->otroAgente->id,
            'name' => 'De Rosa', 'message' => 'x', 'filters' => [], 'status' => 'completed', 'total_recipients' => 0,
        ]);
        Broadcast::create([
            'account_id' => $this->account->id, 'user_id' => $this->agente->id,
            'name' => 'Mio', 'message' => 'x', 'filters' => [], 'status' => 'completed', 'total_recipients' => 0,
        ]);

        $broadcasts = $this->actingAs($this->agente)
            ->get(route('broadcasts.index'))
            ->assertOk()
            ->viewData('page')['props']['broadcasts'];

        $this->assertSame(['Mio'], collect($broadcasts)->pluck('name')->all());
    }
}
