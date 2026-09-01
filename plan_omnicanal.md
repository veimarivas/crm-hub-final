# PLAN — CRM Omnicanal de Marketing (wacrm + Komo)

Documento de trabajo para el agente. Se ejecuta por fases (F0→F7). Cada fase es un commit independiente que deja la suite en verde y actualiza los `CLAUDE_*.md` de ambos proyectos con el resultado (convención existente: documentar rondas, trampas y tests).

**Regla de oro:** una fase no se mezcla con la siguiente. Si aparece trabajo de otra fase, se anota acá y se descarta del commit.

> **Revisión 2026-08-12 — contrastado contra el código.** Se verificaron los supuestos del plan original leyendo `InboundProcessor`, `EventProcessor`, `ServiceWindow` (ambos repos), `LeadScorer`, `Wacrm\Client`, `Dispatcher` y las migraciones. Los cambios respecto de la versión anterior están marcados con **[REV]** y resumidos en el §11.

---

## 0. Contexto

Dos proyectos Laravel 13 + Inertia/React 18 + MariaDB 10.11 (XAMPP local, VPS Ubuntu en producción), integrados por API + webhooks HMAC:

| Proyecto                           | Rol                                                                     | Producción                                |
| ---------------------------------- | ----------------------------------------------------------------------- | ----------------------------------------- |
| **wacrm** (`laravel_crm_whatsapp`) | Motor de mensajería (WhatsApp), flows, automatizaciones, IA, broadcasts | `crm-whatsapp.posgradosinnovaciencia.com` |
| **Komo** (`laravel_komo_crm`)      | Leads, pipeline, tareas, segmentos, workflows, supervisión, reportes    | `komo.posgradosinnovaciencia.com`         |
| meta_ads                           | Atribución publicitaria Meta (3ª app)                                   | —                                         |
| hub                                | SSO + provisión (4ª app)                                                | —                                         |

**Objetivo:** que el wacrm deje de ser "CRM de WhatsApp" y pase a ser motor **omnicanal** (Telegram, Messenger, Instagram, email, SMS, web chat), y que el Komo capture leads desde TikTok/LinkedIn/Google Ads y gestione publicación en redes — un CRM de marketing completo.

## 1. Convenciones que NO se negocian (ya fijadas en el código)

- Multi-tenant por `account_id` en TODA tabla nueva (trait `BelongsToAccount`). Toda query pasa por el scope; controladores validan pertenencia (`abort_if(... 403)`).
- UUIDs (`HasUuids`), Laravel 13 con atributos `#[Fillable]`/`#[Hidden]`.
- Secretos (tokens, API keys) con cast `encrypted`.
- Idempotencia por ID externo en todo lo que entra por webhook/API.
- Contratos de webhook son **aditivos**: solo se agregan campos; receptores viejos deben seguir funcionando (patrón `sender_name`, `media_id`, `referral`).
- Cola FIFO: **webhooks a Komo primero, flows/automations después, IA al final** (fix histórico del delay de 60 s).
- Gemelos: `Services\WhatsApp\ServiceWindow` y `Services\Supervision\ResponseMetrics` existen en AMBOS proyectos con definiciones idénticas. Si cambia una definición → tocar los dos + sus dos tests.
- MariaDB, no MySQL 8: sin `LATERAL JOIN`, sin índices parciales. `UNIQUE` con NULLs permite múltiples NULL (aprovecharlo).
- Los cortes de rol van en el servidor, nunca solo en la UI.
- Un comando que envía WhatsApp saliente **no se agenda por defecto** (Meta cobra fuera de ventana; lección de `komo:remind-daily-tasks`).
- BDs externas (`esam_datos`, APIs de redes) no pueden tumbar secciones: `catch (\Throwable)` + degradación visible en pantalla.
- Tests en `laravel_crm_whatsapp_test` / `laravel_komo_crm_test` (MySQL, no sqlite).
- Deploy: `git pull && npm ci && npm run build && php artisan migrate --force && optimize:clear` en ambos + `systemctl restart crm-*-queue.service` si tocó jobs. `/public/build` va en el servidor.

## 2. Decisiones de diseño (antes de escribir código)

1. **El motor es canal-agnóstico.** Flows, automatizaciones, IA, supervisión y Komo trabajan sobre `Conversation`/`Message`/`lead_events`; el canal vive solo en los bordes (adapters).
2. **`channel` como dato, no como fork.** Columna `channel` en `conversations` y `messages` (wacrm) y `payload.channel` en `lead_events` (Komo). Valores: `whatsapp|telegram|messenger|instagram|email|sms|webchat`.
3. **Un contacto es una persona, no un teléfono.** Tabla `contact_identities` (canal + external_id) permite que el mismo contacto exista en varios canales con historial unificado. **[REV]** Esto no es un extra: hoy el identificador de contacto ES el teléfono en los dos proyectos, y sin identidades ningún canal sin teléfono puede existir (ver §3).
4. **Ventana de servicio solo donde hay costo.** `hasServiceWindow()` es del adapter: WhatsApp/Messenger/Instagram = true (reglas Meta), resto = siempre abierta. Cambia el gemelo `ServiceWindow` en ambos proyectos (misma regla, dos tests).
5. **Configuración por cuenta.** `channel_configs` (credenciales cifradas + settings) — cada cuenta conecta sus propios bots/canales. **[REV]** Además es el reemplazo de `WhatsappConfig` como resolvedor de cuenta en la entrada: hoy la cuenta se deduce del `phone_number_id` de Meta, cosa que no existe en otros canales.
6. **TikTok y LinkedIn son captura, no mensajería.** Sus APIs de DM son cerradas/partner-only. Entran como `source` de leads en Komo; se contacta por un canal de mensajería.
7. **[REV] Direccionamiento por conversación, no por teléfono.** Todo lo que hoy manda mensajes cruzando el puente (Komo → wacrm) usa el teléfono como dirección. Omnicanal exige direccionar por `conversation_id` (o `channel`+`external_id`). Es el segundo cambio de contrato del plan y va en F0.

---

## 3. **[REV]** Hallazgos del código que cambian el plan

Todo esto se verificó leyendo el código actual, no la documentación.

### 🔴 Bloqueante 1 — Komo descarta cualquier evento sin teléfono

`Services\Wacrm\EventProcessor::syncContact()` (Komo) arranca con:

```php
$normalized = Contact::normalizePhone($remote['phone'] ?? null);
if (! $normalized) { return null; }   // ← y handleInboundMessage hace return
```

Un mensaje de Telegram/Messenger/webchat llega **sin teléfono**, así que hoy Komo lo tira en silencio: no crea contacto, no crea lead, no registra `lead_event`. El E2E que el plan pone como DoD de F1 ("mensaje Telegram → lead Komo") **falla en esta línea**, no en el adapter. Arreglarlo es parte de F0, no de F1.

Arreglo: `syncContact()` resuelve por **identidad** (`channel` + `external_id` del payload) y usa el teléfono solo cuando el canal lo trae. Contacto sin teléfono es válido (la columna ya es `nullable` en Komo).

### 🔴 Bloqueante 2 — el puente Komo→wacrm direcciona por teléfono

`Services\Wacrm\Client` (Komo) expone `sendMessage(string $phone, string $text)` y `sendMedia(string $phone, …)`, contra `POST /api/v1/messages` del wacrm, que también recibe teléfono. Un lead de Telegram no tiene a qué responderle desde el chat del lead en Komo.

Arreglo en F0 (aditivo, sin romper): el endpoint del wacrm acepta **o** `phone` (legado) **o** `conversation_id`; `Client` estrena `sendToConversation(string $conversationId, …)`. El chat del lead en Komo pasa a guardar y usar el `conversation_id` que ya viene en el payload de `message.received`.

### 🟠 Trampa 3 — `contacts.phone` es NOT NULL en wacrm

`2026_07_07_000002_create_contacts_tables.php`: `$table->string('phone', 32);` sin `nullable()`. Un contacto de Telegram no tiene teléfono → `Contact::create` revienta con error de SQL.

Arreglo: migración que hace `phone` nullable en wacrm. El `unique(['account_id','phone_normalized'])` ya tolera múltiples NULL en MariaDB (convención §1), así que no hay que inventar teléfonos sintéticos — y no hay que inventarlos: un teléfono falso rompería el merge de duplicados y los broadcasts.

### 🟠 Trampa 4 — la conversación se resuelve por (cuenta, contacto), sin canal

`InboundProcessor` (wacrm, línea ~93):

```php
Conversation::firstOrCreate(['account_id' => …, 'contact_id' => $contact->id], …)
```

Si el mismo contacto escribe por WhatsApp y por Telegram, **los dos hilos caen en la misma conversación** y el `channel` de la conversación queda mintiendo. La clave pasa a ser `(account_id, contact_id, channel)`. La migración de F0 debe además rellenar `channel='whatsapp'` en las existentes ANTES de que exista otro canal (trivial ahí, imposible después).

### 🟠 Trampa 5 — la columna no se llama `wamid`

El plan original proponía `external_message_id = DB::raw('wamid')`. La columna real de `messages` es **`message_id`** (y `Message::where('message_id', $contextId)` se usa para resolver respuestas). El backfill correcto es `DB::raw('message_id')`.

Ojo adicional: ese lookup de `reply_to` **no filtra por cuenta ni por canal**. Al meter varios canales, dos externos podrían colisionar; el `WHERE` tiene que incluir `channel` cuando se agregue la columna.

### 🟠 Trampa 6 — `ServiceWindow` no tiene `forConversation()`

Métodos reales:

| wacrm                                              | Komo                                                    |
| -------------------------------------------------- | ------------------------------------------------------- |
| `for(Conversation)`, `forMany(array)`, `forContacts(array)`, `build(?Carbon,?Carbon)` | `forLead(Lead)`, `forLeads(Collection)`, `forContacts(Collection)`, `build(?Carbon,?Carbon)` |

**Los cuatro métodos de cada lado terminan en `build()`.** Ahí va el corte de canal, en una sola línea por repo, y ahí apunta el test:

```php
public function build(?CarbonInterface $lastInboundAt, ?CarbonInterface $adReferralAt, string $channel = 'whatsapp'): array
{
    if (! ChannelRules::hasServiceWindow($channel)) {
        return self::alwaysOpen($channel);   // is_open=true, window_hours=null, source=$channel
    }
    …
}
```

El default `'whatsapp'` mantiene compatibles a todos los llamadores actuales. **Trampa dentro de la trampa:** el contrato de retorno de `build()` ya tiene consumidores en UI (`source`, `window_hours`, `expires_at`, `remaining_seconds`, `is_open`, `is_expiring`); la rama "siempre abierta" debe devolver **todas** las claves, no un array corto, o el badge revienta.

### 🟠 Trampa 7 — `MessagingCost` (Komo) cobraría canales gratis

`Services\WhatsApp\MessagingCost::estimate(int $messages, string $category)` calcula el costo estimado de un envío. Un broadcast de Telegram costaría USD 0 y la pantalla diría otra cosa. En F0 recibe el canal y devuelve costo cero (con nota en pantalla) para canales sin tarifa.

### 🟡 Corrección 8 — `LeadScorer` NO tiene pesos por fuente

El plan original pedía "pesos para fuentes nuevas (telegram≈whatsapp…) y un test que los fije". No existe tal tabla: `LeadScorer::sourceQuality()` usa `LeadSignals::sourceWinRates()`, o sea **la tasa de cierre histórica real de cada `source`**, y cuando no hay historia devuelve medio peso con la leyenda "Sin historia suficiente".

O sea: **las fuentes nuevas ya degradan bien solas y no hay que tocar nada.** Lo único que corresponde es un test que fije ese comportamiento (`source='telegram'` sin historia → medio peso, sin excepción ni división por cero). Fijar pesos a mano sería un retroceso: reemplazaría un dato medido por una opinión.

### 🟡 Corrección 9 — `payload.channel` existe solo del lado email

`EmailSync` (Komo) escribe `'channel' => 'email'` en el payload del evento, pero **`EventProcessor` no lee `payload.channel` en ningún lado**: los eventos que vienen del wacrm no lo traen ni lo guardan. El "ya existe por T6" del plan original vale como precedente de formato, no como implementación.

### 🟡 Corrección 10 — `InboundProcessor` no acepta un `$channel`

`process(array $payload)` recibe el **sobre de Meta** (`entry/changes/value`) y `handleInboundMessage(WhatsappConfig $config, …)` depende de `WhatsappConfig` para saber la cuenta. No se le puede "pasar `$channel='telegram'`" como decía el plan original: hay que **extraer** el núcleo.

Diseño en F0 (§T0.2b): un DTO `InboundMessage` (cuenta, canal, external_id del remitente, nombre, tipo, texto, media, id externo, referral, respuesta-a) + un `Services\Channels\Ingestor` que hace todo lo de hoy desde `firstOrCreate` para abajo. `InboundProcessor` queda como **parser de Meta** que arma el DTO; `TelegramWebhookController` arma el mismo DTO. Cero lógica duplicada de contactos, broadcasts, auto-tags, dispatch de jobs y orden FIFO.

### 🟡 Corrección 11 — `meta_leadgen_id` es `unique()` global

`2026_07_15_000001`: `$table->string('meta_leadgen_id', 64)->nullable()->unique();` — **sin `account_id`**. Dos cuentas no pueden recibir el mismo id de Lead Ads (y en Meta los ids no son globales por cuenta nuestra). En F5, al generalizar a `external_lead_id`, se corrige a `unique(['account_id','source','external_lead_id'])` y `meta_leadgen_id` se sigue escribiendo por compatibilidad con meta_ads hasta que esa app migre.

### 🟡 Nota 12 — Telegram ya se descartó una vez, por otra cosa

El 2026-07-28 se eliminó el módulo de **avisos por Telegram a los agentes** (commit `5d042cf`) porque un bot solo puede escribirle a quien lo inició, y el requisito era que el agente no hiciera nada. **Ese veredicto no aplica acá**: en F1 Telegram es canal de **clientes**, y el cliente sí inicia (escribe al bot). Lo que sí hereda del veredicto:

- **Telegram no sirve para outbound-first.** Un broadcast por Telegram solo puede alcanzar a quien ya tiene identidad (`contact_identities`), y la UI del creador de broadcasts debe decir el tamaño real de la audiencia alcanzable por canal, no el total de contactos.
- No proponer Telegram para avisos internos.

---

## F0 — Refactor multi-canal (wacrm + Komo) · base obligatoria

> **Avance 2026-09-01 — `ChannelRules` + corte de canal en `ServiceWindow` y `MessagingCost`: ✅ HECHO** (los dos repos, mismo día).
>
> Se hizo **primero** dentro de F0, y no como parte de T0.2, porque es la pieza que no depende
> de nada: ni del esquema, ni del ingestor, ni de los adapters. Y porque `plan_deduplicacion.md`
> D4 acababa de poner fixtures sobre `ServiceWindow::build()` — hacerlo con la red puesta en vez
> de sin ella.
>
> - `ChannelRules` nace como **gemelo** (byte-idéntico, dentro del manifiesto de
>   `SharedFilesDriftTest`), tal como pedía la §T0.2.
> - El corte va en `build()` con default `'whatsapp'`: **ningún llamador cambió**.
> - **Trampa 6 confirmada como real:** la rama «siempre abierta» devuelve todas las claves, y
>   `window_hours: null` es la señal que la UI lee para no dibujar una cuenta regresiva. Sin
>   eso el badge decía «Cerrada» sobre una conversación abierta para siempre.
> - **Trampa 7 (`MessagingCost`) resuelta**: costo cero para canales sin tarifa, con `has_cost`
>   en el retorno para que la pantalla explique por qué.
> - La distinción de la **§F2** (Messenger/Instagram tienen 24 h pero NO las 72 h del anuncio)
>   se adelantó: es una regla, no un adapter, y dejarla para después significaba escribirla
>   cuando ya hubiera código dependiendo de lo contrario. Fijada con dos casos de fixture
>   enfrentados.
>
> **Sigue pendiente de F0:** T0.1 (migraciones + `contact_identities` + `channel_configs`),
> T0.2 (adapters + `ChannelRouter`), **T0.2b (el ingestor — el cambio más riesgoso, y va con
> `InboundProcessorParityTest` ANTES de mover nada)**, T0.3, T0.4, T0.5.



Sin canales nuevos todavía. Todo lo existente sigue funcionando idéntico. **[REV] F0 se parte en dos commits** porque toca los dos repos y el orden de deploy importa: **F0a wacrm** (esquema + capa de canales + ingestor + endpoint por conversación) y **F0b Komo** (identidades, `payload.channel`, direccionamiento, UI). Cada uno deja su suite en verde por separado.

### T0.1 Migraciones (wacrm)

```php
// conversations
$table->string('channel', 20)->default('whatsapp')->after('status');
$table->string('channel_conversation_id')->nullable()->after('channel');
$table->index(['account_id', 'channel', 'status']);
$table->unique(['account_id', 'contact_id', 'channel']);  // [REV] trampa 4

// messages
$table->string('channel', 20)->default('whatsapp')->after('conversation_id');
$table->string('external_message_id')->nullable()->after('channel');
$table->unique(['channel', 'external_message_id']); // NULLs OK en MariaDB

// contacts  [REV] trampa 3
DB::statement('ALTER TABLE contacts MODIFY phone VARCHAR(32) NULL');

// backfill en el mismo archivo, ANTES de crear los índices:
// messages: channel='whatsapp', external_message_id = message_id   [REV] no `wamid`
// conversations: channel='whatsapp', channel_conversation_id = contact.phone_normalized
```

**[REV]** `channel_conversation_id` = **el id del hilo en el sistema del canal** (chat_id de Telegram, PSID de Meta, thread_id de Gmail). Rellenarlo con el uuid propio como decía el plan original lo vuelve inútil justo para lo que sirve: encontrar la conversación cuando llega un webhook. Para WhatsApp el valor correcto es el teléfono normalizado.

```php
Schema::create('contact_identities', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('account_id')->constrained();
    $table->foreignUuid('contact_id')->constrained();
    $table->string('channel', 20);
    $table->string('external_id');
    $table->string('display_name')->nullable();
    $table->json('profile_data')->nullable();
    $table->boolean('is_primary')->default(false);
    $table->timestamps();
    $table->unique(['account_id', 'channel', 'external_id']);
});

Schema::create('channel_configs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('account_id')->constrained();
    $table->string('channel', 20);
    $table->boolean('is_enabled')->default(false);
    $table->json('credentials')->nullable();   // cast encrypted en el modelo
    $table->json('settings')->nullable();
    $table->timestamp('connected_at')->nullable();
    $table->timestamps();
    $table->unique(['account_id', 'channel']);
});
```

Backfill de identidades: `contact_identities` (channel=whatsapp, external_id=`phone_normalized`, `is_primary=true`) para todos los contactos existentes, en el mismo migration. **[REV]** Un comando aparte con `--dry-run` está bien como red de seguridad (`wacrm:backfill-identities`), pero el backfill **no puede quedar solo en el comando**: si el deploy corre migraciones y nadie corre el comando, el primer mensaje de un contacto existente le crea una identidad duplicada.

**[REV] La misma tabla `contact_identities` va en Komo** (mismo esquema, mismo backfill desde `contacts.phone_normalized`). Sin ella el bloqueante 1 no se puede arreglar.

### T0.2 Capa de canales (wacrm, `app/Services/Channels/`)

```php
interface ChannelAdapter
{
    public function channel(): string;
    public function isEnabled(Account $account): bool;
    public function hasServiceWindow(): bool;            // Meta: true; resto: false
    public function requiresApprovedTemplates(): bool;   // solo WhatsApp
    public function supportsOutboundFirst(): bool;       // [REV] Telegram: false (nota 12)
    public function sendText(Conversation $c, string $text): SendResult;
    public function sendMedia(Conversation $c, string $base64, string $mime, ?string $filename, ?string $caption): SendResult;
    public function sendInteractive(Conversation $c, array $payload): SendResult; // botones/listas
    public function sendTypingIndicator(Conversation $c): void; // best-effort
}
// SendResult: readonly {bool $success, ?string $externalMessageId, ?string $error}
```

- `ChannelRouter` (singleton): `register()`, `adapter(string $channel)`, `forConversation()`. Lanza `UnsupportedChannelException` si no existe.
- **[REV]** `ChannelRules` — clase **sin dependencias** (solo constantes + funciones estáticas: `hasServiceWindow()`, `requiresApprovedTemplates()`, `hasCost()`, `supportsOutboundFirst()`). Es lo que consumen `ServiceWindow` y `MessagingCost` en **ambos** repos. Los adapters delegan en ella. Sin esto, el gemelo de Komo tendría que conocer los adapters del wacrm, que allá no existen.
- `ChannelServiceProvider`: registra `WhatsAppAdapter` siempre; el resto condicional a `config('channels.*.enabled')`.
- `WhatsAppAdapter` **envuelve** `Messenger`/`MetaApi` existentes — no se reescribe nada de Meta.
- ⚠️ Trampa: no borrar `Messenger`; el composer del Inbox y la API pública (`/api/v1/messages/media`) lo usan. El adapter delega, no reemplaza.

### T0.2b **[REV]** Ingestor de entrada (wacrm) — lo que hace posible F1

```php
final readonly class InboundMessage {
    public function __construct(
        public string $accountId,
        public string $channel,
        public string $senderExternalId,   // phone | chat_id | PSID | thread participant
        public ?string $senderName,
        public ?string $threadExternalId,  // channel_conversation_id
        public string $contentType,
        public ?string $contentText,
        public ?string $mediaRef,
        public ?string $externalMessageId,
        public ?array  $referral = null,
        public ?string $replyToExternalId = null,
        public ?string $interactiveReplyId = null,
    ) {}
}
```

`Services\Channels\Ingestor::handle(InboundMessage $m): void` se lleva **tal cual** todo lo que hoy vive en `InboundProcessor::handleInboundMessage()` de `DB::transaction` para abajo: resolución de contacto (ahora por identidad), conversación (ahora por canal), guardado del mensaje, correlación de broadcasts, auto-tags, `createLeadDeal`, y **el orden FIFO de jobs sin tocar** (webhooks → flows/automations → transcripción → IA).

`InboundProcessor` queda reducido a: parsear el sobre de Meta, resolver la cuenta por `WhatsappConfig.phone_number_id`, armar el DTO, llamar al ingestor. Los `handleStatusUpdate`/`handleReaction` se quedan donde están (son específicos de Meta).

⚠️ **Es el cambio más riesgoso de F0** y por eso va con un test de caracterización ANTES de moverlo: un `InboundProcessorParityTest` que fije el estado resultante y el **orden exacto** de los jobs encolados con un payload de Meta real. Ese test es el que dice si el refactor cambió algo.

### T0.3 Refactor de puntos de salida (wacrm)

| Pieza                                                          | Cambio                                                                                                                |
| -------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `Messenger` / `InboxController@send`                           | Sin cambio de contrato; internamente pueden seguir en Meta (el adapter los envuelve).                                 |
| `Jobs/AiAutoReplyJob`                                          | Enviar vía `ChannelRouter::forConversation()`; el chequeo de `ServiceWindow` solo si `adapter->hasServiceWindow()`.   |
| `Services/Automations/Engine` (paso `send_message`)            | Resolver canal de la conversación del contacto y usar el adapter.                                                     |
| `Services/Flows/Runner` (`send_buttons`/`send_list`)           | Mapear a `sendInteractive` del adapter; Telegram = inline_keyboard.                                                   |
| **[REV]** `Services/Flows/Simulator` + `Automations/Simulator` | Los simuladores duplican reglas del Runner a propósito (ya documentado). Si el Runner pasa por adapter, el simulador debe **anotar el canal** y respetar los límites de ese canal (3 botones en WhatsApp vs. inline_keyboard en Telegram), o la prueba mentirá. |
| `Services/WhatsApp/ServiceWindow`                              | **[REV]** El corte va en `build()` (no en `forConversation()`, que no existe) — ver trampa 6.                          |
| **[REV]** `Services/WhatsApp/MessagingCost` (Komo)             | Recibe canal; costo 0 y leyenda propia para canales sin tarifa (trampa 7).                                            |
| `Services/Webhooks/Dispatcher`                                 | Agregar `channel` a payloads de `message.received`, `message.sent`, `contact.created`, `ai.pending_changed`. Aditivo. **[REV]** También `conversation_id` y `channel_external_id` en `contact.created`, que hoy solo manda el contacto. |
| **[REV]** `Api\ApiController@sendMessage` / `sendMediaMessage` | Aceptar `conversation_id` como alternativa a `phone` (bloqueante 2). Validación `required_without`. El path de `phone` no cambia de comportamiento. |
| Broadcasts (`Services/Broadcasts/Creator`, `SendBroadcastJob`) | Aceptar `channel`; WhatsApp exige plantilla aprobada, Telegram texto libre. **[REV]** El creador filtra la audiencia por **identidad en ese canal** y muestra "N de M contactos alcanzables por Telegram" (nota 12). |

### T0.4 Komo (espejo del cambio)

- **[REV] `Services/Wacrm/EventProcessor` — el cambio de fondo, no un `default`:**
    - `syncContact()` resuelve por `contact_identities(channel, external_id)` primero; si no hay identidad y hay teléfono, cae al camino actual (`phone_normalized`) y **crea la identidad** de whatsapp; si no hay ninguno de los dos, recién ahí descarta.
    - Contacto sin teléfono es válido: `name` cae a `display_name` de la identidad (hoy cae a `phone`, que sería null).
    - `payload.channel` se guarda en `lead_events` (default `'whatsapp'` para eventos viejos), y `conversation_id` también — es lo que habilita responder desde el chat del lead.
    - Test que fija que un evento de un canal desconocido **no rompe**: se guarda con su channel crudo y la UI lo muestra genérico. Los canales llegan del otro repo y el deploy no es simultáneo.
- `Services/WhatsApp/ServiceWindow` (gemelo): corte en `build()` vía `ChannelRules`. **Mismo cambio de definición que en wacrm + `ServiceWindowTest` en los dos repos.**
- `ResponseMetrics`: **NO se toca** (el canal es dato que entra, no definición — patrón T6 email).
- **[REV]** `Services/Wacrm/Client`: `sendToConversation(string $conversationId, string $text)` + variante de media; el chat del lead usa esa cuando el evento trae `conversation_id`, y el camino por teléfono queda como respaldo para leads viejos.
- UI: `ChannelBadge.jsx` (💬✈️📸️📱🌐) en `/inbox`, `/leads`, ficha del lead. Filtro `?channel=` server-side en `/inbox` y `/leads`.
- **[REV]** `LeadScorer`: **no se toca** (corrección 8). Solo se agrega `LeadScorerNewSourceTest`: fuente sin historia → medio peso, sin excepción.

### T0.5 UI wacrm

- Badge de canal en lista y header del Inbox (mismo patrón que `ServiceWindowBadge`).
- Filtro por canal en tabs del Inbox (server-side).
- `/settings/channels`: página índice con tarjetas por canal y estado Conectado/Desconectado (lee `channel_configs`).

### Tests F0

`InboundProcessorParityTest` **(primero, antes de mover nada)**, `ChannelRouterTest`, `ChannelRulesTest` (ambos repos, mismas aserciones), `WhatsAppAdapterParityTest`, `ServiceWindowChannelTest` (ambos repos), `IngestorMultiChannelTest`, `ContactIdentityResolutionTest` (ambos repos), `ApiSendByConversationTest`, `WebhookChannelPayloadTest`. Regresión de suites completas (baselines a confirmar con `php artisan test` al arrancar la fase: el plan original citaba 410 wacrm / 382 Komo).

### Trampas F0

- ⚠️ El `unique(['channel','external_message_id'])` con backfill: si hay `message_id` duplicados históricos, dedup ANTES de crear el índice (comando de limpieza con reporte).
- ⚠️ **[REV]** El `unique(['account_id','contact_id','channel'])` en `conversations`: si algún contacto tiene hoy **dos** conversaciones (posible — el `firstOrCreate` no tenía índice que lo impidiera), la migración falla en producción y no en local. Contar duplicados primero y fusionarlos con reporte.
- ⚠️ No tocar `ai_reply_count`/`ai_paused_until` en el refactor de `AiAutoReplyJob` (cooldown fijado en tests).
- ⚠️ Desplegar wacrm primero, Komo después (payload aditivo: Komo viejo ignora `channel` sin romper). **[REV]** Pero el `conversation_id` en el payload y el `sendToConversation` del Komo son un par: hasta que F0b esté desplegado, el chat del lead sigue por teléfono. Está bien — solo hay que no borrar el camino viejo en F0a.
- ⚠️ **[REV]** `Message::where('message_id', $contextId)` (resolución de respuestas) no filtra por cuenta ni canal: agregar `channel` al WHERE cuando exista la columna (trampa 5).

---

## F1 — Telegram (mensajería completa)

### Backend wacrm

- `config/channels.php` nuevo con `enabled` por canal; el `bot_token` **no va en `config/services.php`** — **[REV]** es por cuenta y va cifrado en `channel_configs.credentials` (multi-tenant, §1). Un token en `.env` sería un solo bot para todas las cuentas.
- `app/Services/Telegram/TelegramApi.php`: `sendMessage` (parse_mode HTML), `sendPhoto/Video/Audio/Document`, `sendChatAction('typing')`, `sendInlineKeyboard`, `getFile`+descarga, `setWebhook(url, secret_token)`. HTTP facade, timeout 30 s.
- `TelegramAdapter implements ChannelAdapter`: `hasServiceWindow()=false`, `requiresApprovedTemplates()=false`, `supportsOutboundFirst()=false`.
- Ruta `POST /webhooks/telegram/{account}` (fuera de CSRF, rate limiter 600/min/IP como `whatsapp-webhook`): verifica header `X-Telegram-Bot-Api-Secret-Token`. **[REV] La cuenta va en la URL**: Telegram no manda nada que permita deducirla, y con un bot por cuenta un webhook único no puede resolverla. El secreto se compara contra el de esa cuenta con `hash_equals`.
- `TelegramWebhookController`: parsea `message`, `callback_query` (botones de flows), `edited_message`; idempotencia por `update_id`; arma `InboundMessage` (channel=telegram, senderExternalId=`from.id`, threadExternalId=`chat.id`) y llama al **`Ingestor`** (T0.2b) — no a `InboundProcessor`.
- **[REV]** Media al revés que WhatsApp: Telegram da `file_id` → `getFile` → **descarga y guarda en storage propio** (el link de Telegram caduca y contiene el token del bot: no se puede exponer en la UI). Meta se resuelve por proxy en vivo. Son dos estrategias distintas y `media_url` tiene que poder guardar las dos (hoy guarda el media_id de Meta).
- Comando `wacrm:telegram-setup-webhook {--account=}` (llama `setWebhook`); sin `--account` imprime qué falta (patrón de `wacrm:sync-team-to-komo`).
- Settings `/settings/telegram`: pegar bot token (cifrado en `channel_configs`), botón "Conectar webhook", estado.

### Mapeos

| WhatsApp                             | Telegram                                               |
| ------------------------------------ | ------------------------------------------------------ |
| botones (`send_buttons`, máx. 3)     | inline_keyboard (sin ese límite — no ampliar el flow, solo no truncar) |
| `send_list`                          | inline_keyboard paginado (máx. ~8 filas por mensaje)   |
| media por media_id Meta              | `file_id` → `getFile` → descargar y guardar en storage |
| typing = markAsRead+typing_indicator | `sendChatAction`                                       |
| ventana 24/72 h                      | N/A (gratis siempre)                                   |
| plantilla aprobada para outbound     | N/A, pero **solo a quien ya escribió** (nota 12)       |

### Komo

- Sin cambios de motor **si F0b ya está desplegado**: los eventos llegan con `payload.channel='telegram'` y contacto sin teléfono. Badge ✈️. `source='telegram'` si el lead nace ahí.

### Tests F1

`TelegramWebhookTest` (secreto, idempotencia `update_id`, `callback_query`→flow, creación de identidad, contacto sin teléfono), `TelegramAdapterTest`, `TelegramWindowTest` (nunca cierra), `TelegramMediaTest` (el link con token no llega a la UI). Suite verde + deploy de ambos.

---

## F2 — Facebook Messenger + Instagram DM

- Comparten Meta App con WhatsApp. Webhook único `/webhooks/meta` que distingue por `object`: `whatsapp` (existente) | `page` (Messenger) | `instagram`.
- `MetaMessagingAdapter` base + `MessengerAdapter`/`InstagramAdapter` (Graph `/me/messages`). PSID como `external_id` por canal (un mismo humano tiene PSID distinto por página/canal: son identidades distintas, el merge lo hace un humano vía `ContactMergeController`).
- Ventana 24 h de Meta aplica: `hasServiceWindow()=true` pero SIN la pata de 72 h free-entry (esa es solo Click-to-WhatsApp) → `ChannelRules` debe distinguir sub-canal y `build()` ignorar `$adReferralAt` fuera de WhatsApp. ⚠️ Este es el cambio de definición de gemelos más delicado del plan: fijar en los dos `ServiceWindowTest` el caso "Messenger no tiene 72 h".
- **[REV]** `ContactMergeController` (wacrm) fusiona por email o nombre normalizado y mueve conversations/tags/notes/deals. Al fusionar ahora también tiene que **mover `contact_identities`** — si no, la identidad queda apuntando a un contacto borrado y el próximo mensaje resucita un contacto fantasma. Es la trampa de F2, no de F0.
- Comentarios públicos FB/IG → evento `comment.received` (no DM): se registran como nota/evento en Komo, respuesta vía `/{comment_id}/comments`.
- Settings `/settings/messenger` y `/settings/instagram` (page_id, IG business id).

## F3 — Email como canal del motor (wacrm)

- Komo ya tiene `Services\Email\{GmailClient, GoogleOAuth, EmailSync}` (T6) con `history.list`, OAuth Workspace, `In-Reply-To`/`References`, y ya escribe `payload.channel='email'`.
- **[REV] Decisión adelantada, no diferida a la fase:** el email **se queda en Komo** y NO se replica en wacrm. Razones concretas: (a) el OAuth de Workspace ya está conectado y probado allá, con el bug del refresh token ya resuelto; (b) los buzones que importan son los de los asesores, que viven en Komo; (c) duplicar el cliente OAuth obliga a mantener dos consentimientos de Google para la misma cuenta.
  Lo que sí entra en F3 es **que el motor lo alcance**: `EmailSync` pasa a crear `Conversation`/`Message` en wacrm vía API (canal `email`) en vez de solo `lead_events`, para que IA, automatizaciones y supervisión lo vean. El adapter `EmailAdapter` del wacrm sale hacia el Komo (`POST /api/v1/email/send`), invirtiendo la dirección habitual del puente. **Esa inversión es la decisión de diseño de F3 y hay que escribirla en los dos CLAUDE.**
- Saliente desde agente = `sender_type=agent` (patrón T6: sin esto supervisión cuenta mal).
- ⚠️ Refresh token: `access_type=offline`+`prompt=consent`; en renovación NO pisar con null (test existente en Komo).
- ⚠️ **[REV]** Un hilo de email dura semanas. `wacrm:auto-close-inactive --days=7` cerraría conversaciones de email vivas: el umbral pasa a ser por canal.

## F4 — SMS + Web chat widget

- `SmsAdapter` sobre Twilio (o Vonage): webhook `/webhooks/sms`, idempotencia por `MessageSid`. Costo por SMS → sin ventana pero con tope de envíos: reutilizar `Workflows\Guardrails::MAX_OUTBOUND_PER_LEAD_PER_DAY` (=3, ya existe) y sumar el canal a `MessagingCost`.
- Widget web chat: script embebible (patrón `web_forms` de Komo: `GET/POST /f/{token}` con throttle propio), backend WebSocket por Reverb en wacrm; `channel='webchat'`; visitante anónimo → identidad por cookie hasta que deje teléfono/email.
- ⚠️ **[REV]** Reverb **no corre en el VPS** (`BROADCAST_CONNECTION=log`, ver CLAUDE del wacrm: el observer `InboxUpdated` tumbaba la transacción del InboundProcessor al intentar conectar). Un widget en tiempo real exige levantar Reverb en producción con su systemd, o resolverlo con polling. **Decidirlo antes de empezar F4, no durante.**

## F5 — Captura de leads: TikTok + LinkedIn (+ Google Ads)

Entran a **Komo** (patrón meta_ads → `POST /api/v1/leads`, idempotente).

- Migración Komo: `leads.external_lead_id` nullable + `unique(['account_id','source','external_lead_id'])`. **[REV]** Backfill desde `meta_leadgen_id` (`source='meta'`) y corrección del `unique()` global de esa columna (corrección 11); se sigue escribiendo hasta que meta_ads migre.
- `Services/Leads/TikTokLeadSync` (TikTok Business API, OAuth2, pull de Lead Center) y `LinkedInLeadSync` (Marketing API, scope `r_leadgen_automation`). Cron cada 15 min `withoutOverlapping`; webhook si la plataforma lo ofrece.
- `source='tiktok'` / `'linkedin'`; atribución `source_ref` con prefijo de plataforma: `tiktok:{ad_id}`, `linkedin:{ad_id}`. ⚠️ Datos viejos sin prefijo = Meta (patrón `SegmentQuery::upgrade()`: normalizar al vuelo, sin migrar). **[REV]** `LeadApiController@index` filtra `?ad_id=` con `where('source_ref', $adId)` **exacto** — meta_ads dejaría de encontrar sus leads si alguien prefija los de Meta. Prefijar **solo las fuentes nuevas**.
- Reportes "conversión por fuente" los muestran solos (ya agrupan por `source`), y `LeadScorer` pondera las fuentes nuevas solo con historia real (corrección 8).
- Mensajería TikTok/LinkedIn: **NO implementar** (APIs partner-only). Documentar en pantalla de integración.

## F6 — Publicación en redes (social publishing, Komo)

- Tablas `social_accounts` (cuenta conectada por red, tokens cifrados) y `social_posts` (draft/scheduled/published/failed, `payload` por red) + `social_post_targets` (1 post → N redes).
- `Services/Social/Publisher` + adapters: Facebook Pages, Instagram, TikTok Content Posting, LinkedIn. Cron `social:publish-scheduled` cada minuto.
- Composer UI en Komo: escribir una vez, adaptar por red (límites de caracteres visibles), calendario mensual (reutilizar patrón del calendario de tareas T7 con dnd-kit).
- Monitoreo de comentarios: webhook Meta (FB/IG) + poll para el resto → notificación `app_notifications` categoría `marketing`.
- ⚠️ **[REV]** Publicar es irreversible y con público. Un post programado que sale mal no se "reintenta": el job va con `tries=1` + estado `failed` visible, nunca con reintento automático ciego.

## F7 — Publicidad: Google Ads + TikTok Ads (meta_ads)

- El proyecto meta_ads pasa a multi-plataforma: sync de campañas/conversiones de Google Ads API y TikTok Marketing API.
- ROAS unificado por plataforma usando `source_ref` prefijado (F5) + `leads.invoiced_cents/collected_cents` (revenue real ya existe).
- ⚠️ **[REV]** meta_ads no está en el workspace actual: antes de F7 hay que abrirlo y verificar sus supuestos igual que se hizo acá. Estimar F7 sin haberlo leído es adivinar.

---

## 8. Catálogo de redes adicionales (matriz de viabilidad)

| Red                     | Mensajería           | Captura                | Publicación              | Veredicto                | Fase   |
| ----------------------- | -------------------- | ---------------------- | ------------------------ | ------------------------ | ------ |
| Telegram                | ✅ Bot API, gratis   | —                      | —                        | **Sí, completo** (solo inbound-first) | F1     |
| Facebook Messenger      | ✅ Graph API         | ✅ Lead Ads (meta_ads) | ✅                       | **Sí**                   | F2     |
| Instagram               | ✅ DM Graph API      | ✅ Lead Ads            | ✅                       | **Sí**                   | F2     |
| Email                   | ✅ (Gmail T6, vive en Komo) | —               | —                        | **Sí**                   | F3     |
| SMS (Twilio)            | ✅                   | —                      | —                        | **Sí**                   | F4     |
| Web chat                | ✅ propio            | ✅ forms               | —                        | **Sí** (requiere Reverb en prod) | F4     |
| TikTok                  | ❌ partner-only      | ✅ Lead Gen            | ✅ Content API           | Captura+publi            | F5/F6  |
| LinkedIn                | ❌ partner-only      | ✅ Lead Gen Forms      | ✅                       | Captura+publi            | F5/F6  |
| Google Ads              | ❌                   | ✅ Lead Forms          | —                        | Captura                  | F5/F7  |
| YouTube                 | ❌                   |                        | ✅ comentarios/comunidad | Solo engagement          | F6+    |
| Google Business Profile | ❌                   |                        | ✅ reseñas/Q&A/posts     | Solo engagement          | F6+    |
| X/Twitter               | ⚠️ DM en tiers caros | ⚠️                     | ✅                       | Evaluar costo API        | Futuro |
| Pinterest               | ❌                   | ⚠️                     | ✅                       | Solo publicación         | Futuro |
| Discord                 | ✅ bot               | ❌                     |                          | Nicho comunidad          | Futuro |
| LINE / Viber / WeChat   | ⚠️/⚠️/❌             | —                      | —                        | No prioritario (mercado) | —      |

Regla: **mensajería solo donde la API es abierta y estable**; el resto entra por captura o publicación. No construir contra APIs partner-only sin partner aprobado.

## 9. Reglas transversales para el agente (checklist por fase)

1. Suite completa en verde antes y después; tests nuevos nombrados en el commit y en el CLAUDE.md.
2. Gemelos (`ServiceWindow`, `ResponseMetrics`, **[REV]** ahora también `ChannelRules`): si cambia definición → ambos repos + ambos tests en el mismo día.
3. Payloads de webhook aditivos; Komo ignora campos nuevos sin romper. **[REV]** Y al revés: un `channel` desconocido no puede tumbar a Komo (los deploys no son simultáneos).
4. Todo token/secret con cast `encrypted` y **por cuenta**, nunca en `.env` compartido.
5. Idempotencia por ID externo en webhooks y syncs; reintentos seguros.
6. Cortes de rol en servidor; filtros server-side (`?channel=`, `?source=`).
7. Sin `LATERAL JOIN`; caché de arrays, no objetos (lección caché envenenado `esam_datos`).
8. Jobs nuevos → recordar `systemctl restart crm-*-queue.service` en el deploy; crons con `withoutOverlapping`.
9. Nada que envíe salientes a cliente se agenda por defecto sin revisión de costo.
10. UI: estilo Velzon existente (cards rounded-2xl, gradientes de marca, badges con dot); badges de canal junto a `ServiceWindowBadge`.
11. **[REV]** Antes de refactorizar algo con muchos consumidores (`InboundProcessor`, `ServiceWindow::build`), escribir primero el test de caracterización que fija el comportamiento actual.
12. Actualizar `CLAUDE_crm_whatsapp.md` y `CLAUDE_komo.md` con la ronda (decisiones, trampas, tests, suite resultante).

## 10. Orden, esfuerzo y definición de terminado

| Fase                     | Esfuerzo | Deploy             | DoD (definición de terminado)                                                       |
| ------------------------ | -------- | ------------------ | ----------------------------------------------------------------------------------- |
| **F0a** refactor wacrm   | 2 sem    | wacrm              | Suite verde; parity test del ingestor pasa; nada cambia para WhatsApp               |
| **F0b** refactor Komo    | 1 sem    | Komo               | Evento sin teléfono crea contacto+lead; `payload.channel` guardado; badge visible   |
| F1 Telegram              | 1-2 sem  | wacrm(+Komo badge) | E2E: mensaje Telegram → lead Komo → IA responde → agente responde desde ambos inbox |
| F2 Messenger+IG          | 3-4 sem  | ambos              | E2E igual F1 + comentarios públicos + merge mueve identidades                       |
| F3 Email al motor        | 2 sem    | ambos              | Hilo Gmail = conversación en wacrm; IA y supervisión lo miden                       |
| F4 SMS+WebChat           | 2-3 sem  | ambos              | Widget embebido genera lead+conversación (Reverb decidido antes de empezar)          |
| F5 TikTok/LinkedIn       | 2-3 sem  | Komo(+meta_ads)    | Lead de formulario entra idempotente con source y atribución; meta_ads sigue viendo los suyos |
| F6 Publishing            | 3-4 sem  | Komo               | Post programado se publica en ≥3 redes; comentarios notifican; fallo no reintenta   |
| F7 Ads                   | 2-3 sem  | meta_ads           | ROAS unificado Meta/Google/TikTok                                                    |

**Total: 18-25 semanas.** F0 y F1 son el punto de no retorno: con Telegram funcionando, cada canal siguiente es un adapter nuevo sobre el mismo motor.

## 11. **[REV]** Resumen de cambios respecto de la versión anterior del plan

| # | Qué decía el plan | Qué dice el código | Cambio |
| - | ------------------ | ------------------- | ------- |
| 1 | Komo solo necesita leer `payload.channel` | `EventProcessor::syncContact()` descarta todo evento sin teléfono | F0b reescribe la resolución de contacto por identidad — **bloqueante del E2E de F1** |
| 2 | (no lo menciona) | `Wacrm\Client::sendMessage($phone)` y `POST /api/v1/messages` direccionan por teléfono | F0 agrega direccionamiento por `conversation_id` (aditivo) |
| 3 | (no lo menciona) | `contacts.phone` es NOT NULL en wacrm | Migración a nullable en F0 |
| 4 | `channel` en `conversations` | `firstOrCreate` por (cuenta, contacto) mezclaría canales en un hilo | `unique(account, contact, channel)` + conteo de duplicados previo |
| 5 | backfill `external_message_id = wamid` | la columna se llama `message_id` | corregido; + el lookup de `reply_to` necesita filtrar por canal |
| 6 | `ServiceWindow::forConversation()` | no existe; los 4 métodos pasan por `build()` | el corte de canal va en `build()`, un solo punto por repo |
| 7 | (no lo menciona) | `MessagingCost` cobraría canales gratis | recibe canal en F0 |
| 8 | pesos por fuente en `LeadScorer` + test | `source_quality` sale de la tasa de cierre real, degrada solo | **se elimina el trabajo**; queda solo un test |
| 9 | "`payload.channel` ya existe por T6" | lo escribe `EmailSync`; `EventProcessor` no lo lee | precedente de formato, no implementación |
| 10 | "llamar a `InboundProcessor` con `$channel`" | `process()` parsea el sobre de Meta y depende de `WhatsappConfig` | se extrae `InboundMessage` + `Ingestor` (T0.2b), con parity test previo |
| 11 | `unique(account, source, external_lead_id)` | `meta_leadgen_id` hoy es `unique()` **global** | se corrige en F5, con backfill y compatibilidad para meta_ads |
| 12 | Telegram entra limpio | ya se descartó en 2026-07-28 para avisos internos | no aplica al caso cliente, pero fija: **nada de outbound-first** por Telegram |
| 13 | `telegram.bot_token` en `config/services.php` | multi-tenant por cuenta (§1) | va cifrado en `channel_configs`, y la cuenta va en la URL del webhook |
| 14 | F3 "decidir en la fase" dónde vive el email | OAuth y buzones ya viven en Komo | decidido: email se queda en Komo, el adapter del wacrm sale hacia allá |
| 15 | F4 webchat con Reverb | Reverb **no corre** en el VPS (`BROADCAST_CONNECTION=log`) | decisión de infraestructura antes de empezar F4 |
| 16 | F0 = un commit de 2 sem | toca dos repos con orden de deploy | se parte en F0a (wacrm) + F0b (Komo) |
