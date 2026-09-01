<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D5 — la etapa se correlaciona por uuid, no por nombre.
 *
 * El espejo de etapa funciona en las DOS direcciones (Komo → wacrm por
 * `setConversationStage`, wacrm → Komo por el webhook `deal.stage_changed`) y
 * hasta acá las dos correspondían la etapa **por su nombre**. Dos consecuencias
 * que no daban error ni dejaban rastro:
 *
 *  - Dos etapas homónimas en pipelines distintos: el movimiento podía aterrizar
 *    en la columna equivocada.
 *  - `->latest()` para resolver el lead de una conversación: si el cliente
 *    volvió meses después y se abrió un lead nuevo, mover la tarjeta en el
 *    wacrm podía reabrir un negocio ya cerrado.
 */
class StageCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Pipeline $pipeline;

    /** @var array<int, PipelineStage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);

        foreach ([['Nuevo', 'open'], ['Contactado', 'open'], ['Ganado', 'won']] as $i => [$name, $type]) {
            $this->stages[$i] = PipelineStage::create([
                'pipeline_id' => $this->pipeline->id,
                'name' => $name,
                'stage_type' => $type,
                'position' => $i,
            ]);
        }

        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);
    }

    private function lead(PipelineStage $stage, string $convId, string $status = 'open'): Lead
    {
        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
            'title' => 'WhatsApp: entrante',
            'source' => 'whatsapp',
            'status' => $status,
            'wacrm_conversation_id' => $convId,
        ]);
    }

    private function webhook(array $data)
    {
        $body = json_encode(['event' => 'deal.stage_changed', 'data' => $data]);

        return $this->call('POST', "/webhooks/wacrm/{$this->account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_hacia_el_wacrm_viaja_el_uuid_de_la_etapa(): void
    {
        $lead = $this->lead($this->stages[0], 'conv-1');

        Http::fake(['*/conversations/*/stage' => Http::response(['ok' => true])]);

        $this->actingAs($this->owner)
            ->patch(route('leads.move', $lead), ['stage_id' => $this->stages[1]->id])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            // El nombre se sigue mandando: el payload es aditivo y un wacrm sin
            // desplegar tiene que seguir funcionando por nombre.
            return $request->data()['stage_external_id'] === $this->stages[1]->id
                && $request->data()['stage_name'] === 'Contactado';
        });
    }

    public function test_desde_el_wacrm_el_uuid_gana_sobre_el_nombre(): void
    {
        $lead = $this->lead($this->stages[0], 'conv-2');

        // El nombre apunta a una etapa y el uuid a otra. El uuid manda: es la
        // correspondencia que no se rompe al renombrar ni se confunde entre
        // homónimas.
        $this->webhook([
            'conversation_id' => 'conv-2',
            'stage_external_id' => $this->stages[1]->id,
            'stage_name' => 'Nuevo',
            'status' => 'open',
        ])->assertOk();

        $this->assertSame($this->stages[1]->id, $lead->fresh()->stage_id);
    }

    public function test_sin_uuid_sigue_funcionando_por_nombre(): void
    {
        $lead = $this->lead($this->stages[0], 'conv-3');

        // Exactamente el payload de antes de D5: un wacrm todavía sin
        // desplegar. Los deploys no son simultáneos.
        $this->webhook([
            'conversation_id' => 'conv-3',
            'stage_name' => 'Contactado',
            'status' => 'open',
        ])->assertOk();

        $this->assertSame($this->stages[1]->id, $lead->fresh()->stage_id);
    }

    public function test_un_uuid_de_otro_pipeline_no_mueve_el_lead(): void
    {
        $otro = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Otro']);
        $ajena = PipelineStage::create(['pipeline_id' => $otro->id, 'name' => 'Contactado', 'stage_type' => 'open', 'position' => 0]);

        $lead = $this->lead($this->stages[0], 'conv-4');

        // La etapa existe y hasta se llama igual, pero es de otro pipeline. Sin
        // el uuid, el nombre la habría hecho pasar por buena.
        $this->webhook([
            'conversation_id' => 'conv-4',
            'stage_external_id' => $ajena->id,
            'stage_name' => 'Contactado',
            'status' => 'open',
        ])->assertOk();

        // Cae al respaldo por nombre DENTRO de su pipeline, que es lo correcto:
        // nunca a una columna de otro tablero.
        $this->assertSame($this->stages[1]->id, $lead->fresh()->stage_id);
        $this->assertSame($this->pipeline->id, $lead->fresh()->pipeline_id);
    }

    public function test_gana_el_lead_abierto_y_no_el_mas_reciente(): void
    {
        // El cliente escribió, se ganó el negocio, y meses después volvió por la
        // misma conversación: hay dos leads sobre `conv-5`.
        $cerrado = $this->lead($this->stages[2], 'conv-5', status: 'won');
        $abierto = $this->lead($this->stages[0], 'conv-5');

        // `latest()` a secas devolvía el más nuevo sin mirar el estado. Acá el
        // abierto TAMBIÉN es el más nuevo, así que para que el test signifique
        // algo hay que invertir el orden de creación.
        $cerrado->forceFill(['created_at' => now()->addMinute()])->save();

        $this->webhook([
            'conversation_id' => 'conv-5',
            'stage_external_id' => $this->stages[1]->id,
            'stage_name' => 'Contactado',
            'status' => 'open',
        ])->assertOk();

        // El abierto se movió; el cerrado no se tocó. Reabrir en silencio un
        // negocio que el equipo dio por terminado es la peor forma de fallar.
        $this->assertSame($this->stages[1]->id, $abierto->fresh()->stage_id);
        $this->assertSame($this->stages[2]->id, $cerrado->fresh()->stage_id);
        $this->assertSame('won', $cerrado->fresh()->status);
    }
}
