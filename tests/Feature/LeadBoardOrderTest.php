<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orden del tablero de leads (`/leads`, y su alias `/pipelines`).
 *
 * La columna se lee de arriba hacia abajo, asi que arriba tiene que estar lo
 * que hay que atender: el lead cuyo contacto acaba de escribir. Ordenar por
 * `created_at` a secas dejaba enterrada la conversacion recien movida debajo
 * de leads viejos sin actividad.
 */
class LeadBoardOrderTest extends TestCase
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
    }

    private function lead(string $name, string $createdAt): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => $name,
            'source' => 'whatsapp',
            'responsible_user_id' => $this->owner->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    private function inbound(Lead $lead, string $at): void
    {
        $event = LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_in',
            'payload' => ['text' => 'Hola'],
        ]);

        $event->forceFill(['created_at' => $at])->save();
    }

    /** @return list<string> titulos en el orden en que los recibe el tablero */
    private function boardOrder(): array
    {
        return collect(
            $this->actingAs($this->owner)
                ->get(route('leads.index'))
                ->viewData('page')['props']['leads']
        )->pluck('title')->all();
    }

    public function test_el_mensaje_entrante_sube_el_lead_a_la_cima(): void
    {
        $viejo = $this->lead('Lead viejo', now()->subDays(10)->toDateTimeString());
        $this->lead('Lead reciente', now()->subDay()->toDateTimeString());

        // El contacto del lead viejo acaba de escribir: pasa a ser lo primero
        // que el asesor tiene que ver.
        $this->inbound($viejo, now()->toDateTimeString());

        $this->assertSame(['Lead viejo', 'Lead reciente'], $this->boardOrder());
    }

    public function test_un_lead_nuevo_sin_mensajes_arranca_arriba(): void
    {
        $conMensaje = $this->lead('Con mensaje', now()->subDays(3)->toDateTimeString());
        $this->inbound($conMensaje, now()->subHours(2)->toDateTimeString());

        $this->lead('Recien creado', now()->toDateTimeString());

        $this->assertSame(['Recien creado', 'Con mensaje'], $this->boardOrder());
    }

    public function test_sin_actividad_manda_la_fecha_de_creacion(): void
    {
        $this->lead('Antiguo', now()->subDays(5)->toDateTimeString());
        $this->lead('Nuevo', now()->subDay()->toDateTimeString());

        $this->assertSame(['Nuevo', 'Antiguo'], $this->boardOrder());
    }
}
