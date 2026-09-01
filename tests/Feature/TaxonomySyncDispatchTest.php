<?php

namespace Tests\Feature;

use App\Jobs\SyncTaxonomyToWacrmJob;
use App\Models\Account;
use App\Models\CustomField;
use App\Models\Integration;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D2b — Komo es el dueño de la taxonomía y replica cada cambio al wacrm.
 *
 * Hasta acá los dos proyectos tenían catálogos de etiquetas y campos
 * personalizados que NO se sincronizaban, a diferencia de los pipelines. Una
 * etiqueta creada acá no existía en el inbox y viceversa.
 */
class TaxonomySyncDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'komo_live_test',
            'is_active' => true,
        ]);
    }

    public function test_crear_renombrar_y_borrar_una_etiqueta_dispara_el_sync(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('tags.store'), ['name' => 'Interesado']);
        Queue::assertPushed(SyncTaxonomyToWacrmJob::class, 1);

        $tag = Tag::forAccount($this->account->id)->firstOrFail();

        $this->actingAs($this->owner)->patch(route('tags.update', $tag), ['name' => 'Muy interesado']);
        Queue::assertPushed(SyncTaxonomyToWacrmJob::class, 2);

        $this->actingAs($this->owner)->delete(route('tags.destroy', $tag));
        Queue::assertPushed(SyncTaxonomyToWacrmJob::class, 3);
    }

    public function test_manda_el_catalogo_completo_no_el_cambio(): void
    {
        Tag::create(['account_id' => $this->account->id, 'name' => 'Interesado', 'color' => '#10b981']);
        Tag::create(['account_id' => $this->account->id, 'name' => 'Frío', 'color' => '#64748b']);

        Http::fake(['*/api/v1/taxonomy/sync' => Http::response(['tags' => [], 'custom_fields' => []])]);

        (new SyncTaxonomyToWacrmJob($this->account->id))->sync();

        Http::assertSent(function ($request) {
            $tags = $request->data()['tags'];

            // El catálogo entero en cada pasada, no un delta: así un envío
            // perdido se corrige solo con el cambio siguiente, sin llevar
            // registro de qué quedó pendiente.
            return count($tags) === 2
                && collect($tags)->pluck('name')->sort()->values()->all() === ['Frío', 'Interesado'];
        });
    }

    public function test_solo_viajan_los_campos_de_contacto(): void
    {
        foreach (['lead', 'contact', 'company'] as $entity) {
            CustomField::create([
                'account_id' => $this->account->id,
                'entity' => $entity,
                'name' => "Campo de {$entity}",
                'field_type' => 'text',
            ]);
        }

        $payload = SyncTaxonomyToWacrmJob::customFieldPayload($this->account->id);

        // Allá los campos personalizados cuelgan del contacto: uno de lead
        // sería una columna que nadie podría llenar nunca.
        $this->assertCount(1, $payload);
        $this->assertSame('Campo de contact', $payload[0]['name']);
    }

    public function test_un_campo_de_lead_no_dispara_el_sync(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('custom-fields.store'), [
            'entity' => 'lead',
            'name' => 'Beca',
            'field_type' => 'text',
        ]);

        Queue::assertNotPushed(SyncTaxonomyToWacrmJob::class);

        $this->actingAs($this->owner)->post(route('custom-fields.store'), [
            'entity' => 'contact',
            'name' => 'Carrera',
            'field_type' => 'text',
        ]);

        Queue::assertPushed(SyncTaxonomyToWacrmJob::class, 1);
    }

    public function test_sin_integracion_no_explota(): void
    {
        Integration::forAccount($this->account->id)->delete();

        Http::fake();

        // Devuelve vacío y no lanza: una cuenta sin wacrm es un caso normal,
        // no un error que llene el log en cada cambio de etiqueta.
        $this->assertSame([], (new SyncTaxonomyToWacrmJob($this->account->id))->sync());

        Http::assertNothingSent();
    }

    public function test_un_fallo_del_wacrm_no_rompe_el_cambio_local(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        Tag::create(['account_id' => $this->account->id, 'name' => 'Interesado']);

        // El job traga el error: la etiqueta ya se guardó acá y el próximo
        // cambio vuelve a mandar el catálogo completo.
        (new SyncTaxonomyToWacrmJob($this->account->id))->handle();

        $this->assertSame(1, Tag::forAccount($this->account->id)->count());
    }

    public function test_el_comando_en_dry_run_no_pide_cambios(): void
    {
        Tag::create(['account_id' => $this->account->id, 'name' => 'Interesado']);

        Http::fake(['*/api/v1/taxonomy/sync' => Http::response([
            'dry_run' => true,
            'tags' => ['created' => ['Interesado'], 'linked' => [], 'updated' => [], 'deleted' => [], 'kept_in_use' => []],
            'custom_fields' => [],
        ])]);

        $this->artisan('komo:sync-taxonomy --dry-run')
            ->expectsOutputToContain('Simulación')
            ->expectsOutputToContain('Interesado')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->data()['dry_run'] === true);
    }
}
