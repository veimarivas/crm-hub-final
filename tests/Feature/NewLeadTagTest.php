<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etiqueta «Nuevo» automática.
 *
 * Existe para poder difundir a los que acaban de llegar. Etiquetar a mano uno
 * por uno no se hacía nunca, así que el segmento «los nuevos» no existía y no
 * había forma de escribirles a todos.
 */
class NewLeadTagTest extends TestCase
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

    private function crearLead(string $titulo = 'Lead'): Lead
    {
        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'title' => $titulo,
            'source' => 'whatsapp',
        ]);
    }

    public function test_un_lead_nuevo_nace_etiquetado(): void
    {
        $lead = $this->crearLead();

        $this->assertSame([Tag::NEW_LEAD], $lead->tags()->pluck('name')->all());
    }

    public function test_la_etiqueta_se_crea_una_sola_vez_para_la_cuenta(): void
    {
        $this->crearLead('Uno');
        $this->crearLead('Dos');

        $this->assertSame(1, Tag::forAccount($this->account->id)->where('name', Tag::NEW_LEAD)->count());
    }

    public function test_reutiliza_la_etiqueta_que_ya_existia_aunque_este_en_minusculas(): void
    {
        $existente = Tag::create(['account_id' => $this->account->id, 'name' => 'nuevo', 'color' => '#111111']);

        $lead = $this->crearLead();

        $this->assertSame([$existente->id], $lead->tags()->pluck('tags.id')->all());
        $this->assertSame(1, Tag::forAccount($this->account->id)->count());
    }

    public function test_cada_cuenta_tiene_la_suya(): void
    {
        $this->crearLead();

        $otroOwner = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroOwner->id]);
        $otroPipeline = Pipeline::create(['account_id' => $otraCuenta->id, 'name' => 'P', 'is_default' => true]);
        $otraEtapa = PipelineStage::create(['pipeline_id' => $otroPipeline->id, 'name' => 'N', 'stage_type' => 'open', 'position' => 0]);

        Lead::create([
            'account_id' => $otraCuenta->id,
            'pipeline_id' => $otroPipeline->id,
            'stage_id' => $otraEtapa->id,
            'title' => 'Ajeno',
            'source' => 'whatsapp',
        ]);

        $this->assertSame(1, Tag::forAccount($this->account->id)->count());
        $this->assertSame(1, Tag::forAccount($otraCuenta->id)->count());
    }

    public function test_la_pantalla_de_etiquetas_lista_con_su_uso(): void
    {
        $this->crearLead();

        $tags = $this->actingAs($this->owner)
            ->get(route('tags.index'))
            ->assertOk()
            ->viewData('page')['props']['tags'];

        $this->assertSame(Tag::NEW_LEAD, $tags[0]['name']);
        $this->assertSame(1, $tags[0]['leads_count']);
    }

    public function test_no_se_duplica_una_etiqueta_con_el_mismo_nombre(): void
    {
        $this->crearLead();

        $this->actingAs($this->owner)
            ->post(route('tags.store'), ['name' => 'NUEVO'])
            ->assertRedirect();

        $this->assertSame(1, Tag::forAccount($this->account->id)->count());
    }
}
