<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingReuseLeadTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->host = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
            'booking_enabled' => true, 'booking_slug' => 'daniel', 'booking_duration_min' => 30,
        ]);

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function contact(): Contact
    {
        return Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59171234567', 'phone_normalized' => '59171234567']);
    }

    public function test_un_booking_reusa_el_lead_con_conversacion_no_crea_otro(): void
    {
$contact = $this->contact();
        $pipeline = Pipeline::forAccount($this->account->id)->first();
        $existing = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->first()->id,
            'contact_id' => $contact->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-ana',
        ]);

        $before = Lead::count();

        $this->post(route('book.store', 'daniel'), [
            'guest_name' => 'Ana',
            'guest_phone' => '59171234567',
            'scheduled_at' => now()->addDay()->setTime(10, 0)->toISOString(),
        ])->assertRedirect();

        // No se creó un lead nuevo: se reusó el que ya traía el historial.
        $this->assertSame($before, Lead::count());
        $this->assertDatabaseHas('leads', ['id' => $existing->id]);

        // La reserva y la tarea "meet" quedaron ligadas al lead existente.
        $this->assertDatabaseHas('bookings', ['host_user_id' => $this->host->id]);
        $this->assertDatabaseHas('tasks', [
            'lead_id' => $existing->id,
            'task_type' => 'meet',
            'assigned_to' => $this->host->id,
        ]);
        $this->assertDatabaseMissing('leads', ['title' => 'Reunión: Ana']);
    }

    public function test_sin_lead_con_conversacion_se_crea_lead_booking(): void
    {
        $contact = $this->contact();

        $this->post(route('book.store', 'daniel'), [
            'guest_name' => 'Ana',
            'guest_phone' => '+59171234567',
            'scheduled_at' => now()->addHour()->setTime(10, 0)->toISOString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('leads', ['title' => 'Ana', 'source' => 'booking']);
    }
}