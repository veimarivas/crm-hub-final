<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\SavedSegment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Leads\SegmentQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * T4 — segmentación dinámica.
 *
 * Dos cosas que estos tests protegen por encima del resto:
 *
 *  1. **Las listas guardadas antes de T4 siguen funcionando.** El formato viejo
 *     está en la base de producción; si dejara de leerse, el equipo perdería
 *     sus audiencias sin aviso.
 *  2. **Un segmento selecciona lo mismo en todas las pantallas.** Es la misma
 *     promesa de T0, ahora con criterios ricos.
 */
class SegmentQueryTest extends TestCase
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

        Carbon::setTestNow('2026-08-08 12:00:00');

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
            ['name' => 'Negociacion', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private function makeLead(array $o = []): Lead
    {
        // `Contact::saving` deriva `phone_normalized` de `phone`, así que para
        // un contacto sin teléfono hay que dejar `phone` vacío: pasar
        // `phone_normalized => null` lo pisa el modelo y el test mentiría.
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $o['contact_name'] ?? 'Contacto',
            'phone' => ($o['no_phone'] ?? false) ? null : '+5917000'.random_int(1000, 9999),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $o['stage_id'] ?? $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => $o['title'] ?? 'Lead',
            'value' => $o['value'] ?? 100,
            'source' => $o['source'] ?? 'whatsapp',
            'status' => $o['status'] ?? 'open',
            'responsible_user_id' => $o['responsible_user_id'] ?? $this->owner->id,
        ]);
    }

    private function message(Lead $lead, string $type, string $at, array $payload = []): void
    {
        $lead->events()->create([
            'account_id' => $this->account->id, 'event_type' => $type, 'payload' => $payload,
        ])->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    /**
     * Títulos que selecciona una definición.
     *
     * No se llama `matches()`: `PHPUnit\Framework\Assert::matches()` existe y es
     * `final`, así que un helper con ese nombre revienta con un fatal antes de
     * correr un solo test.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function titlesFor(array $definition, ?User $as = null): array
    {
        return SegmentQuery::for($as ?? $this->owner)
            ->apply(Lead::forAccount($this->account->id), $definition)
            ->pluck('title')
            ->sort()
            ->values()
            ->all();
    }

    // ---- Compatibilidad con las listas ya guardadas ----

    public function test_una_lista_del_formato_viejo_se_sube_al_nuevo(): void
    {
        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'MBA', 'color' => '#000']);

        $upgraded = SegmentQuery::upgrade([
            'tag' => $tag->id, 'source' => 'whatsapp', 'no_task' => 1, 'q' => 'Ana',
        ]);

        $this->assertSame(2, $upgraded['version']);
        $this->assertSame('all', $upgraded['match']);

        $campos = collect($upgraded['conditions'])->pluck('field')->sort()->values()->all();
        $this->assertSame(['has_pending_task', 'source', 'tag_id', 'title'], $campos);

        // `no_task: 1` significa "sin tarea pendiente", o sea la condicion
        // `has_pending_task = false`. Invertir esto silenciosamente cambiaria
        // la audiencia de todas las listas viejas.
        $this->assertFalse(collect($upgraded['conditions'])->firstWhere('field', 'has_pending_task')['value']);
    }

    public function test_una_lista_vieja_sigue_seleccionando_los_mismos_leads(): void
    {
        $conTarea = $this->makeLead(['title' => 'Con tarea']);
        Task::create([
            'account_id' => $this->account->id, 'lead_id' => $conTarea->id,
            'assigned_to' => $this->owner->id, 'task_type' => 'call',
            'text' => 'Llamar', 'due_at' => now()->addDay(),
        ]);
        $this->makeLead(['title' => 'Olvidado']);

        // Exactamente el JSON que guardaban las listas antes de T4.
        $this->assertSame(['Olvidado'], $this->titlesFor(['no_task' => 1]));
    }

    public function test_una_lista_vieja_con_clave_invalida_sigue_fallando_fuerte(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SegmentQuery::upgrade(['fuente' => 'whatsapp']);
    }

    // ---- Grupos Y / O ----

    public function test_grupo_all_exige_todas_las_condiciones(): void
    {
        $this->makeLead(['title' => 'Ambas', 'source' => 'whatsapp', 'value' => 900]);
        $this->makeLead(['title' => 'Solo fuente', 'source' => 'whatsapp', 'value' => 10]);
        $this->makeLead(['title' => 'Solo valor', 'source' => 'manual', 'value' => 900]);

        $this->assertSame(['Ambas'], $this->titlesFor([
            'version' => 2, 'match' => 'all',
            'conditions' => [
                ['field' => 'source', 'op' => 'in', 'value' => ['whatsapp']],
                ['field' => 'value', 'op' => 'gte', 'value' => 500],
            ],
        ]));
    }

    public function test_grupo_any_alcanza_con_una(): void
    {
        $this->makeLead(['title' => 'Por fuente', 'source' => 'whatsapp', 'value' => 10]);
        $this->makeLead(['title' => 'Por valor', 'source' => 'manual', 'value' => 900]);
        $this->makeLead(['title' => 'Ninguna', 'source' => 'manual', 'value' => 10]);

        $this->assertSame(['Por fuente', 'Por valor'], $this->titlesFor([
            'version' => 2, 'match' => 'any',
            'conditions' => [
                ['field' => 'source', 'op' => 'in', 'value' => ['whatsapp']],
                ['field' => 'value', 'op' => 'gte', 'value' => 500],
            ],
        ]));
    }

    public function test_grupos_anidados(): void
    {
        // Negociacion Y (whatsapp O valor alto).
        $this->makeLead(['title' => 'Si', 'stage_id' => $this->stages[1]->id, 'source' => 'whatsapp', 'value' => 10]);
        $this->makeLead(['title' => 'Etapa pero nada mas', 'stage_id' => $this->stages[1]->id, 'source' => 'manual', 'value' => 10]);
        $this->makeLead(['title' => 'Otra etapa', 'source' => 'whatsapp', 'value' => 900]);

        $this->assertSame(['Si'], $this->titlesFor([
            'version' => 2, 'match' => 'all',
            'conditions' => [
                ['field' => 'stage_id', 'op' => 'in', 'value' => [$this->stages[1]->id]],
                ['match' => 'any', 'conditions' => [
                    ['field' => 'source', 'op' => 'in', 'value' => ['whatsapp']],
                    ['field' => 'value', 'op' => 'gte', 'value' => 500],
                ]],
            ],
        ]));
    }

    // ---- Criterios de comportamiento ----

    public function test_ultimo_mensaje_mas_viejo_que_incluye_a_los_que_nunca_escribieron(): void
    {
        $viejo = $this->makeLead(['title' => 'Frio']);
        $this->message($viejo, 'message_in', '2026-06-01 10:00:00');

        $reciente = $this->makeLead(['title' => 'Activo']);
        $this->message($reciente, 'message_in', '2026-08-07 10:00:00');

        $this->makeLead(['title' => 'Nunca escribio']);

        // "Hace mas de 30 dias que no se nada" es verdad tambien cuando nunca
        // dijo nada: excluirlos dejaria afuera justo a los mas abandonados.
        $this->assertSame(['Frio', 'Nunca escribio'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'last_inbound', 'op' => 'older_than', 'value' => 30]],
        ]));
    }

    public function test_nunca_escribio(): void
    {
        $escribio = $this->makeLead(['title' => 'Escribio']);
        $this->message($escribio, 'message_in', '2026-08-01 10:00:00');
        $this->makeLead(['title' => 'Mudo']);

        $this->assertSame(['Mudo'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'last_inbound', 'op' => 'never', 'value' => null]],
        ]));
    }

    public function test_le_contesto_un_humano_no_cuenta_a_la_ia(): void
    {
        $soloIa = $this->makeLead(['title' => 'Solo IA']);
        $this->message($soloIa, 'message_in', '2026-08-01 10:00:00');
        $this->message($soloIa, 'message_out', '2026-08-01 10:01:00', ['sender' => 'bot']);

        $humano = $this->makeLead(['title' => 'Atendido']);
        $this->message($humano, 'message_in', '2026-08-01 10:00:00');
        $this->message($humano, 'message_out', '2026-08-01 10:05:00', ['sender' => 'agent']);

        $this->assertSame(['Solo IA'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'human_replied', 'op' => 'is', 'value' => false]],
        ]));
    }

    public function test_cantidad_de_entrantes_en_cero_incluye_a_los_que_no_tienen_eventos(): void
    {
        $con = $this->makeLead(['title' => 'Con mensajes']);
        $this->message($con, 'message_in', '2026-08-01 10:00:00');
        $this->makeLead(['title' => 'Sin nada']);

        $this->assertSame(['Sin nada'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'inbound_count', 'op' => 'lte', 'value' => 0]],
        ]));
    }

    public function test_segmento_por_banda_y_por_enfriamiento_del_copiloto(): void
    {
        $this->makeLead(['title' => 'Caliente'])->forceFill(['score' => 90, 'score_band' => 'caliente'])->save();
        $this->makeLead(['title' => 'Se enfrio'])->forceFill([
            'score' => 20, 'score_band' => 'frio', 'score_band_previous' => 'caliente',
        ])->save();
        $this->makeLead(['title' => 'Siempre frio'])->forceFill(['score' => 15, 'score_band' => 'frio'])->save();

        $this->assertSame(['Se enfrio'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'score_cooled', 'op' => 'is', 'value' => true]],
        ]));

        $this->assertSame(['Se enfrio', 'Siempre frio'], $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'score_band', 'op' => 'in', 'value' => ['frio']]],
        ]));
    }

    // ---- Validacion ----

    public function test_un_criterio_desconocido_no_se_ignora(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SegmentQuery::validate([
            'version' => 2,
            'conditions' => [['field' => 'color_favorito', 'op' => 'in', 'value' => ['rojo']]],
        ]);
    }

    public function test_un_operador_no_admitido_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SegmentQuery::validate([
            'version' => 2,
            'conditions' => [['field' => 'score_band', 'op' => 'contains', 'value' => 'x']],
        ]);
    }

    // ---- Scope de rol ----

    public function test_el_agente_solo_alcanza_su_cartera(): void
    {
        $this->makeLead(['title' => 'Del owner']);
        $this->makeLead(['title' => 'Del agente', 'responsible_user_id' => $this->agent->id]);

        $this->assertSame(['Del agente'], $this->titlesFor(['version' => 2, 'conditions' => []], $this->agent));
    }

    public function test_el_agente_no_puede_segmentar_la_cartera_de_otro(): void
    {
        $this->makeLead(['title' => 'Del owner']);
        $this->makeLead(['title' => 'Del agente', 'responsible_user_id' => $this->agent->id]);

        $matches = $this->titlesFor([
            'version' => 2,
            'conditions' => [['field' => 'responsible_user_id', 'op' => 'in', 'value' => [$this->owner->id]]],
        ], $this->agent);

        // La condicion se descarta en vez de aplicarse: cruzada con su corte de
        // rol daria vacio, que se lee como "no hay leads".
        $this->assertSame(['Del agente'], $matches);
    }

    // ---- Endpoints ----

    public function test_el_conteo_en_vivo_distingue_alcanzables(): void
    {
        $this->makeLead(['title' => 'Con telefono']);
        $this->makeLead(['title' => 'Sin telefono', 'no_phone' => true]);
        $this->makeLead(['title' => 'Cerrado', 'status' => 'won']);

        $json = $this->actingAs($this->owner)
            ->postJson(route('segments.count'), ['filters' => ['version' => 2, 'conditions' => []]])
            ->assertOk()
            ->json();

        $this->assertSame(3, $json['total']);
        $this->assertSame(2, $json['open']);
        // Sin telefono no hay envio: decirlo evita la sorpresa de un segmento
        // de 300 que alcanza a 40.
        $this->assertSame(1, $json['reachable']);
    }

    public function test_el_segmento_se_guarda_ya_validado_y_en_la_version_vigente(): void
    {
        $this->actingAs($this->owner)->post(route('segments.store'), [
            'name' => 'Frios sin tarea',
            'filters' => [
                'version' => 2, 'match' => 'all',
                'conditions' => [
                    ['field' => 'last_inbound', 'op' => 'older_than', 'value' => 30],
                    ['field' => 'has_pending_task', 'op' => 'is', 'value' => false],
                ],
            ],
        ])->assertRedirect();

        $saved = SavedSegment::first()->filters;

        $this->assertSame(2, $saved['version']);
        $this->assertCount(2, $saved['conditions']);
    }

    public function test_solo_el_creador_edita_su_lista(): void
    {
        $segment = SavedSegment::create([
            'account_id' => $this->account->id, 'user_id' => $this->owner->id,
            'name' => 'Compartida', 'filters' => ['version' => 2, 'conditions' => []], 'is_shared' => true,
        ]);

        // Compartir da lectura, no control: si cualquiera la reescribiera, el
        // resto mandaria envios a una audiencia que cambio sin avisar.
        $this->actingAs($this->agent)->patch(route('segments.update', $segment), [
            'name' => 'Secuestrada', 'filters' => ['version' => 2, 'conditions' => []],
        ])->assertForbidden();
    }

    public function test_el_mismo_segmento_cuenta_igual_en_la_vista_previa_del_envio(): void
    {
        $this->makeLead(['title' => 'Negociacion', 'stage_id' => $this->stages[1]->id]);
        $this->makeLead(['title' => 'Nuevo']);

        $definition = [
            'version' => 2,
            'conditions' => [['field' => 'stage_id', 'op' => 'in', 'value' => [$this->stages[1]->id]]],
        ];

        $enSegmento = $this->actingAs($this->owner)
            ->postJson(route('segments.count'), ['filters' => $definition])->json('reachable');

        $enEnvio = $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => $definition])->json('count');

        $this->assertSame(1, $enSegmento);
        $this->assertSame($enSegmento, $enEnvio);
    }
}
