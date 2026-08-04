<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Envío masivo consciente de la ventana de servicio.
 *
 * La pantalla mandaba a ciegas: un número de destinatarios y nada más. Fuera
 * de la ventana de 24 h (o 72 h si vino de un anuncio) el texto libre NO se
 * entrega — hace falta plantilla aprobada, y esa se factura. Así que el
 * envío tiene que decir quién está adentro, quién afuera, y cuánto costaría.
 */
class BroadcastWindowTest extends TestCase
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

    private function lead(string $name, ?string $ultimoEntrante): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
            'phone_normalized' => '5917'.random_int(1000000, 9999999),
        ]);

        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => $name,
            'source' => 'whatsapp',
        ]);

        if ($ultimoEntrante) {
            LeadEvent::create([
                'lead_id' => $lead->id,
                'account_id' => $this->account->id,
                'event_type' => 'message_in',
                'payload' => ['text' => 'Hola'],
            ])->forceFill(['created_at' => $ultimoEntrante])->save();
        }

        return $lead;
    }

    private function preview(array $filters): array
    {
        return $this->actingAs($this->owner)
            ->postJson(route('broadcasts.preview'), ['filters' => $filters])
            ->assertOk()
            ->json();
    }

    private function tagId(string $name): string
    {
        return Tag::forAccount($this->account->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->firstOrFail()->id;
    }

    public function test_separa_a_los_que_estan_en_ventana_de_los_que_no(): void
    {
        $this->lead('Adentro', now()->subHours(2)->toDateTimeString());
        $this->lead('Afuera', now()->subDays(3)->toDateTimeString());
        $this->lead('Nunca escribio', null);

        $data = $this->preview(['tags' => [$this->tagId(Tag::NEW_LEAD)]]);

        $this->assertSame(3, $data['count']);
        $this->assertSame(1, $data['in_window']);
        $this->assertSame(2, $data['out_of_window']);
    }

    public function test_dice_cuanto_costaria_escribirle_a_los_de_afuera(): void
    {
        $this->lead('Afuera 1', now()->subDays(3)->toDateTimeString());
        $this->lead('Afuera 2', now()->subDays(5)->toDateTimeString());

        $costo = $this->preview(['tags' => [$this->tagId(Tag::NEW_LEAD)]])['cost_out_of_window'];

        $this->assertSame(2, $costo['messages']);
        $this->assertSame(round(2 * config('whatsapp.pricing.rates.marketing'), 4), $costo['total_usd']);
        $this->assertSame('Bolivia', $costo['country']);
    }

    public function test_la_lista_trae_la_ventana_de_cada_uno_y_los_urgentes_primero(): void
    {
        $this->lead('Vence pronto', now()->subHours(23)->toDateTimeString());
        $this->lead('Recien escribio', now()->subMinutes(5)->toDateTimeString());
        $this->lead('Afuera', now()->subDays(3)->toDateTimeString());

        $recipients = $this->preview(['tags' => [$this->tagId(Tag::NEW_LEAD)]])['recipients'];

        $this->assertSame(
            ['Vence pronto', 'Recien escribio', 'Afuera'],
            array_column($recipients, 'name'),
            'Primero los que están por vencer; los de afuera, al final.',
        );
        $this->assertTrue($recipients[0]['window']['is_open']);
        $this->assertFalse($recipients[2]['window']['is_open']);
    }

    public function test_solo_se_envia_a_los_seleccionados(): void
    {
        $elegido = $this->lead('Elegido', now()->subHour()->toDateTimeString());
        $this->lead('Descartado', now()->subHour()->toDateTimeString());

        $this->actingAs($this->owner)
            ->post(route('broadcasts.store'), [
                'name' => 'Prueba',
                'message' => 'Hola',
                'filters' => ['tags' => [$this->tagId(Tag::NEW_LEAD)]],
                'lead_ids' => [$elegido->id],
            ])
            ->assertRedirect();

        $broadcast = \App\Models\Broadcast::forAccount($this->account->id)->firstOrFail();

        $this->assertSame(1, $broadcast->total_recipients);
        $this->assertSame($elegido->id, $broadcast->recipients()->first()->lead_id);
    }

    public function test_un_lead_de_otra_cuenta_no_entra_aunque_se_mande_su_id(): void
    {
        $mio = $this->lead('Mio', now()->subHour()->toDateTimeString());

        $otroOwner = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroOwner->id]);
        $otroPipeline = Pipeline::create(['account_id' => $otraCuenta->id, 'name' => 'P', 'is_default' => true]);
        $otraEtapa = PipelineStage::create(['pipeline_id' => $otroPipeline->id, 'name' => 'N', 'stage_type' => 'open', 'position' => 0]);
        $ajeno = Lead::create([
            'account_id' => $otraCuenta->id,
            'pipeline_id' => $otroPipeline->id,
            'stage_id' => $otraEtapa->id,
            'title' => 'Ajeno',
            'source' => 'manual',
        ]);

        $this->actingAs($this->owner)
            ->post(route('broadcasts.store'), [
                'name' => 'Prueba',
                'message' => 'Hola',
                'filters' => ['tags' => [$this->tagId(Tag::NEW_LEAD)]],
                'lead_ids' => [$mio->id, $ajeno->id],
            ])
            ->assertRedirect();

        $broadcast = \App\Models\Broadcast::forAccount($this->account->id)->firstOrFail();

        $this->assertSame(1, $broadcast->total_recipients);
    }
}
