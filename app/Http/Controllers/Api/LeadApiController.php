<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leads vía API pública. Consumidores:
 *
 *   GET   /api/v1/leads?ad_id=…       → meta_ads: ROAS por anuncio.
 *   POST  /api/v1/leads               → meta_ads: crear lead de Lead Ad.
 *   PATCH /api/v1/leads/{id}/revenue  → invoice: actualiza revenue REAL
 *                                        (invoiced_cents/collected_cents)
 *                                        cuando factura/cobra.
 */
class LeadApiController extends Controller
{
    private function accountId(Request $request): string
    {
        return $request->attributes->get('account_id');
    }

    public function index(Request $request): JsonResponse
    {
        $leads = Lead::forAccount($this->accountId($request))
            ->when($request->query('ad_id'), fn ($q, $adId) => $q->where('source_ref', $adId))
            ->when($request->query('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($leads->items())->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->title,
                'status' => $lead->status,
                'value_cents' => (int) round(((float) $lead->value) * 100),
                'invoiced_cents' => (int) $lead->invoiced_cents,
                'collected_cents' => (int) $lead->collected_cents,
                'source' => $lead->source,
                'source_ref' => $lead->source_ref,
                'utm_source' => $lead->utm_source,
                'utm_campaign' => $lead->utm_campaign,
                'created_at' => $lead->created_at?->toIso8601String(),
                'closed_at' => $lead->closed_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'total' => $leads->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'source' => 'nullable|string|max:20',
            'source_ref' => 'nullable|string|max:64',
            'source_url' => 'nullable|string|max:2048',
            'pipeline_id' => 'nullable|uuid',
            'stage_id' => 'nullable|uuid',
            'custom_fields' => 'nullable|array',
            'meta_leadgen_id' => 'nullable|string|max:64',
            'tracking' => 'nullable|array',
            'tracking.utm_source' => 'nullable|string|max:120',
            'tracking.utm_medium' => 'nullable|string|max:120',
            'tracking.utm_campaign' => 'nullable|string|max:191',
            'tracking.utm_content' => 'nullable|string|max:191',
            'tracking.utm_term' => 'nullable|string|max:191',
            'tracking.gclid' => 'nullable|string|max:191',
            'tracking.fbclid' => 'nullable|string|max:191',
            'tracking.ttclid' => 'nullable|string|max:191',
            'tracking.msclkid' => 'nullable|string|max:191',
            'tracking.landing_url' => 'nullable|string|max:2048',
            'tracking.referrer_url' => 'nullable|string|max:2048',
        ]);

        $tracking = $validated['tracking'] ?? [];

        // Idempotencia: un Lead Ad reenviado no duplica el lead. Aun así
        // aprovechamos para intentar completar el tracking si algún campo
        // aún está vacío (first-touch, no pisa nada existente).
        if ($leadgenId = $validated['meta_leadgen_id'] ?? null) {
            $existing = Lead::forAccount($accountId)->where('meta_leadgen_id', $leadgenId)->first();

            if ($existing) {
                if ($tracking !== []) {
                    $existing->applyTracking($tracking);
                }

                return response()->json(['data' => $existing->only(['id', 'title', 'status']), 'duplicated' => true]);
            }
        }

        // Pipeline/etapa: la elegida si es válida, sino la primera
        // etapa open del pipeline por defecto.
        $pipeline = null;
        if ($validated['pipeline_id'] ?? null) {
            $pipeline = Pipeline::forAccount($accountId)->find($validated['pipeline_id']);
        }
        $pipeline ??= Pipeline::forAccount($accountId)->where('is_default', true)->first()
            ?? Pipeline::forAccount($accountId)->first();

        if (! $pipeline) {
            return response()->json(['message' => 'La cuenta no tiene ningún pipeline configurado.'], 422);
        }

        $stage = null;
        if ($validated['stage_id'] ?? null) {
            $stage = PipelineStage::where('pipeline_id', $pipeline->id)->find($validated['stage_id']);
        }
        $stage ??= $pipeline->stages()->where('stage_type', 'open')->orderBy('position')->first();

        if (! $stage) {
            return response()->json(['message' => 'El pipeline no tiene etapas abiertas.'], 422);
        }

        // Contacto: dedup por teléfono normalizado (la clave de
        // correlación de todo el ecosistema).
        $contact = null;
        if ($validated['phone'] ?? null) {
            $normalized = Contact::normalizePhone($validated['phone']);

            $contact = Contact::forAccount($accountId)->where('phone_normalized', $normalized)->first()
                ?? Contact::create([
                    'account_id' => $accountId,
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'] ?? null,
                ]);
        }

        $source = $validated['source'] ?? 'api';

        $lead = Lead::create([
            'account_id' => $accountId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'contact_id' => $contact?->id,
            'title' => ($source === 'lead_ad' ? 'Lead Ad: ' : 'API: ').$validated['name'],
            'source' => $source,
            'source_ref' => $validated['source_ref'] ?? null,
            'source_url' => $validated['source_url'] ?? null,
            'meta_leadgen_id' => $validated['meta_leadgen_id'] ?? null,
        ]);

        if ($tracking !== []) {
            $lead->applyTracking($tracking);
        }

        $lead->recordEvent('created', null, array_filter([
            'source' => $source,
            'ad_id' => $validated['source_ref'] ?? null,
            'utm_source' => $lead->utm_source,
            'utm_campaign' => $lead->utm_campaign,
        ]));

        // Los campos extra del formulario quedan visibles como nota.
        if (! empty($validated['custom_fields'])) {
            $extra = collect($validated['custom_fields'])
                ->map(fn ($value, $field) => "{$field}: {$value}")
                ->implode("\n");

            $lead->notes()->create([
                'account_id' => $accountId,
                'text' => "Datos del formulario:\n".$extra,
            ]);
        }

        // Aviso al owner: entró un lead nuevo por anuncio (mismo patrón
        // que los leads de WhatsApp y de formularios web).
        AppNotification::notify(
            $accountId,
            $lead->account->owner_user_id,
            'lead_created_api',
            $source === 'lead_ad' ? 'Nuevo lead de Meta Ads' : 'Nuevo lead por API',
            "{$validated['name']} entró como lead",
            $lead->id,
        );

        return response()->json(['data' => $lead->only(['id', 'title', 'status', 'source', 'source_ref'])], 201);
    }

    /**
     * PATCH /api/v1/leads/{id}/tracking — asocia UTMs / click IDs a un
     * lead existente. First-touch: solo rellena campos vacíos, no pisa
     * atribución previa. Útil cuando la conversión ocurre en un flujo
     * asíncrono (el JS captura UTMs, luego un backend externo crea el
     * lead sin ellos y los completa después).
     */
    public function updateTracking(Request $request, string $id): JsonResponse
    {
        $lead = Lead::forAccount($this->accountId($request))->findOrFail($id);

        $validated = $request->validate([
            'utm_source' => 'nullable|string|max:120',
            'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:191',
            'utm_content' => 'nullable|string|max:191',
            'utm_term' => 'nullable|string|max:191',
            'gclid' => 'nullable|string|max:191',
            'fbclid' => 'nullable|string|max:191',
            'ttclid' => 'nullable|string|max:191',
            'msclkid' => 'nullable|string|max:191',
            'landing_url' => 'nullable|string|max:2048',
            'referrer_url' => 'nullable|string|max:2048',
        ]);

        $changed = $lead->applyTracking($validated);

        return response()->json([
            'data' => $lead->only([
                'id', 'first_touch_at',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'gclid', 'fbclid', 'ttclid', 'msclkid',
                'landing_url', 'referrer_url',
            ]),
            'changed' => $changed,
        ]);
    }

    /**
     * PATCH /api/v1/leads/{id}/revenue — Invoice actualiza el revenue REAL
     * del lead cuando emite factura y registra pagos. Los valores son
     * absolutos (no delta) para tolerar reenvíos y borrado de pagos.
     */
    public function updateRevenue(Request $request, string $id): JsonResponse
    {
        $lead = Lead::forAccount($this->accountId($request))->findOrFail($id);

        $validated = $request->validate([
            'invoiced_cents' => 'required|integer|min:0',
            'collected_cents' => 'required|integer|min:0',
        ]);

        $lead->update($validated);

        // Aviso al owner cuando cambia el estado de cobro (útil sin polling).
        if ($lead->wasChanged('collected_cents') && $lead->collected_cents >= $lead->invoiced_cents && $lead->invoiced_cents > 0) {
            AppNotification::notify(
                $lead->account_id,
                $lead->account->owner_user_id,
                'lead_fully_paid',
                "Lead cobrado por completo: {$lead->title}",
                'Total: '.number_format($lead->collected_cents / 100, 2).' '.($lead->currency ?? 'USD'),
                $lead->id,
            );
        }

        return response()->json(['data' => [
            'id' => $lead->id,
            'invoiced_cents' => (int) $lead->invoiced_cents,
            'collected_cents' => (int) $lead->collected_cents,
        ]]);
    }
}
