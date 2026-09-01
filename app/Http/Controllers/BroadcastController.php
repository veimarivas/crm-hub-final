<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\SavedSegment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\SegmentQuery;
use App\Services\Wacrm\Client;
use App\Services\Wacrm\WacrmApiException;
use App\Services\WhatsApp\MessagingCost;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);

        $broadcasts = Broadcast::forAccount($accountId)
            // El agente ve el historial de SUS envios. Los del equipo no le
            // aportan y muestran a quien le escribio otro.
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get(['id', 'name', 'message', 'status', 'total_recipients', 'sent_count', 'failed_count', 'sent_at', 'created_at', 'user_id']);

        return Inertia::render('Broadcasts/Index', [
            'broadcasts' => $broadcasts,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function create(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);

        $segments = SavedSegment::forAccount($accountId)
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))
            ->orderBy('name')
            ->get(['id', 'name', 'filters']);

        return Inertia::render('Broadcasts/Create', [
            'segments' => $segments,
            // Filtrar por etiqueta es la forma natural de armar un envío
            // ("los Nuevos", "los del MBA"), asi que las etiquetas viajan con
            // la pantalla en vez de esconderse detras de una lista guardada.
            //
            // El contador de leads por etiqueta respeta el alcance de quien
            // mira: si el agente ve "Nuevo (120)" y al elegirla le aparecen 9,
            // el numero mintio.
            'tags' => Tag::forAccount($accountId)
                ->withCount(['leads' => fn ($q) => $isAdmin ? $q : $q->where('responsible_user_id', $request->user()->id)])
                ->orderBy('name')
                ->get(['id', 'name', 'color']),
            // Elegir responsable es del admin; el agente solo se tiene a si
            // mismo, asi que el desplegable no tendria nada que filtrar.
            'members' => $isAdmin
                ? User::where('account_id', $accountId)->orderBy('name')->get(['id', 'name'])
                : [],
            'pricing' => app(MessagingCost::class)->rates(),
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Lista de candidatos con su ventana de servicio.
     *
     * Antes esto devolvia solo un numero y tres nombres de muestra: se enviaba
     * a ciegas. Ahora vuelve la lista entera para que se vea a quien se le va
     * a escribir, y sobre todo a quien NO conviene.
     */
    public function preview(Request $request)
    {
        $filters = $request->input('filters', []);

        // Filtros inválidos son un 422 con el motivo, no un 500: esto lo llama
        // el front en cada tecleo de la pantalla de armado.
        try {
            $recipients = $this->recipientPhones($request, $filters);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $inWindow = array_values(array_filter($recipients, fn ($r) => $r->window['is_open']));
        $outOfWindow = array_values(array_filter($recipients, fn ($r) => ! $r->window['is_open']));

        return response()->json([
            'count' => count($recipients),
            'in_window' => count($inWindow),
            'out_of_window' => count($outOfWindow),
            // Tope defensivo: un envio de 5.000 no tiene por que reventar el
            // navegador. Se avisa en la UI con `truncated`.
            'recipients' => array_map(fn ($r) => [
                'lead_id' => $r->lead_id,
                'contact_id' => $r->contact_id,
                'name' => $r->name,
                'phone' => $r->phone,
                'title' => $r->title,
                'tags' => $r->tags,
                'window' => $r->window,
            ], array_slice($recipients, 0, 500)),
            'truncated' => count($recipients) > 500,
            // Lo que costaria escribirle a los de afuera CON plantilla
            // aprobada, que es la unica forma de que les llegue.
            'cost_out_of_window' => app(MessagingCost::class)->estimate(count($outOfWindow)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'message' => 'required|string|max:4000',
            'filters' => 'nullable|array',
            'lead_ids' => 'nullable|array|max:5000',
            'lead_ids.*' => 'uuid',
            'image' => 'nullable|file|image|mimes:jpeg,png,webp,gif|max:10240',
        ]);

        $accountId = $request->user()->account_id;

        try {
            $recipients = $this->recipientPhones($request, $validated['filters'] ?? []);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['filters' => $e->getMessage()]);
        }

        // La seleccion de la pantalla manda: los filtros arman la lista, pero
        // quien recibe lo decide la persona destildando a mano. Se intersecta
        // contra la lista recien calculada, asi que un id de otra cuenta o de
        // un lead que dejo de matchear no entra igual.
        if (! empty($validated['lead_ids'])) {
            $elegidos = array_flip($validated['lead_ids']);
            $recipients = array_values(array_filter($recipients, fn ($r) => isset($elegidos[$r->lead_id])));
        }

        // Como excepción de validación y no `abort(422)`: un abort pelado no
        // es una respuesta de validación, así que Inertia lo descarta y el
        // botón parece no hacer nada. Así el motivo aterriza en la pantalla.
        if (empty($recipients)) {
            throw ValidationException::withMessages([
                'lead_ids' => 'Ningún destinatario válido: revisá que los seleccionados sigan teniendo teléfono y coincidan con los filtros.',
            ]);
        }

        // El envío lo hace el wacrm, que es el único que habla con Meta: sabe de
        // plantillas, de la ventana de 24 h, del rate limit y de las métricas.
        // Komo resuelve a QUIÉN (con `SegmentQuery`, que allá no existe) y
        // delega el CÓMO. Hasta D1b había acá un motor paralelo que mandaba
        // texto suelto sin mirar la ventana: fuera de las 24 h, Meta lo
        // rechazaba y el envío no aparecía en ninguna métrica.
        $integration = Integration::forAccount($accountId)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            throw ValidationException::withMessages([
                'name' => 'La integración con WhatsApp no está configurada: sin ella no se puede enviar. Revisá /settings/integration.',
            ]);
        }

        // Guarda la imagen del broadcast. Queda también acá porque la pantalla
        // de detalle la muestra desde este dominio; la copia que se envía viaja
        // en el alta y el wacrm la sube a Meta una sola vez para todos.
        $mediaPath = null;
        if ($request->hasFile('image')) {
            $mediaPath = $request->file('image')->store('broadcasts');
        }

        try {
            $remote = Client::for($integration)->createBroadcast(array_filter([
                'name' => $validated['name'],
                'body_type' => 'text',
                'body_text' => $validated['message'],
                'media_base64' => $mediaPath
                    ? base64_encode(Storage::disk('local')->get($mediaPath))
                    : null,
                'media_mime' => $mediaPath ? Storage::disk('local')->mimeType($mediaPath) : null,
                'audience' => 'phones',
                // El `external_ref` es el lead: es lo que permite marcar de
                // vuelta la fila exacta que quedó afuera, sin adivinar por
                // teléfono.
                'recipients' => array_map(fn ($r) => [
                    'phone' => $r->phone,
                    'external_ref' => $r->lead_id,
                ], $recipients),
            ]));
        } catch (WacrmApiException $e) {
            // El motivo del otro lado aterriza en la pantalla («ninguno tiene la
            // ventana abierta», «WhatsApp no está conectado»). Un fallo mudo acá
            // sería un botón que no hace nada.
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        $report = $remote['report'] ?? [];

        // Motivo por lead de los que quedaron afuera, para marcar cada fila.
        $excluded = collect($report['excluded'] ?? [])
            ->filter(fn ($e) => ! empty($e['external_ref']))
            ->keyBy('external_ref');

        DB::transaction(function () use ($request, $validated, $accountId, $recipients, &$broadcast, $mediaPath, $remote, $report, $excluded) {
            $broadcast = Broadcast::create([
                'account_id' => $accountId,
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'message' => $validated['message'],
                'media_path' => $mediaPath,
                'filters' => $validated['filters'] ?? [],
                'status' => 'running',
                'wacrm_broadcast_id' => $remote['id'] ?? null,
                'report' => $report,
                // Los que realmente salen, no los pedidos: si el total dijera
                // 300 y solo salen 40, la barra de progreso mentiría para
                // siempre.
                'total_recipients' => $report['sending_to'] ?? count($recipients),
                'sent_at' => now(),
            ]);

            // La audiencia COMPLETA se congela igual, incluidos los descartados:
            // «a quién se le quiso escribir y por qué no se pudo» es parte del
            // hecho histórico, y es la única forma de saber a quién hay que
            // alcanzar con una plantilla.
            $now = now();
            $rows = array_map(function ($r) use ($broadcast, $now, $excluded) {
                $out = $excluded->get($r->lead_id);

                return [
                    'id' => (string) Str::uuid(),
                    'broadcast_id' => $broadcast->id,
                    'lead_id' => $r->lead_id,
                    'contact_id' => $r->contact_id,
                    'phone_normalized' => $r->phone,
                    'status' => $out ? 'skipped' : 'pending',
                    'error' => $out ? self::EXCLUSION_REASONS[$out['reason']] ?? $out['reason'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $recipients);

            foreach (array_chunk($rows, 500) as $chunk) {
                BroadcastRecipient::insert($chunk);
            }
        });

        $mensaje = ($report['out_of_window'] ?? 0) > 0
            ? "Broadcast en curso — sale a {$report['sending_to']} de {$report['requested']}: el resto tiene la ventana de 24 h cerrada."
            : 'Broadcast en curso — se procesa en segundo plano.';

        return redirect()->route('broadcasts.show', $broadcast)->with('success', $mensaje);
    }

    /** Motivos que devuelve el wacrm, en el idioma de la pantalla. */
    private const EXCLUSION_REASONS = [
        'ventana_cerrada' => 'Ventana de 24 h cerrada — hace falta una plantilla aprobada.',
        'sin_conversacion' => 'Nunca escribió por WhatsApp: no hay ventana abierta.',
    ];

    public function show(Request $request, Broadcast $broadcast): Response
    {
        $this->authorizeBroadcast($request, $broadcast);
        $broadcast->refresh();

        $remoteError = $broadcast->isDelegated() ? $this->syncFromWacrm($broadcast) : null;

        return Inertia::render('Broadcasts/Show', [
            'broadcast' => $broadcast->load('user:id,name'),
            'recipients' => $broadcast->recipients()
                ->with(['lead:id,title', 'contact:id,name'])
                ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'skipped' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
                ->limit(200)
                ->get(),
            // Motivos de fallo agrupados del lado del wacrm: sin esto «12
            // fallaron» no dice si fue la ventana, un teléfono inválido o que
            // Meta cortó el envío entero.
            'failureReasons' => $broadcast->failure_reasons ?? [],
            'remoteError' => $remoteError,
        ]);
    }

    /**
     * Trae los contadores reales del wacrm, que es quien envía.
     *
     * Los contadores locales se actualizan como caché para que el listado (que
     * no consulta al wacrm) muestre algo razonable. La pantalla se refresca
     * sola cada 4 s mientras el envío está en curso, así que esta llamada
     * ocurre seguido: si el wacrm no responde, la pantalla NO se rompe — sigue
     * mostrando lo último que se supo y lo dice.
     *
     * @return string|null  motivo, si no se pudo consultar
     */
    private function syncFromWacrm(Broadcast $broadcast): ?string
    {
        $integration = Integration::forAccount($broadcast->account_id)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return 'La integración con WhatsApp no está configurada; los números pueden estar desactualizados.';
        }

        try {
            $remote = Client::for($integration)->broadcast($broadcast->wacrm_broadcast_id);
        } catch (\Throwable $e) {
            return 'No se pudo consultar el estado del envío: '.$e->getMessage();
        }

        $broadcast->forceFill([
            'sent_count' => $remote['sent_count'] ?? $broadcast->sent_count,
            'failed_count' => $remote['failed_count'] ?? $broadcast->failed_count,
            // `sending|scheduled` allá es «en curso» acá; `sent` es completado.
            'status' => match ($remote['status'] ?? null) {
                'sent' => 'completed',
                'failed' => 'failed',
                default => 'running',
            },
            'completed_at' => in_array($remote['status'] ?? null, ['sent', 'failed'], true)
                ? ($broadcast->completed_at ?? now())
                : null,
        ])->save();

        // No es columna: viaja solo a la vista.
        $broadcast->failure_reasons = $remote['failure_reasons'] ?? [];

        return null;
    }

    /** Sirve la imagen adjunta al broadcast (autorizada por pertenencia a la cuenta). */
    public function media(Request $request, Broadcast $broadcast)
    {
        $this->authorizeBroadcast($request, $broadcast);
        abort_if(! $broadcast->media_path || ! Storage::disk('local')->exists($broadcast->media_path), 404);

        return Storage::disk('local')->response($broadcast->media_path);
    }

    /**
     * De la cuenta, y del agente si no es admin.
     *
     * El listado ya oculta los envíos ajenos, pero ocultar no es cortar: con
     * el id a mano se abría igual, y el detalle muestra a quién le escribió
     * otro asesor.
     */
    private function authorizeBroadcast(Request $request, Broadcast $broadcast): void
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);

        abort_if(
            ! $request->user()->hasRoleAtLeast(User::ROLE_ADMIN) && $broadcast->user_id !== $request->user()->id,
            403,
        );
    }

    /**
     * Destinatarios candidatos con su ventana de servicio, segun los filtros
     * (los mismos que `LeadController@index`) — solo leads con telefono.
     *
     * @return array<int, object>
     */
    private function recipientPhones(Request $request, array $filters): array
    {
        $user = $request->user();

        // El corte del agente y la traducción de los criterios viven en
        // `SegmentQuery`, compartido con `/leads` y su CSV: una lista guardada
        // tiene que seleccionar exactamente los mismos leads acá que allá.
        // Acepta tanto el formato plano viejo como el árbol de condiciones —
        // `upgrade()` se encarga— así que las listas guardadas antes de T4
        // siguen andando sin migrar la base.
        //
        // Va acá y no en la pantalla porque este método alimenta TANTO la vista
        // previa como el envío — filtrar solo en el front dejaría el `store`
        // abierto a mandar un lead_id ajeno.
        $query = Lead::forAccount($user->account_id)
            ->whereHas('contact', fn ($q) => $q->whereNotNull('phone_normalized'))
            ->with(['contact:id,name,phone_normalized', 'tags:id,name,color']);

        // `openOnly`: escribirle a un lead ya cerrado es un error caro, así que
        // los cerrados quedan afuera salvo que la definición lo pida explícito.
        $leads = SegmentQuery::for($user)
            ->apply($query, $filters, openOnly: true)
            ->get(['id', 'contact_id', 'responsible_user_id', 'title', 'source_ref', 'created_at']);

        // Ventana de servicio de todos de una: dice a quien se le puede
        // escribir gratis y a quien no. Dos queries para la lista entera.
        $windows = app(ServiceWindow::class)->forLeads($leads);

        // Dedup por phone_normalized (mismo contacto en 2 leads = un solo msg)
        $seen = [];
        $out = [];
        foreach ($leads as $lead) {
            $phone = $lead->contact?->phone_normalized;
            if (! $phone || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $out[] = (object) [
                'lead_id' => $lead->id,
                'contact_id' => $lead->contact_id,
                'name' => $lead->contact->name,
                'phone' => $phone,
                'title' => $lead->title,
                'tags' => $lead->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
                'window' => $windows[$lead->id] ?? app(ServiceWindow::class)->build(null, null),
            ];
        }

        // Los que estan por vencer primero: si hay que apurar un envio, es a
        // ellos. Los que ya estan fuera de ventana, al final.
        usort($out, function ($a, $b) {
            if ($a->window['is_open'] !== $b->window['is_open']) {
                return $a->window['is_open'] ? -1 : 1;
            }

            return $a->window['remaining_seconds'] <=> $b->window['remaining_seconds'];
        });

        return $out;
    }
}
