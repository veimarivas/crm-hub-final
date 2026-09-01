<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D1b — Komo dejó de tener motor de envíos propio.
 *
 * Hasta acá `SendBroadcastMessageJob` mandaba texto suelto por
 * `/api/v1/messages`, sin plantilla y sin mirar la ventana de servicio: fuera
 * de las 24 h Meta lo rechazaba, y el envío no aparecía en ninguna métrica.
 * Ahora Komo resuelve **a quién** (con `SegmentQuery`) y el wacrm resuelve
 * **cómo se manda**.
 *
 * Lo que fijan estos tests:
 *
 *  1. No se despacha ningún job local y sale UNA sola llamada al wacrm con la
 *     audiencia entera — no una por destinatario.
 *  2. Los excluidos quedan marcados fila por fila CON EL MOTIVO, no como un
 *     total suelto: sin eso no se sabe a quién hay que alcanzar con plantilla.
 *  3. La audiencia completa se congela igual, incluidos los descartados.
 *  4. Si el wacrm rechaza el envío, el motivo aterriza en la pantalla.
 *  5. Si el wacrm no responde al consultar el estado, la pantalla NO se rompe.
 */
class BroadcastDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'komo_live_xxx',
            'is_active' => true,
        ]);
    }

    private function lead(string $phone, string $name): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => $phone,
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => $name,
            'source' => 'whatsapp',
        ]);
    }

    public function test_delega_el_envio_en_una_sola_llamada_y_no_despacha_jobs_locales(): void
    {
        Queue::fake();

        $ana = $this->lead('584125550001', 'Ana');
        $beto = $this->lead('584125550002', 'Beto');

        Http::fake([
            '*/api/v1/broadcasts' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111',
                'status' => 'sending',
                'report' => [
                    'requested' => 2,
                    'unknown_contacts' => 0,
                    'out_of_window' => 1,
                    'sending_to' => 1,
                    'excluded' => [
                        ['phone' => '584125550002', 'external_ref' => $beto->id, 'reason' => 'ventana_cerrada'],
                    ],
                    'excluded_truncated' => false,
                ],
            ], 201),
        ]);

        $this->actingAs($this->owner)->post(route('broadcasts.store'), [
            'name' => 'Aviso de inicio',
            'message' => 'Arrancamos el lunes',
            'filters' => [],
            'lead_ids' => [$ana->id, $beto->id],
        ])->assertRedirect();

        // Una sola llamada con la audiencia entera: el motor viejo hacía un
        // request por destinatario.
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), '/api/v1/broadcasts'))->count());

        Http::assertSent(function ($request) use ($ana, $beto) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/v1/broadcasts')
                && $body['body_type'] === 'text'
                && $body['audience'] === 'phones'
                && count($body['recipients']) === 2
                // El lead viaja como external_ref: es lo que permite marcar de
                // vuelta la fila exacta.
                && collect($body['recipients'])->pluck('external_ref')->sort()->values()->all()
                    === collect([$ana->id, $beto->id])->sort()->values()->all();
        });

        // El motor local ya no existe. Se afirma sobre la clase y no con
        // `Queue::assertNotPushed`, que necesitaría nombrarla para comprobar
        // que no se usa — y volvería a fallar el día que alguien la recree.
        $this->assertFalse(
            class_exists('App\Jobs\SendBroadcastMessageJob'),
            'El motor de envíos local volvió a existir: los broadcasts tienen que salir por el wacrm.'
        );

        $broadcast = Broadcast::firstOrFail();

        $this->assertSame('11111111-1111-4111-8111-111111111111', $broadcast->wacrm_broadcast_id);
        $this->assertTrue($broadcast->isDelegated());
        // El total es lo que SALE, no lo pedido: si dijera 2, la barra de
        // progreso se quedaría en el 50 % para siempre.
        $this->assertSame(1, $broadcast->total_recipients);
        $this->assertSame(1, $broadcast->report['out_of_window']);
    }

    public function test_los_excluidos_quedan_marcados_con_el_motivo_y_la_audiencia_completa_se_congela(): void
    {
        Queue::fake();

        $ana = $this->lead('584125550001', 'Ana');
        $beto = $this->lead('584125550002', 'Beto');

        Http::fake([
            '*/api/v1/broadcasts' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111',
                'status' => 'sending',
                'report' => [
                    'requested' => 2, 'unknown_contacts' => 1, 'out_of_window' => 1, 'sending_to' => 1,
                    'excluded' => [
                        ['phone' => '584125550002', 'external_ref' => $beto->id, 'reason' => 'sin_conversacion'],
                    ],
                    'excluded_truncated' => false,
                ],
            ], 201),
        ]);

        $this->actingAs($this->owner)->post(route('broadcasts.store'), [
            'name' => 'Aviso',
            'message' => 'Hola',
            'filters' => [],
            'lead_ids' => [$ana->id, $beto->id],
        ]);

        $broadcast = Broadcast::firstOrFail();

        // La audiencia entera se guarda, no solo la que sale: a quién se le
        // quiso escribir es parte del hecho histórico.
        $this->assertSame(2, $broadcast->recipients()->count());

        $excluido = $broadcast->recipients()->where('lead_id', $beto->id)->firstOrFail();
        $this->assertSame('skipped', $excluido->status);
        $this->assertStringContainsString('Nunca escribió por WhatsApp', $excluido->error);

        $enviado = $broadcast->recipients()->where('lead_id', $ana->id)->firstOrFail();
        $this->assertSame('pending', $enviado->status);
        $this->assertNull($enviado->error);
    }

    public function test_si_el_wacrm_rechaza_el_envio_el_motivo_aterriza_en_la_pantalla(): void
    {
        $ana = $this->lead('584125550001', 'Ana');

        Http::fake([
            '*/api/v1/broadcasts' => Http::response([
                'message' => 'Ningún destinatario tiene la ventana de 24 h abierta: un mensaje de texto no les llegaría. Usá una plantilla aprobada.',
            ], 422),
        ]);

        $this->actingAs($this->owner)
            ->from(route('broadcasts.create'))
            ->post(route('broadcasts.store'), [
                'name' => 'Aviso',
                'message' => 'Hola',
                'filters' => [],
                'lead_ids' => [$ana->id],
            ])
            ->assertRedirect(route('broadcasts.create'))
            ->assertSessionHasErrors('name');

        // Y no queda un broadcast fantasma que diga «enviando» para siempre.
        $this->assertSame(0, Broadcast::count());
    }

    public function test_sin_integracion_configurada_no_se_crea_el_broadcast(): void
    {
        Integration::forAccount($this->account->id)->delete();

        $ana = $this->lead('584125550001', 'Ana');

        $this->actingAs($this->owner)
            ->from(route('broadcasts.create'))
            ->post(route('broadcasts.store'), [
                'name' => 'Aviso',
                'message' => 'Hola',
                'filters' => [],
                'lead_ids' => [$ana->id],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Broadcast::count());
    }

    public function test_el_detalle_toma_los_contadores_del_wacrm(): void
    {
        $broadcast = Broadcast::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'name' => 'Aviso',
            'message' => 'Hola',
            'status' => 'running',
            'wacrm_broadcast_id' => '11111111-1111-4111-8111-111111111111',
            'total_recipients' => 3,
        ]);

        Http::fake([
            '*/api/v1/broadcasts/*' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111',
                'status' => 'sent',
                'sent_count' => 2,
                'failed_count' => 1,
                'failure_reasons' => ['Ventana de 24 h cerrada: hace falta una plantilla aprobada.' => 1],
            ]),
        ]);

        $this->actingAs($this->owner)->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('broadcast.sent_count', 2)
                ->where('broadcast.failed_count', 1)
                ->where('broadcast.status', 'completed')
                ->where('remoteError', null)
                ->where('failureReasons', ['Ventana de 24 h cerrada: hace falta una plantilla aprobada.' => 1])
            );

        // Los contadores quedan cacheados para el listado, que no consulta al wacrm.
        $this->assertSame(2, $broadcast->refresh()->sent_count);
    }

    public function test_si_el_wacrm_no_responde_la_pantalla_no_se_rompe(): void
    {
        $broadcast = Broadcast::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'name' => 'Aviso',
            'message' => 'Hola',
            'status' => 'running',
            'wacrm_broadcast_id' => '11111111-1111-4111-8111-111111111111',
            'total_recipients' => 3,
            'sent_count' => 1,
        ]);

        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->actingAs($this->owner)->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Lo último que se supo, y se dice que puede estar viejo.
                ->where('broadcast.sent_count', 1)
                ->where('remoteError', fn ($e) => is_string($e) && str_contains($e, 'No se pudo consultar'))
            );
    }

    public function test_un_broadcast_viejo_no_consulta_al_wacrm(): void
    {
        // Anterior a D1b: se envió con el motor local y sus contadores son la
        // única verdad que queda. Reescribirlos sería mentir sobre lo que pasó.
        $broadcast = Broadcast::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'name' => 'Envío viejo',
            'message' => 'Hola',
            'status' => 'completed',
            'total_recipients' => 10,
            'sent_count' => 9,
            'failed_count' => 1,
        ]);

        Http::fake();

        $this->actingAs($this->owner)->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('broadcast.sent_count', 9));

        // La pantalla hace otras llamadas al wacrm (el estado de la IA viaja en
        // las props compartidas); lo que no puede hacer es ir a buscar un
        // broadcast que allá no existe.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/broadcasts'));
    }
}
