<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Espejo de etapa Komo → wacrm: mover un lead en el kanban debe mover el
 * deal de la conversación en /pipelines del wacrm.
 */
class LeadStageSyncTest extends TestCase
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
            ['name' => 'Negociación', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
            ['name' => 'Perdido', 'stage_type' => 'lost'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $pipeline->id, 'position' => $i, ...$s]));

        return [$account, $owner->fresh(), $stages];
    }

    private function newWhatsappLead(Account $account, PipelineStage $stage, string $convId): Lead
    {
        return Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
            'title' => 'WhatsApp: entrante',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => $convId,
        ]);
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

    public function test_mover_de_etapa_espeja_stage_y_status_en_el_wacrm(): void
    {
        [$account, $owner, $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = $this->newWhatsappLead($account, $stages[0], 'conv-xyz');

        Http::fake(['*/conversations/*/stage' => Http::response(['ok' => true])]);

        $this->actingAs($owner)->patch(route('leads.move', $lead), ['stage_id' => $stages[2]->id])->assertRedirect();

        $this->assertSame($stages[2]->id, $lead->fresh()->stage_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/conversations/conv-xyz/stage')
                && $request->method() === 'PATCH'
                && $request['stage_name'] === 'Negociación'
                && $request['status'] === 'open';
        });
    }

    public function test_etapa_terminal_espeja_status_won(): void
    {
        [$account, $owner, $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = $this->newWhatsappLead($account, $stages[0], 'conv-ganado');

        Http::fake(['*/conversations/*/stage' => Http::response(['ok' => true])]);

        $this->actingAs($owner)->patch(route('leads.move', $lead), ['stage_id' => $stages[3]->id])->assertRedirect();

        $this->assertSame($stages[3]->id, $lead->fresh()->stage_id);
        $this->assertSame(Lead::STATUS_WON, $lead->fresh()->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/conversations/conv-ganado/stage')
                && $request['stage_name'] === 'Ganado'
                && $request['status'] === 'won';
        });
    }

    public function test_el_lead_sin_conversacion_no_llama_al_wacrm(): void
    {
        [$account, $owner, $stages] = $this->makeAccount();
        $this->enableIntegration($account);

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $stages[0]->pipeline_id,
            'stage_id' => $stages[0]->id,
            'title' => 'Web: sin conversacion',
            'source' => 'web_form',
        ]);

        Http::fake();

        $this->actingAs($owner)->patch(route('leads.move', $lead), ['stage_id' => $stages[1]->id])->assertRedirect();

        Http::assertNothingSent();
    }
}
