<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
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
 * Round-robin de leads entrantes + espejo de la asignacion en el wacrm.
 *
 * Dos reglas que se rompieron en produccion y este test fija:
 *   1. El owner/admin NO entra en el reparto automatico.
 *   2. Toda asignacion (automatica o manual) se replica en la conversacion
 *      del wacrm, si no la conversacion queda "Sin asignar" en el Inbox.
 */
class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Account,1:User,2:Pipeline,3:Collection} */
    private function makeAccount(bool $autoAssign = true): array
    {
        $owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id, 'auto_assign_leads' => $autoAssign]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $pipeline = Pipeline::create(['account_id' => $account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
            ['name' => 'Perdido', 'stage_type' => 'lost'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $pipeline->id, 'position' => $i, ...$s]));

        return [$account, $owner->fresh(), $pipeline, $stages];
    }

    private function makeUser(Account $account, string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role).' '.$email,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_id' => $account->id,
            'account_role' => $role,
        ]);
    }

    private function newWhatsappLead(Account $account, Pipeline $pipeline, PipelineStage $stage, string $convId = 'conv-1'): Lead
    {
        return Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'WhatsApp: entrante',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => $convId,
        ]);
    }

    public function test_round_robin_no_asigna_al_owner_ni_al_admin(): void
    {
        [$account, $owner, $pipeline, $stages] = $this->makeAccount();
        $admin = $this->makeUser($account, User::ROLE_ADMIN, 'jefe@test.com');
        $agent = $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        $lead = $this->newWhatsappLead($account, $pipeline, $stages[0]);

        // El unico elegible es el agente, aunque owner y admin tengan 0 leads.
        $this->assertSame($agent->id, $lead->fresh()->responsible_user_id);
        $this->assertNotSame($owner->id, $lead->fresh()->responsible_user_id);
        $this->assertNotSame($admin->id, $lead->fresh()->responsible_user_id);
    }

    public function test_sin_agentes_el_lead_queda_sin_responsable_en_vez_de_caer_en_el_admin(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $this->makeUser($account, User::ROLE_ADMIN, 'jefe@test.com');
        $this->makeUser($account, User::ROLE_VIEWER, 'miron@test.com');

        $lead = $this->newWhatsappLead($account, $pipeline, $stages[0]);

        $this->assertNull($lead->fresh()->responsible_user_id);
    }

    public function test_reparte_al_agente_con_menos_leads_abiertos(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $ocupado = $this->makeUser($account, User::ROLE_AGENT, 'ocupado@test.com');
        $libre = $this->makeUser($account, User::ROLE_AGENT, 'libre@test.com');

        // El agente "ocupado" ya arrastra dos leads abiertos.
        foreach (['a', 'b'] as $n) {
            Lead::create([
                'account_id' => $account->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stages[0]->id,
                'title' => "previo-{$n}",
                'source' => 'manual',
                'responsible_user_id' => $ocupado->id,
            ]);
        }

        $lead = $this->newWhatsappLead($account, $pipeline, $stages[0]);

        $this->assertSame($libre->id, $lead->fresh()->responsible_user_id);
    }

    public function test_los_leads_manuales_no_se_reasignan(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $agent = $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        $lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stages[0]->id,
            'title' => 'Creado a mano',
            'source' => 'manual',
        ]);

        $this->assertNull($lead->fresh()->responsible_user_id);
        $this->assertNotSame($agent->id, $lead->fresh()->responsible_user_id);
    }

    public function test_la_asignacion_automatica_se_espeja_en_el_wacrm(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $agent = $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        Http::fake(['*/conversations/*/assign' => Http::response(['ok' => true])]);

        $lead = $this->newWhatsappLead($account, $pipeline, $stages[0], 'conv-abc');

        $this->assertSame($agent->id, $lead->fresh()->responsible_user_id);

        // El wacrm recibio el PATCH con el email del agente — sin esto la
        // conversacion se queda "Sin asignar" en el Inbox.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/conversations/conv-abc/assign')
                && $request->method() === 'PATCH'
                && $request['email'] === 'agente@test.com';
        });
    }

    public function test_el_cambio_manual_de_responsable_tambien_se_espeja(): void
    {
        [$account, $owner, $pipeline, $stages] = $this->makeAccount(autoAssign: false);
        $agent = $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '59170000000']);
        $lead = $this->newWhatsappLead($account, $pipeline, $stages[0], 'conv-manual');
        $lead->update(['contact_id' => $contact->id]);

        Http::fake(['*/conversations/*/assign' => Http::response(['ok' => true])]);

        $this->actingAs($owner)->patch(route('leads.update', $lead), [
            'title' => $lead->title,
            'value' => 0,
            'responsible_user_id' => $agent->id,
        ])->assertRedirect();

        $this->assertSame($agent->id, $lead->fresh()->responsible_user_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/conversations/conv-manual/assign')
                && $request['email'] === 'agente@test.com';
        });
    }

    public function test_si_el_agente_no_existe_en_el_wacrm_se_provisiona_y_se_reintenta(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        // Primer assign: 422 user_not_found. Tras provisionarlo, el segundo pasa.
        Http::fake([
            '*/conversations/*/assign' => Http::sequence()
                ->push(['message' => 'No hay un usuario con ese email en esta cuenta.'], 422)
                ->push(['ok' => true], 200),
            '*/team/provision' => Http::response(['user' => ['id' => 'u1'], 'created' => true], 201),
        ]);

        $this->newWhatsappLead($account, $pipeline, $stages[0], 'conv-nuevo');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/team/provision')
            && $r['email'] === 'agente@test.com'
            && $r['role'] === 'agent');

        // Dos intentos de assign: el que fallo y el reintento tras provisionar.
        Http::assertSentCount(3);
    }

    public function test_el_lead_sin_conversacion_de_whatsapp_no_llama_al_wacrm(): void
    {
        [$account, , $pipeline, $stages] = $this->makeAccount();
        $this->makeUser($account, User::ROLE_AGENT, 'agente@test.com');

        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        Http::fake();

        // Lead de formulario web: se auto-asigna, pero no hay conversacion.
        Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stages[0]->id,
            'title' => 'Web: sin conversacion',
            'source' => 'web_form',
        ]);

        Http::assertNothingSent();
    }
}
