<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Leads\LeadFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * T0 — un solo traductor de filtros para el listado, el CSV y los broadcasts.
 *
 * El test que importa es `test_el_mismo_segmento_selecciona_los_mismos_leads`:
 * fija la razón de ser de `LeadFilter`. Antes de esta clase, una lista guardada
 * con `stage_id` filtraba en `/leads` y se ignoraba en el envío masivo.
 */
class LeadFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->agent = User::create([
            'name' => 'Agente', 'email' => 'a@test.com', 'password' => bcrypt('secret'),
            'account_id' => $this->account->id, 'account_role' => 'agent',
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Negociación', 'stage_type' => 'open'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private function makeLead(array $overrides = []): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $overrides['contact_name'] ?? 'Contacto',
            'phone' => $overrides['phone'] ?? '+59170000'.random_int(100, 999),
            'phone_normalized' => $overrides['phone_normalized'] ?? '59170000'.random_int(100, 999),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $overrides['stage_id'] ?? $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => $overrides['title'] ?? 'Lead',
            'value' => 100,
            'source' => $overrides['source'] ?? 'manual',
            'status' => $overrides['status'] ?? 'open',
            'responsible_user_id' => $overrides['responsible_user_id'] ?? $this->owner->id,
        ]);
    }

    // ---- Contrato de `normalize()` ----

    public function test_una_clave_desconocida_no_se_ignora(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeadFilter::normalize(['fuente' => 'whatsapp']); // debía ser `source`
    }

    public function test_normalize_unifica_tag_y_tags_y_descarta_vacios(): void
    {
        $out = LeadFilter::normalize([
            'tag' => 'abc',
            'tags' => ['def', null],
            'source' => '',
            'q' => '   ',
            'no_task' => 0,
        ]);

        $this->assertEqualsCanonicalizing(['def', 'abc'], $out['tags']);
        $this->assertArrayNotHasKey('tag', $out);
        $this->assertArrayNotHasKey('source', $out);
        $this->assertArrayNotHasKey('q', $out);
        // `no_task: 0` no filtra nada; guardarlo como `false` haría creer que sí.
        $this->assertArrayNotHasKey('no_task', $out);
    }

    public function test_no_task_llega_como_1_desde_los_segmentos_guardados(): void
    {
        $this->assertTrue(LeadFilter::normalize(['no_task' => 1])['no_task']);
        $this->assertTrue(LeadFilter::normalize(['no_task' => '1'])['no_task']);
    }

    // ---- La regresión que motiva la tarea ----

    public function test_el_mismo_segmento_selecciona_los_mismos_leads_en_leads_y_en_broadcasts(): void
    {
        // 2 leads en «Negociación» y 3 en «Nuevo». El segmento pide Negociación.
        $this->makeLead(['stage_id' => $this->stages[1]->id]);
        $this->makeLead(['stage_id' => $this->stages[1]->id]);
        $this->makeLead();
        $this->makeLead();
        $this->makeLead();

        $filters = ['stage_id' => $this->stages[1]->id];

        $enListado = $this->actingAs($this->owner)
            ->get('/leads?stage_id='.$this->stages[1]->id)
            ->viewData('page')['props']['leads'];

        $enEnvio = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => $filters])
            ->json('count');

        $this->assertCount(2, $enListado);
        // Antes de LeadFilter esto devolvía 5: el envío ignoraba `stage_id`.
        $this->assertSame(2, $enEnvio);
    }

    public function test_el_agente_solo_alcanza_su_cartera_en_los_dos_lados(): void
    {
        $this->makeLead(['responsible_user_id' => $this->owner->id]);
        $this->makeLead(['responsible_user_id' => $this->agent->id]);

        $enListado = $this->actingAs($this->agent)->get('/leads')
            ->viewData('page')['props']['leads'];

        $enEnvio = $this->actingAs($this->agent)
            ->postJson(route('broadcasts.preview'), ['filters' => []])
            ->json('count');

        $this->assertCount(1, $enListado);
        $this->assertSame(1, $enEnvio);
    }

    public function test_el_agente_no_puede_apuntar_a_la_cartera_de_otro(): void
    {
        $this->makeLead(['responsible_user_id' => $this->owner->id]);
        $this->makeLead(['responsible_user_id' => $this->agent->id]);

        // El filtro por responsable se descarta para el agente en vez de
        // combinarse con su corte de rol: una lista vacía se leería como
        // «no hay leads» y no como «eso no es tuyo».
        $count = $this->actingAs($this->agent)
            ->postJson(route('broadcasts.preview'), ['filters' => ['responsible' => $this->owner->id]])
            ->json('count');

        $this->assertSame(1, $count);
    }

    // ---- Diferencias deliberadas entre llamadores ----

    public function test_el_envio_excluye_cerrados_pero_el_tablero_no(): void
    {
        $this->makeLead();
        $this->makeLead(['status' => 'won']);

        $enListado = $this->actingAs($this->owner)->get('/leads')
            ->viewData('page')['props']['leads'];

        $enEnvio = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => []])
            ->json('count');

        $this->assertCount(2, $enListado, 'El tablero muestra ganados y perdidos.');
        $this->assertSame(1, $enEnvio, 'Escribirle a un lead cerrado es un error caro.');
    }

    public function test_include_closed_habilita_los_cerrados_en_el_envio(): void
    {
        $this->makeLead();
        $this->makeLead(['status' => 'won']);

        $count = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => ['include_closed' => true]])
            ->json('count');

        $this->assertSame(2, $count);
    }

    // ---- Criterios sueltos ----

    public function test_filtra_por_etiqueta_sin_tarea_y_texto(): void
    {
        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'MBA', 'color' => '#000']);

        $conTag = $this->makeLead(['title' => 'Interesado MBA']);
        $conTag->tags()->attach($tag->id);

        $conTagYTarea = $this->makeLead(['title' => 'Otro MBA']);
        $conTagYTarea->tags()->attach($tag->id);
        Task::create([
            'account_id' => $this->account->id, 'lead_id' => $conTagYTarea->id,
            'assigned_to' => $this->owner->id, 'task_type' => 'follow_up',
            'text' => 'Llamar', 'due_at' => now()->addDay(),
        ]);

        $this->makeLead(['title' => 'Sin etiqueta']);

        $count = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => ['tags' => [$tag->id], 'no_task' => true]])
            ->json('count');

        $this->assertSame(1, $count, 'Solo el que tiene la etiqueta y ninguna tarea pendiente.');
    }

    public function test_una_lista_guardada_se_normaliza_al_guardarse(): void
    {
        $this->actingAs($this->owner)->post(route('segments.store'), [
            'name' => 'Los del MBA',
            'filters' => ['tag' => 'abc', 'source' => '', 'q' => '  ', 'no_task' => 0],
        ])->assertRedirect();

        $filters = \App\Models\SavedSegment::first()->filters;

        $this->assertSame(['tags' => ['abc']], $filters);
    }

    public function test_una_lista_guardada_con_clave_invalida_es_422(): void
    {
        $this->actingAs($this->owner)->post(route('segments.store'), [
            'name' => 'Rota',
            'filters' => ['inventado' => 'x'],
        ])->assertSessionHasErrors('filters');
    }
}
