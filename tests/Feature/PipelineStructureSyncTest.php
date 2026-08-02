<?php

namespace Tests\Feature;

use App\Jobs\SyncLeadStageToWacrmJob;
use App\Jobs\SyncPipelinesToWacrmJob;
use App\Models\Account;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sincronización de la estructura de pipelines Komo → wacrm y del espejo
 * inverso (deal.stage_changed del wacrm mueve el lead del Komo).
 */
class PipelineStructureSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Account,1:User,2:Collection} */
    private function makeAccount(): array
    {
        $owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $pipeline = Pipeline::create(['account_id' => $account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Contactado', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
            ['name' => 'Perdido', 'stage_type' => 'lost'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $pipeline->id, 'position' => $i, ...$s]));

        return [$account, $owner->fresh(), $stages];
    }

    private function enableIntegration(Account $account): void
    {
        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);
    }

    public function test_cambio_en_pipeline_despacha_sync_de_estructura(): void
    {
        [$account, $owner] = $this->makeAccount();

        Queue::fake();

        $this->actingAs($owner)->post(route('pipelines.store'), ['name' => 'Nuevo pipeline'])
            ->assertRedirect();

        Queue::assertPushed(SyncPipelinesToWacrmJob::class, fn ($job) => $job->accountId === $account->id);
    }

    public function test_job_envia_payload_completo_de_estructura(): void
    {
        [$account, , $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        Http::fake(['*/pipelines/sync' => Http::response(['ok' => true])]);

        (new SyncPipelinesToWacrmJob($account->id))->sync();

        Http::assertSent(function ($request) use ($stages) {
            if (! str_contains($request->url(), '/api/v1/pipelines/sync') || $request->method() !== 'POST') {
                return false;
            }

            $pipeline = $request['pipelines'][0] ?? [];

            return $pipeline['name'] === 'Ventas'
                && $pipeline['is_default'] === true
                && count($pipeline['stages']) === 4
                && $pipeline['stages'][0]['name'] === 'Nuevo'
                && $pipeline['stages'][0]['stage_type'] === 'open'
                && $pipeline['stages'][2]['id'] === $stages[2]->id
                && $pipeline['stages'][2]['stage_type'] === 'won';
        });
    }

    public function test_webhook_deal_stage_changed_mueve_el_lead(): void
    {
        [$account, , $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stages[0]->pipeline_id,
            'stage_id' => $stages[0]->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-1',
        ]);

        $body = json_encode([
            'event' => 'deal.stage_changed',
            'data' => ['conversation_id' => 'conv-1', 'stage_name' => 'Contactado', 'status' => 'open'],
        ]);

        $this->call('POST', "/webhooks/wacrm/{$account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame($stages[1]->id, $lead->fresh()->stage_id);
        $this->assertSame(Lead::STATUS_OPEN, $lead->fresh()->status);
    }

    public function test_webhook_deal_stage_changed_a_etapa_terminal(): void
    {
        [$account, , $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stages[0]->pipeline_id,
            'stage_id' => $stages[0]->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-2',
        ]);

        $body = json_encode([
            'event' => 'deal.stage_changed',
            'data' => ['conversation_id' => 'conv-2', 'stage_name' => 'Ganado', 'status' => 'won'],
        ]);

        $this->call('POST', "/webhooks/wacrm/{$account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame($stages[2]->id, $lead->fresh()->stage_id);
        $this->assertSame(Lead::STATUS_WON, $lead->fresh()->status);
    }

    public function test_webhook_deal_stage_changed_idempotente(): void
    {
        [$account, , $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stages[0]->pipeline_id,
            'stage_id' => $stages[0]->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-3',
        ]);

        $body = json_encode([
            'event' => 'deal.stage_changed',
            'data' => ['conversation_id' => 'conv-3', 'stage_name' => 'Nuevo', 'status' => 'open'],
        ]);

        Queue::fake();

        $this->call('POST', "/webhooks/wacrm/{$account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // Misma etapa → no se mueve ni se espeja de vuelta (evita rebotes).
        $this->assertSame($stages[0]->id, $lead->fresh()->stage_id);
        Queue::assertNotPushed(SyncLeadStageToWacrmJob::class);
    }

    public function test_webhook_con_etapa_desconocida_se_ignora(): void
    {
        [$account, , $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stages[0]->pipeline_id,
            'stage_id' => $stages[0]->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-4',
        ]);

        $body = json_encode([
            'event' => 'deal.stage_changed',
            'data' => ['conversation_id' => 'conv-4', 'stage_name' => 'Etapa inexistente', 'status' => 'open'],
        ]);

        $this->call('POST', "/webhooks/wacrm/{$account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame($stages[0]->id, $lead->fresh()->stage_id);
    }
}
