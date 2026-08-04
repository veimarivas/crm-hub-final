<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un envío que no sale tiene que DECIR por qué.
 *
 * El caso sin destinatarios cortaba con `abort(422)`, que no es una respuesta
 * de validación: Inertia la descarta y el botón «Enviar» parece no hacer nada.
 * Tiene que volver como error de validación para que aterrice en la pantalla.
 */
class BroadcastStoreFailureTest extends TestCase
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

    public function test_sin_destinatarios_devuelve_un_error_visible_y_no_un_422_mudo(): void
    {
        $this->actingAs($this->owner)
            ->from(route('broadcasts.create'))
            ->post(route('broadcasts.store'), [
                'name' => 'Prueba',
                'message' => 'Hola',
                'filters' => ['tags' => ['00000000-0000-4000-8000-000000000000']],
            ])
            ->assertRedirect(route('broadcasts.create'))
            ->assertSessionHasErrors('lead_ids');
    }

    public function test_un_contacto_sin_telefono_no_cuenta_como_destinatario(): void
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Sin tel']);
        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'Sin tel',
            'source' => 'whatsapp',
        ]);

        $this->actingAs($this->owner)
            ->from(route('broadcasts.create'))
            ->post(route('broadcasts.store'), [
                'name' => 'Prueba',
                'message' => 'Hola',
                'filters' => [],
                'lead_ids' => [$lead->id],
            ])
            ->assertSessionHasErrors('lead_ids');
    }
}
