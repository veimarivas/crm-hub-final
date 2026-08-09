<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Email\EmailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T6 — correo corporativo de Google Workspace.
 *
 * Dos decisiones del usuario que estos tests fijan:
 *
 *  1. **OAuth con Workspace**, no contraseña de aplicación.
 *  2. **El correo es un `message_in`/`message_out` mas**, con
 *     `payload.channel = 'email'` y `source = 'email'` en el lead. Eso hace que
 *     supervisión, copiloto y segmentos funcionen sobre el correo sin tocar
 *     `ResponseMetrics` — el GEMELO del wacrm, que no se modifica.
 */
class EmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otro;

    private Account $account;

    private EmailAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'cid');
        config()->set('services.google.client_secret', 'secret');
        config()->set('services.google.redirect', 'https://komo.test/settings/email/callback');
        config()->set('services.google.workspace_domain', 'esam.edu.bo');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'ESAM', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->otro = User::create([
            'name' => 'Otro', 'email' => 'x@test.com', 'password' => bcrypt('secret'),
            'account_id' => $this->account->id, 'account_role' => 'agent',
        ]);

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        PipelineStage::create([
            'pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open',
            'position' => 0, 'color' => '#0ea5e9',
        ]);

        $this->mailbox = EmailAccount::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'email' => 'admisiones@esam.edu.bo',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'last_history_id' => '1000',
        ]);
    }

    /** Respuesta de Gmail para un mensaje. */
    private function gmailMessage(string $id, string $from, string $to, string $body): array
    {
        return [
            'id' => $id,
            'threadId' => 'thread-1',
            'snippet' => 'resumen',
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [
                    ['name' => 'From', 'value' => $from],
                    ['name' => 'To', 'value' => $to],
                    ['name' => 'Subject', 'value' => 'Consulta por el diplomado'],
                    ['name' => 'Message-ID', 'value' => '<abc@mail>'],
                ],
                'parts' => [[
                    'mimeType' => 'multipart/alternative',
                    'parts' => [[
                        'mimeType' => 'text/plain',
                        'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
                    ]],
                ]],
            ],
        ];
    }

    private function fakeGmail(array $ids, array $messages, string $historyId = '1200'): void
    {
        Http::fake([
            '*/gmail/v1/users/me/history*' => Http::response([
                'historyId' => $historyId,
                'history' => [['messagesAdded' => collect($ids)->map(fn ($id) => ['message' => ['id' => $id]])->all()]],
            ]),
            '*/gmail/v1/users/me/profile' => Http::response(['historyId' => $historyId]),
            ...collect($messages)->mapWithKeys(fn ($m, $id) => ["*/gmail/v1/users/me/messages/{$id}*" => Http::response($m)])->all(),
        ]);
    }

    // ---- Sincronización ----

    public function test_un_correo_entrante_entra_al_timeline_como_message_in(): void
    {
        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'Ana <ana@gmail.com>', 'admisiones@esam.edu.bo', 'Hola, quiero informacion'),
        ]);

        $result = app(EmailSync::class)->sync($this->mailbox);

        $this->assertSame(1, $result['imported']);

        $lead = Lead::first();
        $event = $lead->events()->first();

        $this->assertSame('message_in', $event->event_type);
        // El canal permite separar correo de WhatsApp el dia que haga falta,
        // sin haber tocado ResponseMetrics.
        $this->assertSame('email', $event->payload['channel']);
        $this->assertSame('Consulta por el diplomado', $event->payload['subject']);
        $this->assertStringContainsString('quiero informacion', $event->payload['text']);
    }

    public function test_el_lead_nace_con_source_email_y_el_dueno_de_la_casilla_como_responsable(): void
    {
        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'ana@gmail.com', 'admisiones@esam.edu.bo', 'Hola'),
        ]);

        app(EmailSync::class)->sync($this->mailbox);

        $lead = Lead::first();
        $this->assertSame('email', $lead->source);
        $this->assertSame($this->owner->id, $lead->responsible_user_id);
        $this->assertSame('ana@gmail.com', Contact::first()->email);
    }

    public function test_un_correo_saliente_se_graba_como_message_out_de_un_humano(): void
    {
        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'admisiones@esam.edu.bo', 'ana@gmail.com', 'Te paso el detalle'),
        ]);

        app(EmailSync::class)->sync($this->mailbox);

        $event = Lead::first()->events()->first();

        $this->assertSame('message_out', $event->event_type);
        // Sin `sender`, el resto del sistema lo trataria como respuesta de la IA
        // y la supervision diria que nadie atendio.
        $this->assertSame('agent', $event->payload['sender']);
        $this->assertSame($this->owner->id, $event->user_id);
    }

    public function test_sincronizar_dos_veces_no_duplica(): void
    {
        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'ana@gmail.com', 'admisiones@esam.edu.bo', 'Hola'),
        ]);

        app(EmailSync::class)->sync($this->mailbox);
        $this->mailbox->forceFill(['last_history_id' => '1000'])->save();
        $segunda = app(EmailSync::class)->sync($this->mailbox->refresh());

        $this->assertSame(0, $segunda['imported']);
        $this->assertSame(1, Lead::first()->events()->count());
    }

    public function test_reusa_el_lead_abierto_del_contacto(): void
    {
        $contact = Contact::create([
            'account_id' => $this->account->id, 'name' => 'Ana', 'email' => 'ana@gmail.com',
        ]);
        $pipeline = Pipeline::first();
        $existente = Lead::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->first()->id, 'contact_id' => $contact->id,
            'title' => 'Ana', 'source' => 'whatsapp', 'responsible_user_id' => $this->owner->id,
        ]);

        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'ana@gmail.com', 'admisiones@esam.edu.bo', 'Hola de nuevo'),
        ]);

        app(EmailSync::class)->sync($this->mailbox);

        // Abrir un lead por cada correo convertiria el pipeline en una bandeja
        // de entrada.
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, $existente->events()->count());
    }

    public function test_la_primera_pasada_no_importa_correo_viejo(): void
    {
        $this->mailbox->forceFill(['last_history_id' => null])->save();
        $this->fakeGmail(['m1'], [
            'm1' => $this->gmailMessage('m1', 'ana@gmail.com', 'admisiones@esam.edu.bo', 'Hola'),
        ]);

        $result = app(EmailSync::class)->sync($this->mailbox);

        // Importar la casilla entera llenaria el timeline de meses de correo.
        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Lead::count());
        $this->assertSame('1200', $this->mailbox->refresh()->last_history_id);
    }

    public function test_un_punto_de_historia_caducado_se_rearma_sin_quedar_en_bucle(): void
    {
        Http::fake([
            '*/gmail/v1/users/me/history*' => Http::response(['error' => 'not found'], 404),
            '*/gmail/v1/users/me/profile' => Http::response(['historyId' => '9999']),
        ]);

        $result = app(EmailSync::class)->sync($this->mailbox);

        $this->assertSame(0, $result['imported']);
        $this->assertSame('9999', $this->mailbox->refresh()->last_history_id);
        $this->assertStringContainsString('caducó', $this->mailbox->last_error);
    }

    // ---- Tokens ----

    public function test_el_access_token_se_renueva_sin_perder_el_refresh(): void
    {
        $this->mailbox->forceFill(['token_expires_at' => now()->subMinutes(5)])->save();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'nuevo', 'expires_in' => 3600]),
            '*/gmail/v1/users/me/history*' => Http::response(['historyId' => '1200', 'history' => []]),
        ]);

        app(EmailSync::class)->sync($this->mailbox);
        $this->mailbox->refresh();

        $this->assertSame('nuevo', $this->mailbox->access_token);
        // Google NO reenvia el refresh al renovar: pisarlo con null
        // desconectaria la casilla en la primera renovacion.
        $this->assertSame('refresh', $this->mailbox->refresh_token);
    }

    public function test_los_tokens_se_guardan_cifrados(): void
    {
        $crudo = \Illuminate\Support\Facades\DB::table('email_accounts')
            ->where('id', $this->mailbox->id)->value('refresh_token');

        // Un volcado de la base no puede dejar en texto plano un token que da
        // acceso al correo de la institucion.
        $this->assertNotSame('refresh', $crudo);
        $this->assertSame('refresh', $this->mailbox->refresh_token);
    }

    // ---- Conexión OAuth ----

    public function test_el_callback_rechaza_un_state_que_no_coincide(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['google_oauth_state' => 'esperado'])
            ->get(route('settings.email.callback', ['code' => 'x', 'state' => 'otro']))
            ->assertRedirect(route('settings.email'));

        $this->assertSame(1, EmailAccount::count()); // no se creó ninguna nueva
    }

    public function test_solo_se_aceptan_casillas_del_dominio_institucional(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600, 'refresh_token' => 'r']),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'personal@gmail.com']),
        ]);

        $this->actingAs($this->owner)
            ->withSession(['google_oauth_state' => 'ok'])
            ->get(route('settings.email.callback', ['code' => 'x', 'state' => 'ok']))
            ->assertRedirect(route('settings.email'))
            ->assertSessionHas('error');

        $this->assertFalse(EmailAccount::where('email', 'personal@gmail.com')->exists());
    }

    public function test_reconectar_no_borra_el_refresh_token_si_google_no_lo_reenvia(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a2', 'expires_in' => 3600]),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'admisiones@esam.edu.bo']),
        ]);

        $this->actingAs($this->owner)
            ->withSession(['google_oauth_state' => 'ok'])
            ->get(route('settings.email.callback', ['code' => 'x', 'state' => 'ok']));

        $this->assertSame('refresh', $this->mailbox->refresh()->refresh_token);
        $this->assertSame('a2', $this->mailbox->access_token);
    }

    // ---- Permisos ----

    public function test_solo_el_dueno_administra_su_casilla(): void
    {
        $this->actingAs($this->otro)
            ->post(route('settings.email.sync', $this->mailbox))
            ->assertForbidden();

        $this->actingAs($this->otro)
            ->delete(route('settings.email.destroy', $this->mailbox))
            ->assertForbidden();
    }

    public function test_la_pantalla_avisa_cuando_falta_reconectar(): void
    {
        $this->mailbox->forceFill(['refresh_token' => null])->save();

        $props = $this->actingAs($this->owner)->get(route('settings.email'))
            ->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['mailboxes'][0]['needs_reconnect']);
    }
}
