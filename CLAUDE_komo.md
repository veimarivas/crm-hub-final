# Komo CRM — CRM de leads estilo Kommo (Laravel 13 + MariaDB)

CRM de ventas centrado en **leads** inspirado en Kommo (kommo.com), hermano del **wacrm** (`C:\xampp_82_12\htdocs\laravel_crm_whatsapp`, CRM de WhatsApp). Son **dos proyectos separados integrados por API**: este maneja leads/tareas/pipeline; el wacrm es el motor de WhatsApp.

## F0 — responder EN la conversación, no al teléfono (2026-09-01)

**El bloqueante 2 del plan omnicanal, levantado.** Todo lo que salía de acá direccionaba por teléfono (`Client::sendMessage($phone, $text)`), así que a un lead de Telegram no se le podía contestar desde su ficha: no tiene número. El botón fallaba con *«El lead no tiene un contacto con teléfono»*.

- **`Client::sendToConversation($conversationId, $text)`** contra el `POST /api/v1/messages` del wacrm, que desde esta ronda acepta `conversation_id` como alternativa a `to`.
- **El chat del lead la usa cuando hay `wacrm_conversation_id`**, y cae al teléfono si no. El respaldo no es opcional: los leads anteriores a la integración nunca guardaron ese id.
- Direccionar por conversación es además **más preciso** aunque haya teléfono: un mismo número puede corresponder a más de un hilo cuando hay varios canales.
- El único caso que sigue fallando es **sin conversación y sin teléfono**, y lo dice con esas palabras.

Tests: `LeadReplyByConversationTest` (4). Suite **422/422 (1503 aserciones)**.

## F0b/T0.4 — Komo deja de descartar los eventos sin teléfono (2026-09-01)

**Era el bloqueante del E2E de Telegram, y fallaba de la peor manera posible.** `EventProcessor::syncContact()` arrancaba con:

```php
$normalized = Contact::normalizePhone($remote['phone'] ?? null);
if (! $normalized) { return null; }
```

Un mensaje de Telegram llega **sin teléfono**, así que este proyecto lo tiraba **en silencio**: sin contacto, sin lead, sin evento. El wacrm lo procesaba bien y acá desaparecía sin dejar rastro — no había error que investigar.

### El orden nuevo: identidad → teléfono → descartar
`contact_identities` (espejo del wacrm, con backfill en la migración) y `syncContact()` reescrito:

1. **Por identidad** (`channel` + `channel_external_id`).
2. **Respaldo por teléfono**, que cubre a los contactos anteriores a F0 y a cualquiera que haya entrado por otra vía (alta manual, importación, formulario web) — y de paso les deja la identidad que faltaba.
3. Solo se descarta si no hay **ninguno de los dos**. Ya no es «no trae teléfono» sino «no trae ningún identificador».

**En WhatsApp el identificador del canal ES el teléfono normalizado**, así que si el evento no trae `channel_external_id` se deriva: un wacrm sin desplegar sigue funcionando.

### El canal viaja y da forma al lead
`payload.channel` y `payload.conversation_id` quedan en cada `message_in`. El lead nace con `source = $channel` y título `«Telegram: Ana»` — un lead de Telegram rotulado «WhatsApp:» haría que los reportes por fuente mientan desde el primer día. Un **canal desconocido se guarda crudo y no rompe**: los canales nacen en el wacrm y los deploys no son simultáneos.

**`ServiceWindow::forLeads` ya resuelve el canal de cada lead** desde el último entrante que lo declare (una query más, ordenada ascendente porque `pluck` va pisando la clave y gana el más reciente). Un lead de Telegram muestra «sin límite»; uno de WhatsApp sigue venciendo a las 24 h. Hay un test por cada lado.

**`ContactIdentity` entra al manifiesto de gemelos**: es el mismo concepto y hoy el mismo código en los dos repos.

**Del lado del wacrm** (aditivo, misma ronda): el payload lleva `channel` en la raíz y `contact.channel_external_id`. Sin ese segundo campo, un contacto de Telegram llegaría acá sin ningún identificador y se descartaría — o sea, todo lo anterior no habría servido de nada.

Tests: `InboundChannelTest` (9). Suite **418/418 (1494 aserciones)**.

## F0 (1/n) — los gemelos se vuelven conscientes del canal (2026-09-01)

Primer paso de `plan_omnicanal.md` §F0. **Sin canales nuevos todavía: todo lo existente se comporta idéntico.** Se hizo justo después de D4 y no antes, a propósito — este cambio toca `ServiceWindow::build()` en los dos repos, que es exactamente lo que las fixtures de D4 protegen.

**`Services\Channels\ChannelRules` es un GEMELO nuevo** y nace como tal: archivo byte-idéntico en los dos proyectos, dentro del manifiesto de `SharedFilesDriftTest` desde el día uno. Es una clase **sin dependencias** (constantes + estáticos), y eso es lo que la hace posible: si dependiera de los adapters del wacrm, acá no podría existir.

### Las cuatro preguntas que separan un canal de otro
- **¿tiene ventana de servicio?** Solo los canales de Meta. En Telegram, correo o SMS no hay plazo que vencer.
- **¿tiene las 72 h del anuncio?** **Solo WhatsApp.** Es la regla más fácil de generalizar mal: Messenger e Instagram comparten la app de Meta y las 24 h, pero **no** las 72 h del free entry point. Dárselas diría «todavía es gratis» cuando ya no lo es. El plan lo marcaba como el cambio de definición de gemelos más delicado; queda fijado con dos casos de fixture enfrentados (mismo escenario en messenger y en whatsapp, resultado opuesto).
- **¿exige plantilla aprobada?** Solo WhatsApp.
- **¿se puede escribir primero?** Telegram no (un bot solo alcanza a quien lo inició — lección del módulo de avisos eliminado el 2026-07-28), webchat tampoco.

### El corte va en `build()` y en ningún otro lado
Los cuatro métodos públicos de `ServiceWindow` terminan ahí, así que una sola línea cubre la ficha, los listados y los contactos. `$channel` tiene default `'whatsapp'`: **ningún llamador actual cambió**, y las 9 fixtures que ya existían prueban precisamente ese default.

**⚠️ La trampa que el plan anticipaba y era real:** la rama «siempre abierta» tiene que devolver **todas** las claves del contrato. La tarjeta hace `w.window_hours * 3600` y divide `remaining_seconds` por eso — con `window_hours` ausente es una división por cero, y con `remaining_seconds: 0` el badge diría **«Cerrada» sobre una conversación abierta para siempre**. `window_hours: null` es la señal de «sin límite» y `ServiceWindowBadge` la lee para escribir *«Sin límite»* y llenar la barra.

**`MessagingCost` recibe el canal** (trampa 7 del plan): costo cero para canales sin tarifa, y viaja `has_cost` para que la pantalla pueda decir **por qué** el total es cero — un «USD 0,00» suelto se lee como un error.

**Un canal desconocido no rompe nada.** Los canales nacen en el wacrm y los deploys no son simultáneos: este proyecto va a recibir eventos de canales que todavía no conoce. Todas las reglas contestan que no, que es el criterio conservador — como mucho no ofrece algo, nunca gasta de más. Hay fixture y test.

Tests: `TwinContractTest` sube a 6 (211 aserciones). Suite **409/409 (1454 aserciones)**.

## D3-red — guardián de deriva de los archivos compartidos (2026-09-01)

**No es D3.** D3 —extraer los 36 archivos duplicados a un paquete compartido— **sigue bloqueada** por dónde alojar el paquete de npm, porque el build corre en el VPS. Esto es su red, para que la deriva no siga siendo invisible mientras tanto.

`tests/Fixtures/twins/shared-files.json` lista los **36 archivos que deben ser byte-idénticos** en los dos proyectos: Breeze completo, componentes de UI, hooks. `SharedFilesDriftTest` los compara contra el repo hermano y falla nombrando **cuáles** se separaron y qué hacer con cada uno.

- **Es un test y no un comando a propósito.** Un comando hay que acordarse de correrlo, que es la misma debilidad que la convención escrita. La suite se corre antes de cada deploy igual.
- **Se salta solo donde el hermano no está** (VPS, CI), diciendo por qué, en vez de fallar por algo que no es culpa del código. En desarrollo los dos repos están uno al lado del otro en `htdocs/`, que es donde sirve. `TWIN_REPO_PATH` lo pisa.
- **El manifiesto también se compara entre repos.** Sin eso, alguien saca un archivo de la lista de un solo lado y la red deja de cubrirlo sin que se note — justo la falla que este test existe para evitar.

**Verificado que detecta**, no solo que pasa: se agregó una línea a `PrimaryButton.jsx` del wacrm con la suite en verde y el test de ESTE repo lo señaló por nombre. Después se revirtió.

**Los 61 archivos que hoy divergen quedan fuera del manifiesto**, incluida la capa de gráficos (8 de 11 separados). Meterlos exige decidir cuál versión gana, archivo por archivo — es trabajo de D3, no de su red.

Tests: `SharedFilesDriftTest` (2). Suite **407/407 (1380 aserciones)**.

## D4 — el contrato de los gemelos, fijado con fixtures (2026-09-01)

Cuarta fase ejecutada de `plan_deduplicacion.md`. **Cross-repo, sin orden de deploy**: son solo tests.

`ServiceWindow` y `ResponseMetrics` existen acá y en el wacrm con definiciones que **deben** coincidir. Hasta hoy el mecanismo que lo garantizaba era **acordarse**, y nada lo comprobaba. Ya había pasado con la capa de gráficos, que nació como «una sola» y en un mes tenía dos `format.js`.

`tests/Fixtures/twins/*.json` son **byte-idénticos en los dos repos**. Cada uno construye el estado con **su propia fuente** —acá `lead_events`, allá `messages`— y compara contra los mismos números. **133 aserciones de cada lado, el mismo número.**

- `ServiceWindow::build()` es función pura de dos fechas en los dos repos: 9 casos, incluidas las 72 h que no se reinician y el de «toca el anuncio y escribe en la hora 71 → llega a la hora 95».
- `ResponseMetrics`: el reloj arranca en el primer mensaje de la ráfaga, la IA no cierra la espera, un saliente sin espera abierta es seguimiento proactivo y no entra en los promedios, el SLA se incumple **al** llegar a los 30 min.
- Única diferencia declarada: `first_responder` es `'responsable'` acá y `'asignado'` allá. La fixture usa el token `__owner__` y cada repo lo traduce — la diferencia queda escrita, no escondida.

**Se verificó que el guardián detecta**, rompiendo la definición a propósito con la suite en verde: una constante (`WARNING_HOURS`) y un comportamiento (`max()`→`min()` en la selección de ventana). Los dos salen en rojo con el caso nombrado. Un test que nunca se vio fallar no es una garantía.

**⚠️ Lo que NO protege:** editar las fixtures de los dos repos de forma inconsistente. Para eso el archivo tiene que vivir **una** vez — es D3. Lo que sí se cierra es el caso real: alguien toca la definición que tiene enfrente y su propia suite se pone roja.

**⚠️ Trampa (la de siempre, y por eso está comentada en el test):** `created_at` no es fillable en `LeadEvent`; pasarlo en el `create()` se ignora **en silencio** y todos los eventos quedan con la hora del test, con lo cual toda medición de tiempo da cero y el test pasa por casualidad. Va con `forceFill()->save()` después de crear.

Tests: `TwinContractTest` (4, 133 aserciones). Suite **405/405 (1376 aserciones)**.

## D5b — la etapa se correlaciona por uuid, y gana el lead abierto (2026-09-01)

Tercera fase ejecutada de `plan_deduplicacion.md`. **Cross-repo**: D5a va en el wacrm y se despliega **primero**. **Arregla dos fallas silenciosas que ya estaban en producción**, no es mantenimiento.

### 1. La etapa se correspondía por NOMBRE en las dos direcciones
Komo → wacrm (`setConversationStage`) y wacrm → Komo (webhook `deal.stage_changed`) buscaban la etapa con `where('name', …)`. Dos etapas homónimas en pipelines distintos podían aterrizar el movimiento en la columna equivocada — sin error, sin log, sin rastro. El uuid **ya viajaba** al wacrm en `pipelines/sync` (se guarda allá como `external_id`); solo faltaba usarlo en el movimiento. Ahora va en los dos sentidos, con el nombre como respaldo: los deploys no son simultáneos y el payload es aditivo.

### 2. `->latest()` podía reabrir un negocio cerrado
`handleDealStageChanged` resolvía el lead de la conversación con `where('wacrm_conversation_id', …)->latest()->first()`. Cuando una misma conversación tiene **dos** leads —el cliente vuelve meses después y se abre uno nuevo— ganaba el más reciente **aunque estuviera cerrado**: arrastrar la tarjeta en el wacrm reabría en silencio un negocio que el equipo ya había dado por terminado, con su `reopened` en el timeline y todo.

Ahora el lead **abierto** manda (`ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END`), y entre varios abiertos sigue ganando el más nuevo.

**⚠️ Trampa del test que fija esto:** en el caso natural el lead abierto también es el más nuevo, así que el test pasaría con el código viejo. Hay que **invertir el orden de creación a mano** (`forceFill(['created_at' => …])`) para que la aserción signifique algo.

Para saber si el caso existe hoy en producción:

```sql
SELECT wacrm_conversation_id, COUNT(*) FROM leads
WHERE wacrm_conversation_id IS NOT NULL
GROUP BY wacrm_conversation_id HAVING COUNT(*) > 1;
```

Tests: `StageCorrelationTest` (5). Suite **401/401 (1243 aserciones)**.

## D2b — Komo es el dueño de la taxonomía (2026-09-01)

Segunda fase de `plan_deduplicacion.md`. **Cross-repo**: la mitad del wacrm (D2a: `POST /api/v1/taxonomy/sync`) va en ese repo y tiene que desplegarse **primero**.

Etiquetas y campos personalizados tenían catálogo propio en cada proyecto y **no se sincronizaban**, a diferencia de los pipelines. Era la inconsistencia más gratuita del sistema: el mecanismo ya estaba escrito y probado. `SyncTaxonomyToWacrmJob` es un calco de `SyncPipelinesToWacrmJob`, disparado desde `TagController` y `CustomFieldController`.

- **Se manda el catálogo COMPLETO en cada pasada, no el cambio.** Así un envío perdido se corrige solo con la modificación siguiente, sin llevar registro de qué quedó pendiente. Es la misma decisión que en pipelines y por eso `tries = 1`.
- **Solo viajan los campos personalizados de `entity = 'contact'`.** Los del wacrm cuelgan de `ContactCustomValue`, así que un campo de lead o de empresa sería allá una columna que nadie podría llenar nunca. El recorte va de este lado, que es el que sabe de entidades.
- **Borrar una etiqueta acá no la borra allá incondicionalmente.** Si del otro lado está en uso —etiqueta contactos o alimenta una regla de auto-etiquetado— se desvincula y sobrevive como etiqueta local. Borrarla en cascada habría roto el auto-etiquetado del wacrm en silencio (`auto_tag_rules.tag_id` es `cascadeOnDelete`).

### El comando existe por la primera pasada
`komo:sync-taxonomy --dry-run` **no es un atajo del job**: después el sync lo dispara solo cada cambio, pero la primera vez los dos proyectos tienen catálogos que nunca se hablaron y la reconciliación puede enlazar por nombre, renombrar y borrar. El informe dice qué haría, con el motivo de lo que conserva («en uso: 12 contactos, 1 regla») — un total sin el número no se puede juzgar. El comando sale con código de error si algo falló: se corre en un deploy, donde nadie lee la salida entera.

**Orden de la primera pasada en producción:** desplegar el wacrm, correr `--dry-run`, leer el informe, y recién entonces sin la bandera.

Tests: `TaxonomySyncDispatchTest` (7). Suite **396/396 (1230 aserciones)**.

## D1b — Komo deja de tener motor de envíos propio (2026-09-01)

Primera fase de `plan_deduplicacion.md`. **Cross-repo**: la mitad del wacrm (D1a: `body_type=text`, `audience=phones`, guardián de ventana) va en ese repo y tiene que desplegarse **primero**.

**Lo que se borró:** `Jobs\SendBroadcastMessageJob`, que mandaba texto suelto por `/api/v1/messages` — un request HTTP **por destinatario**, sin plantilla y sin mirar la ventana de servicio. Fuera de las 24 h de Meta eso se rechaza, y el envío tampoco aparecía en las métricas de broadcast del wacrm. Ahora sale **una sola llamada** con la audiencia entera.

**La división, que es lo que hace que exista un solo motor:** Komo resuelve **a quién** (con `SegmentQuery`, que allá no se puede reproducir porque no conoce leads, etapas ni responsables) y el wacrm resuelve **cómo se manda** (plantillas, ventana, rate limit, métricas). Toda la lógica de selección de `BroadcastController` —el corte por rol, los filtros, la intersección con lo tildado a mano, el dedup por teléfono— **no se tocó**: era correcta y es lo único que este proyecto puede hacer mejor que el otro.

### La tabla local cambia de significado
`broadcasts` deja de ser el registro de qué se envió y pasa a ser el de **qué se pidió**: `wacrm_broadcast_id` apunta al envío real y `report` guarda el informe de audiencia de aquel día.

- **`total_recipients` es lo que SALE, no lo pedido.** Si dijera 300 cuando salen 40, la barra de progreso se quedaría clavada en el 13 % para siempre.
- **La audiencia completa se congela igual, incluidos los descartados**, con estado `skipped` y el motivo en castellano. «A quién se le quiso escribir y por qué no se pudo» es parte del hecho histórico — y es la única forma de saber a quién hay que alcanzar después con una plantilla. `skipped` no es `failed`: al primero todavía se le puede llegar.
- Los broadcasts **anteriores a D1b** quedan con `wacrm_broadcast_id = null` y siguen mostrando sus contadores locales. Son historia; reescribirla sería mentir sobre lo que pasó. `isDelegated()` es el corte, y hay test.

### Degradación en vez de pantalla rota
`/broadcasts/{id}` consulta los contadores reales al wacrm en cada render (la pantalla se refresca sola cada 4 s mientras el envío está en curso, así que la llamada ocurre seguido). Si el wacrm no responde **la pantalla no se rompe**: muestra lo último que se supo y lo dice. Los contadores se cachean localmente porque el listado no consulta.

Si el wacrm **rechaza** el envío, el motivo aterriza como error de validación en la pantalla («ninguno tiene la ventana abierta», «WhatsApp no está conectado») y **no queda un broadcast fantasma** diciendo «enviando» para siempre.

**⚠️ Trampa de los tests, no del código:** `Queue::assertNothingPushed()` falla porque crear un lead encola `RunStageAutomationsJob`, y `Http::assertNothingSent()` falla porque las props compartidas consultan el estado de la IA al wacrm. Las dos aserciones tienen que apuntar a lo que el test afirma, no a «no pasó nada». La de «el motor local ya no existe» se escribe con `class_exists`, que además vuelve a fallar el día que alguien lo recree.

`Tests\TestCase` estrena `fakeWacrmBroadcasts()` — integración + alta que acepta la audiencia entera. Lo necesita cualquier test que llegue a `broadcasts.store`; el doble acepta todo a propósito, porque esos tests miden la **selección**, no el envío.

Tests: `BroadcastDelegationTest` (7). Suite **389/389 (1215 aserciones)**.

## T6 — Correo corporativo de Google Workspace (2026-08-08)

Dos decisiones del usuario, ambas tomadas contra mi recomendación de «lo reversible» y ambas correctas para el caso: la institución tiene correos corporativos en Google, así que **OAuth con Workspace** (no contraseña de aplicación) y **el correo es un `message_in`/`message_out` más** (no un tipo de evento propio).

### Gmail API sobre HTTP, no IMAP
El servidor **no tiene `ext-imap`** (solo curl/openssl) — y con Workspace + OAuth la API es mejor igual: `history.list` da **sincronización incremental** («qué cambió desde este punto») en vez de recorrer la casilla entera en cada pasada. Sin dependencias nuevas de Composer: son llamadas HTTP con el `Http` facade.

### La consecuencia de tratar el correo como mensaje
Los eventos son `message_in`/`message_out` con `payload.channel = 'email'`, y el lead nace con `source = 'email'`.

- **A favor:** supervisión, copiloto y segmentos funcionan sobre el correo **sin tocar una línea**. «Esperando respuesta hace 3 h» pasa a ser cierto también para un mail.
- **El costo, dicho explícito:** `ResponseMetrics` —el **GEMELO** del wacrm— empieza a medir también el correo. **No se modificó ni una línea de esa clase**: cambia el dato que entra, no la definición, así que el gemelo sigue idéntico y sus tests intactos. Pero los tiempos de respuesta a partir de ahora **no son comparables con los de antes**, porque el correo se contesta en horas y el WhatsApp en minutos. `payload.channel` permite separarlos el día que haga falta.

### Decisiones que evitan fallas silenciosas
- **`access_type=offline` + `prompt=consent`**: sin ambos, Google no manda refresh token la segunda vez que se autoriza la misma casilla y la sincronización muere en una hora.
- **Al renovar, Google NO reenvía el refresh token**: solo se pisa el de acceso. Sobrescribirlo con `null` desconectaría la casilla en la primera renovación. Hay test.
- **La primera pasada no importa correo viejo**, solo fija el punto de partida: traer meses de historia llenaría el timeline de golpe.
- **Un `historyId` caducado se rearma** en vez de quedar en un bucle de error silencioso.
- **Idempotencia por `gmail_id`**: una pasada que falla a la mitad y se reintenta no duplica el hilo.
- **Los tokens van cifrados en reposo** (cast `encrypted`): un refresh token de Workspace da acceso al correo de la institución.
- **Un saliente escrito desde Gmail se graba con `sender: 'agent'`**: sin eso el sistema lo trataría como respuesta de la IA y supervisión diría que nadie atendió.
- **Solo el dueño administra su casilla.** Un admin ve que existe, pero sincronizarla o desconectarla sería operar correspondencia ajena.
- El cuerpo se extrae recorriendo el árbol MIME **en profundidad**: quedarse en el primer nivel devuelve vacío justo en los correos con adjuntos.

**Falta para cerrar T6:** responder por correo **desde la ficha del lead** (`GmailClient::send()` ya está y maneja `In-Reply-To`/`References` para no partir el hilo, pero no hay UI ni endpoint), y adjuntos.

**Configuración:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_WORKSPACE_DOMAIN` en `.env`; scopes `gmail.modify` + `gmail.send` + `openid email` en la consola de Google Cloud.

Tests: `EmailSyncTest` (14). Suite **382/382 (1149 aserciones)**.

## T7 — Calendario de tareas: arrastrar, filtrar y zona horaria (2026-08-08)

Primera de la ronda `7.b` de `mejoras2.md`. **El calendario mensual ya existía** (`/tasks?view=calendar`); lo que faltaba era poder *usarlo*: reprogramar, filtrar y que los días fueran los correctos.

### La zona horaria era un bug real, no un preparativo
El día de cada tarea lo calculaba el **navegador** a partir de `due_at`. Con `business_hours_timezone = America/La_Paz` y un servidor en UTC, una tarea de las **23:30 aparecía en el día siguiente**. Ahora el servidor manda `due_date` y `due_time` ya resueltos en la zona de la cuenta, y el cliente agrupa por ese string. También `today` viaja desde el servidor: el «hoy» y las vencidas se marcaban contra el reloj del navegador.

**⚠️ Y la trampa que costó el doble desfase:** `Carbon::createFromFormat(..., $tz)` da el instante correcto, pero **Eloquent serializa el Carbon en la zona que trae el objeto, sin convertir**. Guardar uno en `America/La_Paz` mete «10:00» literal en una columna que se lee como UTC, y la tarea queda 4 h antes de lo pedido. Hace falta `->setTimezone(config('app.timezone'))` antes de guardar. Los tests lo fijan con una cuenta en UTC-4.

### Lo nuevo
- **`PATCH /tasks/{task}/reschedule`** + arrastrar la tarea a otro día (`@dnd-kit`, ya instalado en T2). **Conserva la hora** salvo que se mande otra: mover «llamar a Ana a las 10:00» del martes al jueves no puede convertirla en una tarea de medianoche.
- **Queda en el timeline del lead** (`task_rescheduled`): una tarea que se corre tres veces cuenta una historia, y esa historia se pierde si reprogramar es silencioso. Además limpia `overdue_notified_at`, si no la tarea vence de nuevo y nadie se entera.
- **Filtro por tipo de tarea** y, para el admin viendo al equipo, **por responsable**. El corte de rol sigue en el servidor: un `?mine=0` a mano no le abre la agenda del equipo a un agente.
- **Días no laborables atenuados**, derivados del horario que ya se configura en `/settings/business-hours` — no hace falta un ajuste nuevo. Mover una tarea a un domingo suele ser un error de arrastre.

### Vistas de semana y día (grilla de horas)
Completadas después: `?mode=month|week|day`. Una columna por día, una fila por hora, y cada celda es soltable con id `YYYY-MM-DD|HH:00` — arrastrar ahí reprograma **fecha y hora de una vez**, que es lo que el mes no puede hacer.

- **La franja horaria sale del horario comercial**, ensanchada una hora a cada lado: dibujar de 00 a 23 obliga a scrollear por catorce filas vacías para llegar a la mañana, y sin el margen una tarea puesta justo antes de abrir sería invisible.
- **El rango se calcula en la zona de la cuenta**: «esta semana» empieza el lunes del usuario, no el del servidor. Los límites se convierten a la zona de la app **antes de consultar** — Eloquent liga el Carbon formateado en la zona del objeto, así que un rango en La Paz recortaría mal contra una columna en UTC (misma trampa que al guardar).
- **Cambiar de modo mantiene la fecha** que se estaba mirando: pasar de mes a semana y aterrizar en «hoy» hace perder el lugar cada vez.
- **⚠️ `new Date('YYYY-MM-DD')` interpreta el string como UTC**, así que en cualquier zona negativa devuelve el día anterior y la grilla arranca corrida. Hay un `parseYmd()` que construye la fecha a mano.
- Un `?mode=` inválido cae al mes en vez de romper.

Tests: `TaskCalendarTest` (19). Suite **368/368 (1111 aserciones)**.

## T5 — Feedback de la IA, lado Komo (2026-08-08)

Última tarea de `mejoras2.md`. **Cross-repo**: la mitad del wacrm (endpoint + cola de revisión) va en `ae56439` de ese repo y tiene que desplegarse **primero** — Komo tolera su ausencia, al revés no.

- **👍/👎 + «corregir» bajo cada respuesta de la IA en el chat del lead** (`Components/AiFeedbackControl.jsx`). Va ahí y no en una pantalla de configuración porque el agente que ve la respuesta mala es el único que tiene el contexto para arreglarla, **y lo tiene en ese momento**. Pedirle que después vaya a otro lado a reportarlo es garantizar que no lo haga.
- El pulgar abajo abre el campo de corrección pero **no lo exige**: marcar que algo estuvo mal ya es información útil, y bloquear el voto detrás de un formulario haría que nadie vote.
- **Solo bajo mensajes de la IA** (`payload.sender === 'bot'`), verificado también en el servidor: corregir un mensaje que escribió una persona no tiene sentido.
- **Un voto por mensaje y por usuario.** El voto de otro agente no se muestra como propio — hay test.

### Guardar primero, despachar después
`ai_feedback` se guarda **local e inmediato**; el envío al wacrm va en `SendAiFeedbackJob` con reintentos (60 s → 1 h). Si el envío fuera sincrónico y el wacrm estuviera caído, el agente se tomaría el trabajo de escribir la corrección y **se perdería**. `synced_at` distingue lo entregado de lo pendiente; sin integración activa queda guardado y sin marcar, listo para reintentar.

El job manda además **la pregunta que originó la respuesta** (el `message_in` inmediatamente anterior): sin ella la corrección no se puede juzgar — «el precio es 3.500» no dice nada si no se sabe de qué se estaba hablando.

**La UI no promete lo que no pasa:** el texto dice «va a revisión antes de enseñarle a la IA». Del otro lado nada entra al conocimiento sin que un humano lo apruebe.

**Trampa:** la columna de `integrations` es `wacrm_url`, no `wacrm_base_url` — el nombre del método `baseUrl()` invita al error y el fallo aparece como *«Field 'wacrm_url' doesn't have a default value»*, que no señala la causa.

Tests: `AiFeedbackTest` (11). Suite **349/349 (1064 aserciones)**.

## T3 — Motor de workflows con inscripción dinámica (2026-08-08)

Quinta tarea de `mejoras2.md`, la XL. **Esta entrega es el motor; el constructor visual va aparte.** Lo que se puede hacer hoy se hace por código o seeder: la UI llega en el próximo commit.

**La diferencia con `stage_automations` no son las ramas, es el modelo mental.** Aquello es reactivo a un evento puntual (pasó algo → se dispara). Esto es declarativo sobre un estado: se define *quién debe estar* en el workflow y el motor mete y saca leads a medida que la realidad cambia. Un lead creado hace tres meses que hoy empieza a cumplir el criterio, **entra hoy**, sin que nadie lo toque.

### Los guardarraíles se escribieron ANTES que el motor
`Services\Workflows\Guardrails` vive en clase propia y ninguno de sus topes se puede desactivar desde la configuración de un workflow: son del sistema, no del usuario. Hay dos formas de que esto se dispare solo, y las dos terminan en WhatsApp a personas reales y factura de Meta:

- **El bucle** — un paso `change_stage` con disparador `stage_changed` se llama a sí mismo → tope de 50 pasos por inscripción, la corrida queda `failed` con el motivo.
- **El barredor** — un filtro sin re-inscripción bien configurada reinscribe al mismo lead **cada 10 minutos** → sin re-inscripción no se reinscribe nunca, y con ella rige un enfriamiento mínimo del sistema (60 min) aunque se configure menos.

Además: índice único `(workflow_id, lead_id)` **en la base**; tope de 200 inscripciones por pasada (activar un filtro que matchea 4.000 leads no puede disparar 4.000 secuencias de golpe); tope de **3 salientes por lead y por día cruzando todos los workflows** (al cliente no le importa cuántas automatizaciones tenga la empresa); idempotencia por `enrollment:enroll_count:step`; y kill switch por cuenta (`accounts.workflows_paused_at`) que para todo sin deploy.

### Decisiones de esquema
- **`branch_key` es string, no booleano**: HubSpot ramifica por valor, y un booleano obligaría a rehacer la tabla en la primera rama de tres salidas.
- **La re-inscripción REUSA la fila** en vez de crear otra. Así el índice único vale siempre, sin índices parciales (que MariaDB no tiene); el historial vive en `workflow_step_runs` discriminado por `enroll_count`.
- **`workflow_step_runs` es la traza**: hoy un fallo de automatización de etapa solo deja un `Log::warning` que nadie lee, y la pantalla muestra «Activa» sin que haya pasado nada.

### Comportamiento que importa
- **La meta se revisa antes de cada paso**, no solo en el barrido: si el lead se ganó mientras esperaba, no recibe el resto de la secuencia. Sin esto, quien ya compró sigue recibiendo «¿seguís interesado?» — la forma más rápida de que un equipo apague las automatizaciones para siempre.
- **Un paso que falla no mata la inscripción.** Que no se pueda mandar el WhatsApp por falta de teléfono no es razón para no crear después la tarea de seguimiento.
- **`send_whatsapp` fuera de la ventana de servicio no manda callado**: o crea una tarea (`outside_window: 'task'`) o falla. Un workflow no decide gastar por su cuenta.
- **Las esperas respetan la ventana de ejecución del workflow**: una espera que vence 3 AM se corre a la próxima apertura. Un seguimiento automático que sale 3:40 AM se lee como spam de robot.
- Las **ramas se evalúan con `SegmentQuery`**, el mismo motor que la inscripción: un criterio significa lo mismo en los dos lugares.

### Tres bugs que encontraron los tests, no la lectura
- **⚠️ Faltaba la relación `step()` en `WorkflowPendingExecution`.** `$pending->step` devolvía `null` **en silencio**, el motor daba la espera por inválida y **toda secuencia se cortaba después del primer `wait`**. El síntoma no apunta a la causa: parece que el paso siguiente «no hace nada».
- **⚠️ `$config['clave'] ?: $default` revienta cuando la clave no existe**: Laravel convierte el warning en excepción y el paso falla por un campo que era opcional a propósito. Va `($config['clave'] ?? null) ?: $default`.
- **`random_int` para teléfonos en tests colisiona** contra el único `(cuenta, teléfono)` de `contacts` cuando el test crea cientos de leads — el peor tipo de test, el que falla de a ratos. Secuencial.

**Comandos:** `workflows:sweep` cada 10 min (inscribe, cierra metas, desinscribe) y `workflows:tick` cada minuto (retoma esperas vencidas), ambos `withoutOverlapping`.

Tests: `WorkflowEngineTest` (18, los primeros 9 son guardarraíles).

### T3.b — Constructor visual, simulador y activación (2026-08-08)

`/workflows` (admin-only). Lienzo **vertical** como el de Digital Pipeline: se lee como el recorrido del lead. Un grid en zig-zag no deja ver el orden, que es lo único que hay que entender de un workflow.

- **`WorkflowSimulator`** — recorre el árbol **que está en pantalla** (no lo guardado) contra un lead real y **no escribe nada**: ni WhatsApp, ni tareas, ni notas, ni etiquetas, ni eventos. Dice los mismos motivos por los que el motor fallaría (integración inactiva, etiqueta borrada, etapa de otro pipeline) antes de que le llegue algo a un cliente. Marca como `later` lo que queda después de una espera y como `skipped` la rama no tomada.
- **Activar exige que el servidor no reporte problemas** (`Guardrails::activationProblems`): sin pasos, con criterio vacío —que alcanzaría a todos los leads de la cuenta— o con re-inscripción sin enfriamiento suficiente, no se activa.
- **Kill switch a la vista** en el índice, no escondido en configuración: si algo se descontroló hay que poder pararlo sin esperar un deploy.
- **Conteo en vivo** del criterio de inscripción, avisando que el primer barrido entra por lotes.
- El editor de criterios se extrajo a **`Components/SegmentBuilder.jsx`**, compartido con `/segments`: es el mismo `SegmentQuery` del servidor, así que la UI también tiene que ser una sola.

**⚠️ El detalle que evita romper leads en vuelo:** `saveSteps` hace **upsert por id**, no borrar-y-recrear. Los pasos están referenciados por las esperas pendientes y por `current_step_id`; recrearlos en cada guardado dejaría a los leads que están esperando apuntando a un paso inexistente y su secuencia se cortaría **en silencio**. Los que sí quedaron sin su paso se cierran como `unenrolled` **diciendo por qué**. Hay un test por cada mitad.

**⚠️ Con `Carbon::setTestNow` congelado, `latest('created_at')` no desempata** entre filas del mismo instante: las aserciones sobre la traza buscan en toda la colección, no en la última fila.

**Fix aparte:** `BookingReuseLeadTest` fijaba una reserva en `now()->addHour()->setTime(10,0)`, que cae **en el pasado** en cualquier corrida posterior a las 09:00 — el test fallaba según la hora del día. Ahora usa `addDay()`, como su hermano.

Tests: `WorkflowBuilderTest` (17). Suite **338/338 (1045 aserciones)**.

## T4 — Segmentación dinámica (2026-08-08)

Cuarta tarea de `mejoras2.md`. **Un segmento deja de ser una lista y pasa a ser una pregunta**: se guarda la condición y se contesta cada vez que se usa, así que un lead que ayer no calificaba y hoy sí, entra solo. Lo que sigue congelándose es el envío — `broadcast_recipients` es una foto del momento, porque quién recibió qué es un hecho histórico, no una consulta.

- **`Services\Leads\SegmentQuery`** — árbol de condiciones con grupos Y/O (hasta 4 niveles), 19 criterios en 5 familias: atributos, marketing, comportamiento, copiloto y tiempo.
- **`LeadFilter` ya no traduce a SQL**: normaliza la query string, la sube a árbol y delega. **Hay un solo evaluador** — mantener dos es exactamente lo que causó el bug de T0.
- **`GET /segments`** (constructor visual con conteo en vivo), **`POST /segments/count`**, `PATCH` y `DELETE`. Entrada nueva **«Listas»** en el sidebar, junto a Broadcasts.

### Compatibilidad hacia atrás, que era el riesgo real
El formato viejo (JSON plano de `LeadFilter`) está en la base de producción. `SegmentQuery::upgrade()` lo sube al vuelo **sin migrar la columna**: migrar habría dejado sin listas a quien no corriera la migración de datos. Dos tests lo fijan, incluido que `no_task: 1` siga significando `has_pending_task = false` — invertir eso en silencio cambiaría la audiencia de todas las listas viejas.

### Decisiones que cambian resultados
- **`last_inbound older_than` incluye a los que nunca escribieron.** «Hace más de 30 días que no sé nada de este lead» es verdad también cuando nunca dijo nada, y excluirlos dejaría afuera justo a los más abandonados.
- **`inbound_count lte 0`** se pregunta al revés (`whereDoesntHave`): `has(..., '<=', 0)` **no matchea** a los que no tienen la relación, así que la forma directa devolvería vacío.
- **`service_window_open` NO es un criterio, a propósito.** La ventana se calcula en PHP desde eventos y no es expresable en SQL; filtrar por ella obligaría a traer todo y descartar en memoria, rompiendo el modelo de «un segmento es una consulta» y la paginación. La pantalla de envío ya muestra quién está dentro y fuera, y el costo.
- **El conteo en vivo distingue `reachable`** (abierto + con teléfono) de `total`: evita la sorpresa de un segmento de 300 que termina alcanzando a 40.
- **Compartir da lectura, no control.** Solo el creador edita o borra: si cualquiera reescribiera una lista compartida, el resto mandaría envíos a una audiencia que cambió sin avisar.

### Trampas encontradas
- **⚠️ `PHPUnit\Framework\Assert::matches()` existe y es `final`.** Un helper de test llamado `matches()` revienta con un fatal *antes* de correr un solo test, con un mensaje que no menciona tu archivo como culpable.
- **⚠️ No reescribir fuentes con `Get-Content | Set-Content` en PowerShell 5.1**: lee como ANSI y escribe UTF-8 con BOM, con lo cual los acentos quedan en mojibake y PHP falla con «Namespace declaration statement has to be the very first statement». Editar con las herramientas de archivos, no con el shell.
- **`Contact::saving` deriva `phone_normalized` de `phone`**: pasar `phone_normalized => null` no crea un contacto sin teléfono, hay que dejar `phone` vacío. Un test que no lo sepa mide otra cosa y pasa igual.

**Pendiente declarado:** la «evolución del tamaño del segmento en el tiempo» que pedía `mejoras2.md` **no está**. Requiere snapshots periódicos — un segmento es una consulta sobre el estado actual y no se puede reconstruir hacia atrás. Queda para cuando se decida guardar la serie.

Tests: `SegmentQueryTest` (19). Suite **303/303 (959 aserciones)**.

## T2 — Dashboard por widgets (2026-08-08)

Tercera tarea de `mejoras2.md`. **No es solo personalización.** Hasta acá `DashboardController` calculaba **todo para todos en cada carga** —ocho agregados más el recorrido de los eventos de mensaje de la cuenta entera— aunque el usuario mirara dos tarjetas. Ahora el catálogo vive en `WidgetRegistry`, cada widget trae su `resolver`, y el controlador **solo ejecuta el de los visibles**: personalizar es, de paso, dejar de calcular lo que nadie mira.

- **`Services\Dashboard\WidgetRegistry`** — 8 widgets: `kpis`, `urgent_leads`, `copilot_priorities`, `forgotten_leads`, `recent_leads`, `my_tasks`, `pipeline_funnel`, `team_ranking` (admin). Cada uno con label, descripción, tamaño y `adminOnly`.
- **`WidgetContext`** — los scopes de rol viajan como closures ya construidos, no como un booleano que cada resolver interpreta: si cada widget decidiera por su cuenta cómo recortar por responsable, tarde o temprano uno se olvidaría y un agente vería números del equipo.
- **`dashboard_widgets`** — layout **por usuario**, no por cuenta: si un admin acomoda su tablero no le mueve el de nadie. Sin filas = default por rol, así que un widget nuevo en el registro aparece solo para quien nunca tocó su tablero, **sin migración de datos**.
- **`PATCH /dashboard/layout`** reemplaza el layout completo (el cliente manda el estado final; reconciliar altas/bajas/reordenes campo por campo sería más código para el mismo resultado). **`DELETE`** restaura: borrar las filas alcanza. Un tablero que el usuario rompió y no sabe restaurar es peor que uno fijo.

**El test que da sentido a la tarea** es `test_no_se_calcula_el_widget_apagado`: cuenta las queries con `DB::listen` y verifica que la agregación del ranking de equipo no se ejecute si el widget está apagado. Sin ese test, cualquiera podría «simplificar» resolviendo todo de nuevo y nadie lo notaría.

**Cortes de rol, los dos en el servidor:** `layoutFor()` filtra los `adminOnly` (aunque quedara una fila vieja de cuando el usuario era admin) y `saveLayout()` los rechaza al guardar — sin eso, un agente se activaba el widget del equipo mandando el key a mano. `resolve()` además lanza si el rol no alcanza, por si alguien llama al resolver directo.

**Frontend:** dos modos separados —**ver** y **acomodar**— porque dejar los controles de edición siempre visibles llena de ruido la pantalla que más se mira. `@dnd-kit` (`core` + `sortable` + `utilities`, instalado con `--legacy-peer-deps`), grilla de 6 columnas con tamaños `sm|md|lg|full`. Un widget recién agregado muestra su silueta y se llena al guardar: **su payload no existe en el cliente porque el servidor no lo calculó**, que es exactamente el punto de la tarea.

**Cambio de forma de props (rompe lo que dependía del dashboard):** lo que vivía suelto en la raíz (`stats`, `deltas`, `forgottenLeads`, `urgentLeads`, `recentLeads`, `myTasks`) ahora llega dentro de `widgets.<key>`. `DashboardExecutiveTest` se actualizó a la nueva forma; **las definiciones de cada métrica no cambiaron**, solo dónde viaja el dato.

Widget nuevo que estrena el copiloto: **`copilot_priorities`** cruza puntaje alto **con** acción pendiente. Un ranking de score a secas es una tabla de honor —los primeros puestos son casi siempre los mismos y no piden nada—; lo accionable es el cruce.

Tests: `DashboardWidgetsTest` (11). Suite **284/284 (929 aserciones)**.

## T1.a/b — Copiloto: motor de señales y score explicable (2026-08-08)

Segunda tarea de `mejoras2.md`. Backend del scoring; la capa prescriptiva y la UI van aparte.

**La decisión de fondo: no hay ML, y se dice.** No hay infraestructura de entrenamiento ni volumen garantizado por cuenta. Un «87% de probabilidad de cierre» con pesos inventados se lee como ciencia y es decoración — la primera vez que ese 87% no cierra, el equipo deja de mirar el módulo entero. En su lugar:

- **`Services\Copilot\LeadSignals`** — mide los hechos, sin opinar. Todo en **lote**: una consulta agregada por familia de señal. Puntuar una cuenta de noche con N+1 sería impracticable.
- **`Services\Copilot\LeadScorer`** — pondera. Seis factores con peso declarado y justificado: `engagement` 25, `recency` 25, `stage_progress` 15, `source_quality` 15, `attention` 10, `momentum` 10.
- **`Services\Copilot\ScoreLeads`** — persiste. `copilot:score-leads` agendado a las 03:30.

**Separación deliberada señales/pesos:** cuando haya volumen para entrenar un modelo de verdad, se reemplaza `LeadScorer` y `LeadSignals` no se toca — ya devuelve la matriz lista.

### Las promesas que fijan los tests (no los números)
- **El score es la suma de su desglose.** Sin ese test el desglose sería decorativo y podría contradecir al número.
- **`score_factors` se guarda junto al score**, no se recalcula al mostrarlo: recalcular el motivo aparte abre la puerta a que número y explicación se contradigan.
- **Calibración = medición, no predicción.** Se mide qué % de los cerrados de cada banda terminó ganado. Con menos de 200 cerrados se declara **`calibrated: false`** y la UI dirá «sin calibrar». El dato crudo se devuelve igual; lo que no se hace es presentarlo como representativo.
- **Los leads cerrados conservan su banda.** Repuntuarlos al cerrar destruiría la calibración: se perdería en qué banda estaban *cuando todavía se podía hacer algo*.
- **Muestras chicas se ignoran**: una fuente con menos de 10 cerrados no aporta tasa (con 3 casos, «100% de conversión» es ruido).
- **Bandas por tercil** de la cartera —la pregunta real es «¿a quién llamo primero?», que es un ranking relativo— con caída a umbrales absolutos bajo 12 leads, porque un tercil sobre 5 leads no significa nada.

### Trampas encontradas
- **`created_at` no es fillable en `LeadEvent`**: pasarlo en el `create()` se ignora **en silencio** y todos los eventos quedan con la hora del test, con lo cual cualquier test de recencia mide cero y pasa por casualidad. Hay que pisarlo con `forceFill()->save()` después de crear.
- **`score_factors` se guarda por modelo y no con `update()` de query builder**: el query builder no aplica el cast `array` y el JSON queda doblemente codificado.
- La distinción humano/IA sale de `payload.sender` agregado **en SQL** (`JSON_UNQUOTE(JSON_EXTRACT(...))`), no iterando en PHP como `ResponseMetrics`: acá se puntúa la cuenta entera, no una ficha.

Tests: `CopilotScoringTest` (14).

### T1.c — Capa prescriptiva y panel en la ficha (2026-08-08)

**`Services\Copilot\NextActions`** — «¿qué hago ahora con este lead?». Cinco reglas sobre hechos, sin IA: cuesta cero, no alucina y se puede explicar.

- `reply` — el cliente escribió y **nadie humano** contestó. Misma definición que `/supervision` (la IA no cierra la espera): si acá significara otra cosa, el mismo lead aparecería atendido en una pantalla y desatendido en la otra.
- `window` — la ventana sin costo se cierra en menos de 6 h. **Lleva el costo estimado** porque la decisión de apurarse es económica, no de gusto.
- `task` — la regla Kommo. La prioridad **sube con la antigüedad**: un lead de hoy sin tarea es normal, uno de tres semanas está abandonado.
- `cooled` — cayó de banda. Es la señal que justifica guardar la banda anterior: un lead que estaba caliente y se enfrió es algo que se estaba por cerrar y se está perdiendo, muy distinto de uno que siempre estuvo frío.
- `stagnant` — 7+ días en la misma etapa.

**Tres reglas de diseño, todas aprendidas de que el equipo ignore los avisos:** cada sugerencia dice su motivo (*«llamalo»* no se acciona, *«escribió hace 3 h»* sí), trae la acción a un clic, y **se cortan en 4 ordenadas por urgencia** — si todo urge, nada urge. Un lead cerrado no recibe ninguna: sugerirle algo empuja a reabrir lo que el equipo ya decidió.

**Migración `score_band_previous` + `score_band_changed_at`.** Solo se pisan cuando la banda **cambia**: si se sobreescribieran en cada pasada nocturna, a las 24 h dirían siempre lo mismo que la actual y el enfriamiento no se detectaría nunca.

**⚠️ Bug que encontró el test, no la lectura:** `ScoreLeads::forAccount()` traía los leads con un `select` acotado que **no incluía `score_band`**. `bandChange()` comparaba contra `null`, creía que la banda cambiaba en cada corrida y pisaba `score_band_previous` con `null` — «este lead se enfrió» no se habría detectado jamás, en silencio y con los tests del score en verde.

**Panel** (`Components/CopilotPanel.jsx`, columna izquierda de `Leads/Show.jsx`, arriba de la ventana de servicio): qué hacer → score → por qué. El desglose va colapsado pero presente: el día que el número no cuadre con la intuición del asesor, tenerlo a mano es la diferencia entre corregir el criterio y dejar de creerle al módulo. La **calibración se muestra siempre**: o «de los caliente ya cerrados ganó el X%», o «sin calibrar — N cerrados, hacen falta 200». Cada sugerencia aterriza donde se ejecuta (chat con el cursor puesto / pestaña de tareas / stepper de etapa).

Tests: `CopilotNextActionsTest` (12). Suite **273/273 (897 aserciones)**.

## T0 — Un solo traductor de filtros de leads (2026-08-08)

Primera tarea de `mejoras2.md`. Prerequisito de la segmentación dinámica, pero **arregla un bug que ya estaba en producción**.

**El bug:** la misma cadena de filtros estaba escrita tres veces (`LeadController@index`, `LeadController@export`, `BroadcastController@recipientPhones`) y ya había divergido:

| Criterio | `/leads` | CSV | Broadcasts |
|---|---|---|---|
| `stage_id` | ✅ | ✅ | ❌ |
| `tags[]` (varias) | ❌ (solo `tag`) | ❌ | ✅ |
| `include_closed` | — | — | ✅ |
| `q` busca en | título, contacto, teléfono, normalizado | título, contacto, teléfono | título, contacto |

Como `saved_segments.filters` guarda ese mismo JSON, **una lista guardada desde `/leads` con filtro de etapa se ignoraba en silencio al usarla en un envío**: se veían 12 leads en pantalla y el mensaje salía a 300. El test `test_el_mismo_segmento_selecciona_los_mismos_leads_en_leads_y_en_broadcasts` fija exactamente eso.

- **`Services\Leads\LeadFilter`**: único lugar donde un filtro se traduce a consulta. `LeadFilter::KEYS` es el contrato; **una clave desconocida lanza `InvalidArgumentException`**, no se ignora — ignorar en silencio es cómo se produjo la divergencia.
- **El scope de rol vive en la clase**, no en cada llamador: un corte que hay que acordarse de repetir es un corte que algún día se olvida. De paso arregla `export`, que pasaba `responsible` crudo (un agent con `?responsible=<otro>` obtenía CSV vacío en vez de los suyos).
- **`normalize()`** unifica `tag`/`tags`, castea `no_task` (llega como `0|1` desde los segmentos y como string desde la URL) y descarta vacíos. `SavedSegmentController@store` **guarda ya normalizado**: la forma degradada del JSON no vuelve a entrar a la base.
- **Diferencias deliberadas que se conservaron**, ahora explícitas en el parámetro `openOnly`: el tablero muestra ganados y perdidos a propósito; el envío masivo excluye cerrados salvo `include_closed`, porque escribirle a un lead cerrado es un error caro. Hay un test por cada lado.
- **⚠️ Trampa**: `$pipeline->leads()` devuelve una **relación**, no un `Builder`. El type hint estricto `Builder $query` reventaba `/leads` con un `TypeError` que en test aparece como *«The response is not a view»*. La firma acepta `Builder|Relation`.
- Filtros inválidos desde el cliente son **422 con el motivo**, no 500: `/broadcasts/preview` lo llama en cada tecleo.

Tests: `LeadFilterTest` (11). Suite **247/247 (821 aserciones)**.

## Ronda de analítica — reportes legibles y analizables (2026-08-07/08)

Ejecución de `mejoras.md` (raíz del repo). Objetivo: que `/reports` deje de ser tablas y barras sueltas y pase a ser un dashboard analítico. **Ronda gemela**: el wacrm hizo la suya en paralelo con su propio `mejoras.md` (ver `CLAUDE_crm_whatsapp.md`, sección equivalente). Sin migraciones; todo sale de `leads`, `lead_events`, `tasks`. Suite **236/236 (800 aserciones)**.

**Paso 0 — capa de gráficos** (`resources/js/Components/Charts/`, recharts): `chartTheme.js`, `format.js` (`fmtNumber`/`fmtMoney`/`fmtDuration`/`fmtPct`, es-BO), `ChartCard.jsx` + `EmptyChart`, `ChartTip.jsx`, `TrendArea`, `CompareBars` (con `ReferenceLine`, soporta apiladas), `FunnelSteps`, `DonutChart`, `HeatmapGrid`, `WindowPicker`. Importar siempre desde `@/Components/Charts`, no desde los archivos sueltos.

### `/reports` — dashboard analítico (`ReportController` + `Reports/Index.jsx`)
- **KPI cards con delta vs periodo anterior** (`periodStats()` corre dos veces: ventana actual y la inmediatamente anterior) + `WindowPicker` `?days=` 7/15/30/90 que aplica a todo.
- **`weeklySeries()`** — 26 semanas (≈6 meses) de creados / ganados / perdidos en `TrendArea`. Es el gráfico que antes no existía y el que más informa: dice si el negocio acelera o se enfría.
- **⚠️ Trampa cara**: la clave de agrupación semanal debe ser `DATE_FORMAT(campo, '%x-%v')` (ISO, semana que arranca el **lunes**) para coincidir con `Carbon::startOfWeek()`/`isoWeek()`. Con `'%X-%V'` (domingo, que es lo que se escribió primero) las claves **nunca** coinciden y las tres series salen en **cero** sin ningún error visible. El padding también importa: `isoWeek()` devuelve `5`, MySQL devuelve `05` — por eso `sprintf('%02d', …)`.
- **Embudo con % de paso entre etapas** (`FunnelSteps`): el embudo anterior eran barras sin porcentaje; el dato accionable es *dónde se cae el lead*. Clic en etapa → `/leads?stage_id=X`.
- **Fuentes con revenue**: donut de ingresos por fuente (`won_value`) sobre la tabla de conversión que ya existía. Clic en porción → `/leads?source=X`.
- **`teamStages`**: `CompareBars` apiladas por etapa con los colores de etapa del pipeline. **Solo admin** — un agent recibe `byUser` y `teamStages` vacíos desde el servidor, no ocultos en el cliente.
- **Revenue real**: facturado vs cobrado del periodo (`invoiced_cents`/`collected_cents`) + serie mensual de ganados.
- **`closeTimeHistogram()`**: días entre `created_at` y `closed_at` en baldes (‹1 d / 1-3 / 3-7 / 7-14 / 14-30 / ›30). Responde "cuánto tarda en cerrar un lead".
- **`GET /reports/export`** — CSV del periodo con la misma ventana y el mismo scope de rol que `index()` (stream chunked 500, BOM UTF-8, separador `;`, patrón de `ContactController@exportCsv` del wacrm).
- **`LeadController` acepta `stage_id`** server-side (en `index` y en `export`), respetando el scope de rol. Era el requisito del drill-down del embudo.
- Clic en fila del ranking de equipo → `/supervision/agents/{user}`.

### `/supervision` — comparativas de equipo (`Services\Supervision\TeamComparison`)
**Clase nueva a propósito.** `ResponseMetrics` es el **GEMELO** del wacrm y sus definiciones están fijadas por `SupervisionMetricsTest`; `TeamComparison` solo las *consume* (el reloj arranca en el primer mensaje de la ráfaga, la IA no cierra la espera, un saliente sin espera abierta es seguimiento proactivo). No se tocó una línea del gemelo.

- **Mediana** de 1ª respuesta por responsable con `ReferenceLine` de SLA. Mediana y no promedio: un solo caso olvidado de 10 h le arruina el promedio a quien contesta bien el resto del tiempo.
- **Cumplimiento diario del SLA** (% dentro del objetivo). Los días sin respuestas van en **`null`, no en `0`**: si no, un fin de semana sin tráfico se lee como un desplome.
- **Heatmap hora × día** de `message_in` — cuándo escriben los clientes vs. cuándo se atiende.
- **Antigüedad del backlog** en baldes (‹1 h / 1-4 / 4-12 / 12-24 / 1-3 d / ›3 d). Es el **AHORA**: no se recorta a la ventana `?days=`.
- `Supervision/Agent.jsx`: línea punteada del **promedio del equipo** superpuesta a la serie del agente (`teamDailyAverage()`), para que su número tenga contexto.

### Dashboard ejecutivo (`DashboardController` + `Dashboard.jsx`)
- KPIs con delta contra el **equivalente honesto**, no contra "hace 30 días" a secas: abiertos vs. los que estaban abiertos hace un mes (reconstruido desde `closed_at`), ganados vs. **el mismo tramo** del mes pasado (mes a la fecha, para no comparar 7 días contra 30), tareas de hoy vs. las que vencían ayer. Sin base de comparación el delta va en `null` y **no se pinta**: un `0%` mentiría.
- **"Leads sin tarea" no lleva delta**: no hay histórico del que sacar cuántos estaban sin tarea ayer. En su lugar lleva `forgottenLeads`, la mini-lista clicable de los **5 que llevan más tiempo abiertos sin una sola tarea** — el KPI dice cuántos, la lista dice cuáles.

### Sidebar — grupo «Reportes» (2026-08-08)
Ningún enlace faltaba (a diferencia del wacrm, donde los paneles de analítica eran pantallas sin entrada), pero estaban **dispersos**: *Reportes* suelto entre Contactos y Etiquetas, y *Seguimiento* / *Asesores* / *Avisos* bajo un encabezado «Supervisión». Se unificaron en un grupo **«Reportes»** con el mismo patrón del wacrm: flag `analytics: true` en el item + bloque propio entre la navegación de trabajo y la de configuración.

- **Trampa del agrupado**: el grupo anterior se armaba desde `adminNav` y todo el bloque estaba gateado por `adminNav.length > 0`. Meter *Reportes* ahí tal cual habría dejado al **agent sin acceso a `/reports`**, que es justo lo que la ronda le habilitó (ve la pantalla con sus propios números). Por eso el criterio de agrupación es `analytics` y el corte por rol lo sigue haciendo `visibleNav`/`adminOnly`: un agent ve el grupo con un solo item.
- Igual que el resto de los grupos (y que el wacrm), el bloque **no se renderiza con la sidebar colapsada**; en ese modo solo quedan los iconos de la navegación principal.
- *Avisos* (`team-messages`) quedó dentro del grupo: es el brazo ejecutor de la supervisión — desde `/supervision` se sale a mandar el aviso.

**Tests:** `ReportMeasuresTest` (7), `TeamComparisonTest` (8), `DashboardExecutiveTest` (4).

## Ronda de UI — pantallas a ancho completo y modales (2026-08-07)

Ronda de mejoras de layout y UX, solo frontend (`resources/js`). Sin migraciones y sin tocar lógica de negocio; el build de producción va en el servidor.

- **`/bookings`** (`Bookings/Index.jsx`): el campo de link público ahora muestra **solo la ruta** (`/book/{slug}`, derivado con `new URL(bookingUrl).pathname`) mientras **Copiar** y **Abrir** siguen usando la URL completa (`bookingUrl`).
- **`/settings/web-forms`** (`Settings/WebForms.jsx`): ancho completo (`max-w-4xl` → `max-w-7xl`), form de creación en 3 columnas (nombre / pipeline / título visible) y lista en grid de 2. La URL pública muestra solo el path (`/web-forms/{token}`) pero Copiar y abrir usan la URL completa; el snippet del iframe embebe la URL completa (la requiere el navegador).
- **`/settings/business-hours`** (`Settings/BusinessHours.jsx`): ancho completo; las cards de "Horario semanal" y "Auto-respuesta fuera de hora" ahora van en 2 columnas (`lg:grid-cols-2`) aprovechando el ancho.
- **`/settings/team`** (`Settings/Team.jsx`): ancho completo (`max-w-3xl` → `max-w-7xl`), "Miembros" y "API keys" en 2 columnas; **expulsar** y **revocar API key** pasaron de `confirm()` nativo a **modal de confirmación** (`Components/Modal.jsx`), y los **permisos (scopes)** de las API keys se rediseñaron como *pills* seleccionables con cuadro de check, icono por scope y estado activo en violeta.
- **`/settings/integration`** (`Settings/Integration.jsx`): ancho completo (`max-w-3xl` → `max-w-7xl`); paso 1 (credenciales) y paso 2 (webhook) en 2 columnas.
- **`/settings/pipelines`** (`Settings/Pipelines.jsx`): ancho completo (`max-w-4xl` → `max-w-7xl`) y las tarjetas de pipeline en grid de 2 columnas. *(Un intento intermedio de partir cada tarjeta en dos columnas internas —etapas activas izq / terminales der— se probó y se revirtió por pedido del usuario.)*
- **`/pipelines/{id}/automations`** (`Pipelines/Automations.jsx`): ancho completo y las etapas en grid de 2 columnas (antes stack vertical).
- **`/broadcasts`** — Index, Create y Show rediseñados (diseño a ancho completo con grid de tarjetas; Create con imagen+mensaje en `lg:grid-cols-5`; Show con resumen + tabla de destinatarios en 2 columnas).

## Digital Pipeline: plantillas, edición y vista previa (2026-08-07)

`/pipelines/{pipeline}/automations`. Mismo tratamiento que se aplicó a `/automations` y `/flows` en el wacrm. Sin migraciones.

**Los fallos eran invisibles.** `DigitalPipeline\Runner::leadEnteredStage()` atrapa las excepciones de cada acción y deja solo un `Log::warning`: con la integración de WhatsApp apagada o un lead sin teléfono, la automatización figuraba «Activa» en pantalla y no hacía nada. Eso ya no pasa.

- **`Services\DigitalPipeline\Simulator.php` + `POST /pipelines/{pipeline}/automations/simulate`**: vista previa de qué pasaría si un lead entrara a la etapa. Interpola con un lead real, calcula el vencimiento y el responsable de la tarea, y **dice los mismos motivos por los que el Runner lanzaría** (integración inactiva, lead sin teléfono, texto vacío) antes de que ocurra. No manda WhatsApp, no crea tareas ni notas, no registra eventos.
- **`Services\DigitalPipeline\Recipes.php`**: 6 plantillas **filtradas por `stage_type`** — lo que sirve al ganar un lead no sirve al perderlo, y mostrarlo todo junto obliga a leer seis opciones para descartar cuatro. `POST …/automations/recipe` crea todas las acciones de la plantilla en una transacción.
- **`PATCH /automations/{automation}`** — antes solo había crear y borrar: corregir una palabra de un mensaje obligaba a rehacer la acción, y de paso se perdía el `execution_count`. Hay test de regresión sobre eso.
- **La vista se lee como el recorrido del lead**: una etapa debajo de la otra con flecha, en vez del grid de dos columnas (que en zig-zag no dejaba ver el orden del pipeline). Cada etapa muestra cuántos leads tiene **ahora** — dimensiona a cuántos alcanzaría lo que se configure.
- Cada acción muestra su resumen legible (tipo de tarea, vencimiento en lenguaje natural, a quién se asigna) y **qué le falta** para funcionar. Banner arriba si la integración de WhatsApp está inactiva.
- **Trampa**: `assigned_to: ''` no debe guardarse — pisa el fallback al responsable del lead en `Runner::createTask()`. Lo limpia `cleanConfig()`, cubierto por test.
- Tests en `StageAutomationBuilderTest` (12); suite total **217/217 (742)**.

## Ronda reserva-de-reunión + Inbox + Reportes (2026-08-06/07)

Baté de mejoras sobre el flujo de **reserva de reunión** (`/book/{slug}`), la ficha e Inbox del lead y los Reportes. Sin migraciones.

### Reserva → mensaje de confirmación al lead (BookingController@publicStore)
- **Ventana de servicio abierta (24 h / 72 h, integración activa y teléfono):** se envía por WhatsApp al lead *"Se registró la reunión agendada para el {día} a las {hora}."* vía `Wacrm\Client::sendMessage` (`.message_out` lo registra el webhook del wacrm, sin duplicar).
- **Fuera de ventana NO se envía** (evita el pago a Meta por texto libre): en su lugar se crea una **tarea** en el lead: *"No se envió la confirmación de la reserva: fuera de la ventana de servicio (24/72 h)…"*.
- **Error de envío dentro de ventana:** también queda la tarea de "no se envió la confirmación" (error de envío).
- Usa `Services\WhatsApp\ServiceWindow::forLead` para decidir `is_open`. Como el booking siempre crea/reusa el lead, la tarea siempre queda ligada a un lead.
- El **título del lead** ahora guarda **solo el nombre del formulario** (p. ej. `Ana`), sin el prefijo "Reunión:" — la reunión sigue en la tarea `meet` ("Reunión agendada con {nombre}"). Se aplica también al **reusar** un lead con conversación (antes conservaba el título previo, p. ej. "WhatsApp: Ana").
- Tests actualizados en `BookingReuseLeadTest` (título `Ana`, no `Reunión: Ana`).

### Ficha del lead — cabecera de acciones + reunión (Leads/Show.jsx)
- Los botones **Llamar**, **IA activa/Humano** y el badge **Activo** se **movieron a la cabecera**, junto a **Ganado/Perdido** (bloque `Acciones destacadas`). Se **eliminó la barra duplicada** que vivía sobre el composer del chat (gana ~50px al hilo).
- **Badge de próxima reunión reservada** al lado de Ganado/Perdido: "📅 Reunión · {día mes, hora}" (solo si `scheduled_at` es **futuro**; si ya pasó, desaparece). Se calcula con `nextBooking` (filtra eventos `booking` futuros).
- **Fecha/hora de la reunión en el detalle**:
  - Timeline (`TimelineEvent`): bajo "Reunión reservada" se muestra `{weekday, día, mes, hora}` en violeta.
  - Chat (`SystemEvent`): "📅 Reunión reservada · {weekday, día, mes, hora}" con `scheduled_at` del payload.
- payload del evento `booking` = `{source: 'booking', scheduled_at: ISO8601]`.

### Inbox `/inbox` (`InboxController@conversation` + `Inbox/Index.jsx`)
- **El título del lead es el nombre**: en la fila (`ConversationRow`), en el header del hilo y en el panel del lead (`LeadPanel`) se muestra `lead.title` con prioridad sobre `contact.name`.
- **Card "📅 Reunión reservada"** en el panel cuando existe `next_booking` (solo futuras). El controller calcula `next_booking` en el payload de `conversation.lead` (filtra eventos `booking` con `scheduled_at` futuro).

### Reportes `/reports` (`ReportController` + `Reports/Index.jsx`)
- **Conversión por fuente**: nueva fuente **Formulario de reserva** (`source=booking`). Si un booking reusó un lead con contacto inicial por otro medio, la fuente queda la original y no suma acá (esa es la paridad "si ya hubo contacto inicial por otro medio").
- **Canales de marketing**: ahora muestra **WhatsApp** y **Formulario de reserva** como canales propios arriba (iconos 💬/📅); esos leads se **excluyen** de la agrupación por `utm_source` donde antes caían bajo "(direct)".
- **Equipo este mes**: cada agente ahora tiene una **columna por etapa abierta** (Nuevo, Contactado, Negociación…) con sus leads abiertos en cada una (`byUser[].stages` + nuevo prop `stageNames`). Columnas generadas dinámicamente desde las etapas open del pipeline.

### Acceso a Digital Pipeline desde el pipeline (Settings/Pipelines.jsx)
- La ruta que existía (`/pipelines/{id}/automations`) no tenía **entrada en la UI**. Se agregó un botón visible **"⚡ Automatizaciones por etapa"** en cada tarjeta de pipeline (`Settings/Pipelines.jsx`), que enlaza a `route('pipelines.automations')`.

## Fix — Bookings reutilizan el lead con conversación (2026-08-06)

**Bug:** al reservar una reunión (`POST /book/{slug}` → `BookingController@publicStore`) se creaba SIEMPRE un lead nuevo con `source='booking'` y sin `wacrm_conversation_id`, aunque el contacto ya tuviera un lead con el chat de WhatsApp activo. Resultado: desde `/leads` se abría un lead vacío (sin historial) y desde `/inbox` el lead original (con historial) — dos leads para el mismo contacto.

**Fix:** si el contacto ya tiene un lead de la cuenta con `wacrm_conversation_id` no nulo, `publicStore` lo **reusa**: le anexa la tarea `meet`, la reserva y un evento `booking`. Solo crea lead nuevo si el contacto no tiene ninguno con conversación. La tarea y el `host_user_id` de la reserva siguen siendo del host; el `responsible_user_id` del lead reutilizado NO se toca (conserva al dueño del chat).
- Nuevo tipo de evento `booking` en los `EVENT_META` de `Components/Chat.jsx` y `Pages/Contacts/Timeline.jsx` (📅 "Reunión reservada", grupo `lead`).
- Bonus: se corrigió un error 500 latente cuando viene la reserva sin `notes` (ahora `$validated['notes'] ?? ''` al construir la tarea).
- Tests: `Tests/Feature/BookingReuseLeadTest` (reuso no crea lead nuevo; sin lead de conversación sí crea el lead booking). Sin migraciones.

## Rebranding ESAM HUB + paridad de UI con el wacrm (2026-08-06)

Ronda de cambios de interfaz espejados con el wacrm (Ronda 17 de allá):

- **Logo `HUB.png`** (`public/HUB.png`, trackeado en git): reemplaza a `logo_esam.png`/`esam_pequenio.png` en el Login (`GuestLayout.jsx`) y en el sidebar (`AuthenticatedLayout.jsx`).
- **Login**: el logo `HUB.png` aparece a `h-28` dentro de la tarjeta ANTES del título, título nuevo **"Bienvenido a ESAM HUB"** (antes "¡Bienvenido al Komo CRM!") y se quitó el subtexto "Inicia sesión para continuar". Se eliminó el logo del banner fuera del formulario en `GuestLayout` (y el `import { Link }` quedó sin uso y se limpió).
- **Sidebar**: logo `HUB.png` a `h-12`, **centrado** (header del sidebar con `justify-center` fijo en vez del condicional por `sidebarCollapsed`), y el texto al lado ahora dice **"ESAM HUB"** (antes "Komo").
- **Inbox `/inbox` — ocultar/mostrar panel del lead** (paridad con el wacrm): nuevo estado `showContactPanel` (default `true`) + botón 👤 en el header del chat que alterna la columna derecha (`LeadPanel`). El aside pasa de `{conversation && (<aside …>)}` a `{conversation && showContactPanel && (<aside …>)}`. El botón usa `hidden lg:inline-flex` porque el panel es `hidden lg:block` (solo pantallas grandes).
- **Inbox `/inbox` — número debajo del nombre** en la lista de conversaciones (`ConversationRow`): `<p className="text-[11px] text-gray-400 font-mono truncate">{item.contact?.phone}</p>` entre el nombre y la preview del último mensaje.
- **Leads `/leads` — número en cada lead** (`Leads/Index.jsx`): teléfono debajo del nombre/contacto en la tarjeta (`LeadCard`, usa la variable `phone` = `phone_normalized || phone`) y en la vista de fila (`LeadRow`, con `lead.contact?.phone_normalized || lead.contact?.phone`).
- Ninguna migración, solo JS/CSS. El build de producción va en el servidor (`/public/build` está en `.gitignore`).

## Estado: fases 1 y 2 completadas (2026-07-12)

Suite: **31 tests / 99 aserciones en verde** (`php artisan test`). Usuario de pruebas: `admin@gmail.com` / `admin123` (owner, con pipeline "Ventas" sembrado).

Fase 2 (UI completa, estilo Velzon del wacrm — cards rounded-2xl, gradientes, marca #045474):

- Layout propio con **sidebar fija** (`Layouts/AuthenticatedLayout.jsx`): Dashboard, Leads, Tareas, Contactos, Empresas, Integración.
- `Dashboard` (KPIs: abiertos/ganados mes/tareas hoy/**leads sin tarea** — la métrica Kommo), `Leads/Index` (Kanban drag&drop HTML5, punto rojo = sin tarea pendiente), `Leads/Show` (la página estrella: tabs Timeline/Tareas/Notas, editor de datos, botones Ganado/Perdido, panel de **enviar WhatsApp** vía wacrm), `Tasks/Index` (agenda con tabs pendientes/hoy/vencidas/completadas, completar con nota de resultado), `Contacts/Index` y `Companies/Index` (tablas con modal CRUD), `Settings/Integration` (wizard 2 pasos: credenciales+probar conexión / URL del webhook para pegar en el wacrm).
- Controladores: Dashboard, Lead (index/store/show/update/move/destroy/addNote/**sendWhatsapp**), Task (index/store/complete/destroy), Contact, Company, Integration (edit/update/**test** → llama /api/v1/me del wacrm).

## Entorno

- BD: `laravel_komo_crm` (root sin contraseña, XAMPP). Tests contra `laravel_komo_crm_test` (phpunit.xml).
- Puerto sugerido: **8001** (`php artisan serve --port=8001`) — el wacrm usa el 8000.
- Mismo stack y convenciones que el wacrm: Laravel 13 (atributos `#[Fillable]`), UUIDs, multi-tenant por `account_id` (trait `BelongsToAccount`), Breeze+Inertia+React (npm install requiere `--legacy-peer-deps`; `resources/js/bootstrap.js` creado a mano).

## Modelo de dominio (lo que lo diferencia del wacrm)

- **Lead** = oportunidad con ciclo de vida. `leads.status` (open/won/lost) se **deriva del `stage_type` de su etapa** — cambiar de etapa SOLO vía `Lead::moveToStage()` (valida pipeline, sincroniza status/closed_at, registra eventos won/lost/reopened en el timeline).
- **Pipelines** con etapas tipadas: `stage_type` open|won|lost. El registro siembra "Ventas" con Nuevo/Contactado/Negociación + Ganado(won) + Perdido(lost).
- **lead_events** = timeline del lead (created, stage_changed, won, lost, reopened, task_completed, message_in/out, note_added). Los mensajes de WhatsApp aterrizan aquí.
- **Tasks** con due_at/completed_at; `Task::complete()` registra evento en el lead. Scopes `pending()`/`overdue()`. Regla Kommo: ningún lead sin tarea pendiente (`Lead::hasPendingTask()`).
- **Contacts** (con `phone_normalized` — la clave de correlación con el wacrm — y `wacrm_contact_id`) y **Companies**. Tags y Notes son **polimórficos** (leads/contactos/empresas; pivots con PK compuesta, sin uuid id).

## Integración con el wacrm

Tabla `integrations` (una por cuenta): `wacrm_url`, `wacrm_api_key` (cifrada; scopes contacts/conversations/messages), `webhook_secret` (cifrado).

- **komo → wacrm**: `Services/Wacrm/Client.php` consume `/api/v1` del wacrm (me, contacts, conversations, sendMessage).
- **wacrm → komo**: el wacrm registra un webhook saliente apuntando a `POST /webhooks/wacrm/{accountId}` de aquí (sin CSRF, firma HMAC verificada contra `webhook_secret`). `Services/Wacrm/EventProcessor.php`:
    - `contact.created` → contacto espejo (dedup por phone_normalized).
    - `message.received` → si el contacto no tiene lead ABIERTO, crea uno (source whatsapp, primera etapa open del pipeline default, guarda `wacrm_conversation_id`); el mensaje se registra como `message_in` en el timeline. Un lead won/lost NO se reabre — nace un lead nuevo (regla Kommo).

Cableado manual de la integración: en el wacrm crear API key + webhook saliente (eventos message.received y contact.created, URL = komo `/webhooks/wacrm/{account_id}`); en komo guardar url+api_key+whsec en `integrations`.

## Fase 3 completada (2026-07-12) — suite 37/37 (126 aserciones)

- **Digital Pipeline**: tabla `stage_automations` (acción al ENTRAR a una etapa: send_whatsapp | create_task | add_note, tokens {name} {title} {value} {stage}). `Services/DigitalPipeline/Runner` + `Jobs/RunStageAutomationsJob` (cola). Se dispara desde `Lead::booted()` created (cubre manual/WhatsApp/web form) y desde `moveToStage()`. UI: `Pipelines/Automations.jsx` (botón "⚡ Automatizar" en el Kanban) — acciones agrupadas por etapa, pausar/eliminar, contador de ejecuciones.
- **Web forms**: tabla `web_forms` (token público). Rutas públicas GET/POST `/f/{token}` (blade standalone `webform.blade.php` con CSS inline — embebible por iframe), honeypot `website` + throttle `web-form` 10/min/IP. Cada envío: contacto dedup + lead source web_form en la primera etapa + nota con el mensaje. Admin en `Settings/WebForms.jsx` (URL pública + snippet iframe con copiar).
- **Reportes** (`Reports/Index.jsx`): tasa de conversión, ticket promedio, embudo por etapa (barras con color de etapa), cierres won/lost últimos 6 meses, ranking del equipo del mes.
- Sidebar ganó Reportes y Formularios.

## Fase 4 completada (2026-07-12) — suite 41/41 (149 aserciones)

- **Import masivo del wacrm**: botón "💬 Importar del WhatsApp CRM" en Contactos → `ContactController@importFromWacrm` pagina la API del wacrm (tope 40 páginas), dedup por phone_normalized, completa `wacrm_contact_id` en existentes.
- **Equipo**: `TeamController` (mismo patrón wacrm) — invitaciones por link (hash, 7 días, single-use), roles, expulsar (el expulsado recupera cuenta propia con pipeline vía `Services/AccountProvisioner`, que también usa el registro). UI `Settings/Team.jsx` + `/invite/{token}` (`Invitations/Accept.jsx`). Sidebar ganó "Equipo".
- **Tags en leads**: `TagController` + `leads.tags` sync (filtra ids de otras cuentas silenciosamente). UI en la ficha del lead: chips toggle + creador inline "+ Nueva" (Enter crea, Esc cancela).

## Fase 5 completada (2026-07-12) — suite 44/44 (168 aserciones)

- **Custom fields** por entidad (lead|contact|company): tablas `custom_fields` + `custom_field_values` (pivot polimórfico PK compuesta, sin modelo — se maneja con el trait `HasCustomFields` en Lead/Contact: `customFieldValues()` / `syncCustomFieldValues($map, $entity)` que filtra campos de otras cuentas). UI: `Settings/CustomFields.jsx` (3 tarjetas por entidad, sidebar "Campos"), inputs renderizados en Lead Show y en el modal de Contactos según field_type (text|number|date|select).
- **Transferencia de ownership** (`team.members.transfer`, solo owner, el anterior pasa a admin; botón ⭐ en Team.jsx).
- **Tags en contactos**: modal con chips + eager load en la tabla.

## Fase 6 completada (2026-07-12) — suite 49/49 (185 aserciones) — DESARROLLO CERRADO PARA PRUEBAS

- **Notificaciones in-app**: tabla `app_notifications` (nombre para no chocar con las nativas de Laravel) + `AppNotification::notify()` (guard: nunca notificarse a uno mismo). Disparos: lead asignado (store/update de LeadController), lead nuevo por WhatsApp (EventProcessor → owner), lead nuevo por formulario (WebFormController → owner), tareas vencidas (`tasks:notify-overdue` cada 10 min vía Schedule; dedupe con `tasks.overdue_notified_at` — OJO: debe estar en el #[Fillable]). Campana con badge arriba del sidebar + página Notifications/Index con link al lead. Contador compartido como prop Inertia `unreadNotifications`.
- **Empresas**: tags + custom fields en el modal (Company usa HasCustomFields; CompanyController@syncExtras).
- **Operación local del komo ahora requiere 3 procesos**: `php artisan serve --port=8001` + `queue:work` + `schedule:work` (para tasks:notify-overdue).

## Fase 7 completada (2026-07-15) — suite 55/55 (228 aserciones) — API pública + atribución de anuncios

Activa la integración con **meta_ads** (`C:\xampp_82_12\htdocs\laravel_meta_ads`): atribución ROAS y Lead Ads.

- **`leads.source_ref`** (ad_id de Meta) + `source_url` + `meta_leadgen_id` (unique, idempotencia de Lead Ads). Migración 2026_07_15.
- **EventProcessor** (`message.received` del wacrm): si el mensaje trae `referral` (anuncio Click-to-WhatsApp), el lead nuevo nace con `source_ref`/`source_url` y el evento `created` registra `ad_id`. En leads abiertos existentes solo se escribe si aún no tienen source_ref (la atribución original se preserva).
- **Sistema api.key copiado del wacrm**: tabla `api_keys` (hash SHA-256, prefix `komo_live_`), modelo `ApiKey` (scopes `leads:read` / `leads:write` / `contacts:read` en `ApiKey::SCOPES`), middleware `AuthenticateApiKey` (alias `api.key` en bootstrap/app.php), rate limiter `public-api` 120/min por key.
- **`routes/api.php`** `/api/v1`: `GET /me` (ApiController), `GET /leads` (filtros `?ad_id=` → source_ref, `?source=`, `?status=`; devuelve `{data:[{id,name,status,value_cents,…}], meta}` — el shape que espera el `KomoClient::leadsByAdId` de meta_ads) y `POST /leads` (LeadApiController@store: crea lead source `lead_ad`/`api`, dedup de contacto por phone_normalized, pipeline/etapa del payload o fallback al default, custom_fields → nota, notificación al owner, idempotente por `meta_leadgen_id` — reenvío devuelve 200 con `duplicated:true`).
- **`GET /api/v1/contacts`** (scope `contacts:read`, `ContactApiController`): filtros `?tag_id=` (server-side, incluye tags en la respuesta) y `?q=` — lo usa meta_ads (Fase 7.2) para armar Custom Audiences desde tags del komo.
- **UI**: sección "API keys" en Settings/Team (crear con scopes, secreto `komo_live_…` mostrado UNA vez, revocar) + chip azul "Vino del anuncio X" (con link `ver anuncio ↗` si hay source_url) en la ficha del lead.
- Tests en `PublicApiTest` (auth/scopes, GET con value_cents, POST idempotente, referral→source_ref, CRUD de keys).

Cableado con meta_ads: crear aquí una API key con ambos scopes y pegarla en meta_ads → Ajustes → Integraciones (tarjeta Komo).

## Fase 17 (2026-07-24/25) — Feedback "IA pensando" en el chat del lead

- **Migración `2026_07_25_000001`**: `leads.ai_pending` (bool, default false, después de `ai_enabled`). Flag efímero: TRUE mientras la IA del wacrm está generando respuesta para este lead.
- **`Lead` model**: `ai_pending` en `#[Fillable]` + cast a boolean.
- **`EventProcessor@handleAiPending`** (nuevo handler para el evento `ai.pending_changed` del wacrm — Ronda 16 del wacrm): recibe `{conversation_id, pending: bool}` y actualiza `Lead::where('wacrm_conversation_id', ...)` con el nuevo estado. Silencioso si no hay lead matching.
- **UI `Show.jsx` — burbuja de IA pensando**: cuando `lead.ai_pending=true`, después del último `chatItems` del hilo aparece la misma burbuja violeta gradiente con 3 dots animados y texto "Pensando respuesta…" que el Inbox del wacrm. El polling de 2s del chat la pinta/despinta según el estado real del lead. Latencia percibida: 2-4s de retraso vs wacrm por el polling.
- **Trampa operativa**: hay que activar el evento `ai.pending_changed` en el webhook saliente del wacrm (Ajustes → Equipo → Webhooks → editar el que apunta a `komo.posgradosinnovaciencia.com` → marcar el chip nuevo). Sin ese paso, Komo nunca recibe la notificación.

## Fase 16 (2026-07-24) — Recordatorios, reportes, timeline unificado y perfil de usuario

- **Recordatorios diarios de tareas** (`komo:remind-daily-tasks`, `RemindDailyTasks`): comando artisan que para cada user con `phone` cargado + tareas pendientes hoy/vencidas, envía un WhatsApp con el resumen (usa `Wacrm/Client::sendMessage`). **DESACTIVADO en Schedule** — Meta cobra por conversaciones iniciadas fuera de la ventana de 24h y además necesita template aprobado; el comando queda dormido en el código y se reactiva si algún día se aprueba un template. Por defecto usamos solo notificaciones in-app (campana + AppNotification tipo `task_overdue` cada 10 min ya existente). Migración `2026_07_24_000001` agrega `users.phone` (opcional, se carga en Perfil).
- **Campo `phone` en Perfil** (`ProfileUpdateRequest` + `UpdateProfileInformationForm.jsx`): input tel opcional con placeholder `591xxxxxxxx`. Si el user lo carga, queda listo para cuando se implemente el envío (template Meta / SMS / push).
- **Reporte de conversión por fuente** (`ReportController@index` extendido, sección nueva en `Reports/Index.jsx`): agrupa leads por `source` (whatsapp/lead_ad/web_form/manual/api/other), muestra tabla con Total/Abiertos/Ganados/Perdidos/Ingresos/% Conversión. Ordenado por total desc. Iconos por fuente (💬📣📋✍️⚙️). Badge verde si conv ≥50%, ámbar 25-49%, rojo <25%. Ideal para decidir dónde invertir marketing. Respeta scope de rol (agent solo ve sus leads).
- **Timeline unificado del contacto** (`ContactController@show`, ruta `/contacts/{contact}/timeline`, página `Contacts/Timeline.jsx`): vista 360° del contacto — header con avatar+tags+contacto, cards de TODOS sus leads (histórico), y lista unificada ordenada por fecha desc con eventos (message_in/out/stage_changed/won/lost/notas) + tareas + notas. Cada item enlaza al lead correspondiente. Útil para reunión con cliente o handoff entre agentes. Sin route link en la UI de Contacts/Index — se accede directo por URL o agregando el link.
- **`users.phone` fillable**: agregado al `#[Fillable]` del User. Sin phone no se rompe nada (todos los flujos son tolerantes a null).
- **Trampa Meta cobro**: el comando `komo:remind-daily-tasks` **NO** se agenda por default. Si se agrega `Schedule::command(...)->dailyAt('08:00')` en `console.php` y algún user tiene phone cargado, se dispararán mensajes por WhatsApp saliente vía wacrm→Meta, que fallarán (fuera de ventana 24h sin template) o cobrarán ~$0.01-0.03 USD por conversación si hay template aprobado. Decisión intencional: dejarlo dormido salvo aprobación explícita.

## Fase 15 (2026-07-23/24) — Paridad total con el wacrm en el chat del lead

Después de la Ronda 14 del wacrm (que agregó típing/fallback IA + plantillas + búsqueda + whisper + TTS + bulk + media/quick-replies API), Komo levantó todas esas features en su chat del lead. Ahora un agente puede trabajar 100% desde Komo sin volver al Inbox del wacrm salvo para triaging global.

- **Reproducción real de media** en el chat del lead — nueva ruta proxy `GET /leads/media/{mediaId}` (`LeadController@media`): llama a la nueva API `GET /api/v1/media/{id}` del wacrm (scope `conversations:read`), descarga el binario y lo re-sirve desde el dominio del Komo (Cache-Control: private 1h). Evita CORS/cookies cross-domain. En `Show.jsx`, el `ChatBubble` renderiza según `payload.type`: `audio` → `<audio controls>`, `video` → `<video controls>`, `image` → thumbnail clickeable con lightbox nativo, `document` → link "📄 Descargar documento". Todo usa `route('leads.media', p.media_id)`.
- **`media_id` guardado en el payload de eventos** — `EventProcessor@handleInboundMessage` y `handleOutboundMessage` guardan `media_id` (viene del webhook `message.received` del wacrm — reflejo del `Message.media_url` = Meta media_id). Sin esto, los eventos viejos no tienen media_id y no se pueden reproducir. Los eventos nuevos sí.
- **Handler `message.transcribed`** (`EventProcessor@handleTranscribed`) — nuevo evento del wacrm que llega cuando Whisper termina. Busca eventos `message_in`/`message_out` del lead por `wamid` (via `whereJsonContains('payload->wamid', $wamid)`) y actualiza `payload.text` y `payload.transcript` con el texto transcrito. Así los audios pasan de mostrar "[sin texto]" a mostrar la transcripción real. El evento se activa en el webhook saliente del wacrm; hay que marcar el checkbox `message.transcribed` en Ajustes → Equipo → Webhooks del wacrm (o vía tinker que actualice el array `events` del `WebhookEndpoint`).
- **TTS en el chat** — mismo patrón que wacrm: singleton `ttsState.current` + Web Speech API con `lang: 'es-BO'`. Botón 🔊 aparece al hover en cada burbuja con texto o transcript. Click alterna play/pause; segundo click al mismo lo detiene.
- **Composer completo** en el chat del lead (paridad wacrm):
    - **Adjuntar archivo** (📎): `<input type="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">`. Endpoint `POST /leads/{lead}/whatsapp-media` (`LeadController@sendMedia`): valida `file` max 16MB, encodea base64, llama a `Wacrm/Client::sendMedia($phone, $b64, $mime, $filename, $caption)` que a su vez hitea `POST /api/v1/messages/media` del wacrm.
    - **Grabar voz** (🎤): componente `VoiceRecorder` con `opus-recorder` (nuevo devDependency de Komo, mismo setup que wacrm). Estados idle→recording→preview→sending. Al enviar hace `POST /leads/{lead}/whatsapp-media` con un `File` .ogg audio/ogg.
    - **Plantillas rápidas** (📋): endpoint `GET /leads-quick-replies` (`LeadController@quickReplies`) hitea `GET /api/v1/quick-replies` del wacrm (scope `messages:write`) → lista las compartidas del equipo. Dropdown en el composer, click inserta con `renderTemplate()` que sustituye `{name}` `{phone}` `{email}` con datos del `lead.contact`.
- **Botón 📞 "Llamar" en el header del chat** — abre `https://wa.me/{phone_normalized}` en nueva pestaña. En móvil abre la app de WhatsApp del agente y permite iniciar llamada de voz/video. **Aclaración**: WhatsApp Business Cloud API NO soporta llamadas programáticamente (la Calling API está en beta cerrada de Meta) — el botón es solo un puente al WhatsApp personal del agente.
- **`opus-recorder` en `package.json`** — agregado con `npm install opus-recorder --legacy-peer-deps`. Import del worker con `import encoderPath from 'opus-recorder/dist/encoderWorker.min.js?url'` para que Vite lo empaquete y sirva. Config `encoderApplication: 2049 (VOIP), encoderSampleRate: 48000, numberOfChannels: 1, streamPages: false` → produce ogg/opus (único formato de audio que Meta acepta).
- **`Wacrm/Client` extendido** con 3 métodos nuevos: `sendMedia($phone, $b64, $mime, $filename, $caption)`, `quickReplies()`, `downloadMedia($mediaId)` (retorna `[contentType, bytes]`).
- **Fix duplicado de mensajes salientes** — `LeadController@sendWhatsapp` YA NO graba evento `message_out` local. Antes hacía doble: grababa local + wacrm disparaba `message.sent` webhook → aparecía duplicado. Ahora el webhook es la única fuente de verdad. Trade-off: el mensaje enviado desde Komo aparece con 1-3s de delay (lo que tarda DeliverWebhookJob + polling), pero sin duplicados.
- **Trampa conocida — audios viejos sin `media_id`**: los eventos `message_in`/`message_out` creados ANTES de que el wacrm empezara a mandar `media_id` en el webhook (pre-Ronda 14) tienen `payload.media_id = null` → NO se reproducen (el ChatBubble no renderiza el `<audio>` sin `media_id`). Solo se puede reproducir lo que llegue de ahora en adelante. Si querés backfill, se puede escribir un artisan que recorra los eventos, tome el wamid, pregunte al wacrm el media_id correspondiente y actualice el payload — no implementado por ahora.

## Fase 14 (2026-07-22/23) — UX, notificaciones y sincronización más ágil con wacrm

- **Sidebar y login rediseñados al estilo wacrm** (`Layouts/AuthenticatedLayout.jsx` + `Layouts/GuestLayout.jsx` + `Pages/Auth/Login.jsx`): mismo fondo dark blue #042048, accent amarillo `#e6dd5e` en el item activo, logo ESAM chico (`public/esam_pequenio.png`, copiado del wacrm) + texto **"Komo"** al lado (oculto cuando `sidebarCollapsed`). Login: quitado el link "Regístrate", footer minimalista `© 2026 Derechos reservados`. La página `Invitations/Accept.jsx` ya estaba al día — no se tocó.
- **Ficha del lead — toggle hamburguesa "Datos del lead"**: botón ☰ verde a la izquierda de "Volver a leads" oculta/muestra la columna izquierda. Cuando está oculta, el chat ocupa el 100% del ancho (`lg:grid-cols-3` → `lg:grid-cols-1`). Combinado con el sidebar colapsable, el chat queda casi a pantalla completa — ideal para leer conversaciones largas.
- **Ficha del lead — altura fija con scroll interno**: contenedor del panel de chat cambió de `minHeight: 70vh` a `height: calc(100vh - 12rem); maxHeight: 900px`. El hilo scrollea internamente (`overflow-y-auto` ya estaba), el composer siempre visible, la página no crece infinita.
- **Ficha del lead — polling 5s → 2s** (`Show.jsx`): `setInterval(tick, 2000)` con `router.reload({ only: ['events','tasks','notes','lead'] })`. Sensación casi en tiempo real; requests ligeros (solo esos 4 props, no la página completa). Sigue respetando `document.hidden` para no consumir con pestaña en segundo plano.
- **Cola `--sleep=3` → `--sleep=1`** (systemd `crm-komo-queue.service`): mismo cambio que en wacrm. El worker de Komo también se activa 3× más rápido para procesar webhooks entrantes.
- **Notificaciones — endpoint `notifications.go`** (`GET /notifications/{notification}/go`, `NotificationController@go`): marca la notificación como leída y redirige al lead asociado (o a `/notifications` si no hay `lead_id`). El link "Ver lead «...»" del listado ahora apunta a este endpoint en vez de directamente a `leads.show` — así con **un solo clic** el user va al lead Y la notificación se marca leída. El contador del header (shared prop `unreadNotifications`) se recalcula automáticamente en el próximo request Inertia.
- **`sendWhatsapp` ya NO graba evento local `message_out`** (`LeadController@sendWhatsapp`): antes grababa el evento localmente Y el wacrm disparaba `message.sent` → aparecía duplicado en el timeline. Ahora el webhook es la ÚNICA fuente de verdad para mensajes salientes. El delay entre enviar y ver es de 1-3s (el que tarda el DeliverWebhookJob + polling), pero sin duplicados. Trampa: si en el futuro el wacrm falla en disparar `message.sent`, el mensaje enviado desde Komo no aparecerá en el timeline — worth monitorear.
- **Trampa conocida**: los agentes recién invitados DEBEN aceptar la invitación (el link `/invite/{token}`) para existir como User real. Si el admin creó un `AccountInvitation` pero el agente no la aceptó, aparece en Ajustes → Equipo pero NO en el dropdown "Responsable" del lead (que consulta `users` table). Solución: reenviar el link (botón "🔄 Regenerar link") o crear el user directo por tinker.

## Fase 13 (2026-07-22) — Autor del mensaje visible en el chat del lead

- **`EventProcessor@handleOutboundMessage`**: además de `sender` (agent|bot), guarda `sender_name` y `sender_role` en `payload` del evento `message_out`. Vienen del webhook `message.sent` del wacrm (Ronda 12 del wacrm — receptor compatible con eventos viejos que no los traen: se guardan como null).
- **`Show.jsx` — `outboundAuthor(payload)` + label sobre la burbuja**: sobre cada burbuja saliente del chat del lead aparece:
    - `✨ IA` (violeta) para `sender=bot`.
    - `{sender_name}` para agents, con sufijo `· Admin` cuando `sender_role` es owner/admin.
    - Fallback `Agente` si el payload no trae `sender_name` (eventos viejos anteriores a este cambio).
    - Layout envuelto en `flex flex-col items-end` (o items-start para message_in) — el nombre queda pegado al borde de la burbuja del lado correspondiente.

## Fase 12 (2026-07-21/22) — Producción, integración total con wacrm y restricciones por rol

Ronda de integración end-to-end en producción. Komo desplegado en `https://komo.posgradosinnovaciencia.com` (VPS Ubuntu compartido con el wacrm; nginx vhost `/etc/nginx/sites-available/crm-komo`, cert Let's Encrypt, systemd `crm-komo-queue.service`, cron `schedule:run` cada minuto). Cookie: `SESSION_COOKIE=komo_session`. Sin Reverb (usamos polling; ver más abajo).

- **Rediseño login estilo wacrm** (`Layouts/GuestLayout.jsx` + `Pages/Auth/Login.jsx` + `Invitations/Accept.jsx`): fondo `bg-gradient-to-br from-[#042048] via-[#1c486c] to-[#045474]` con blur circles, logo ESAM (`public/logo_esam.png`, copiado del wacrm), card blanca central con ícono amarillo `#e6dd5e`. Reemplaza los componentes Breeze originales que se veían mal sobre el nuevo fondo.
- **Ficha del lead estilo chat WhatsApp** (`Leads/Show.jsx` completo): nueva tab **"💬 Chat"** como principal — reemplaza el Timeline como vista default. Header con avatar circular de iniciales + gradiente por hash del nombre (8 AVATAR_COLORS), teléfono en monoespaciado, badge "Activo". Hilo con burbujas rounded-2xl agrupadas por día vía `dayLabel()` (Hoy/Ayer/nombre), separadores `DateSeparator`, `ChatBubble` (message_in bg-white izq / message_out gradiente marca der con ✓✓), `SystemEvent` (chip centrado para stage_changed/won/lost/note/task). Composer inferior con textarea auto-crece + Enter=enviar. Tabs originales (Tareas/Notas/Timeline) siguen accesibles. **Polling 5s** con `router.reload({ only: ['events','tasks','notes','lead'] })` mientras la tab Chat esté activa y `!document.hidden`.
- **Handler `message.sent`** (`Services/Wacrm/EventProcessor@handleOutboundMessage`): registra los mensajes salientes del wacrm (agente o IA) como evento `message_out` en el timeline del lead. Idempotente por wamid (`whereJsonContains('payload->wamid', $wamid)`). Ignora silenciosamente si no hay lead abierto para el contacto.
- **`Wacrm/Client` extendido** con 3 métodos nuevos (todos requieren scopes nuevos en la API key del wacrm):
    - `provisionUser(email, name, password, role)` — POST `/api/v1/team/provision`.
    - `assignConversation(conversationId, email)` — PATCH `/api/v1/conversations/{id}/assign`.
    - `setAiMode(conversationId, aiEnabled)` — PATCH `/api/v1/conversations/{id}/ai-mode`.
- **Sync de responsable Komo → wacrm** (`Jobs\SyncLeadAssignmentToWacrmJob`, ver también la sección de asignación más abajo): cuando cambia `responsible_user_id` de un lead con `wacrm_conversation_id`, llama a `Client::assignConversation()` con el email del nuevo responsable. Falla silenciosa con Log::warning si la red o la API responden mal.
- **Auto-provisión de user en wacrm al aceptar invitación** (`TeamController@redeem` → `provisionInWacrm()`): tras crear el user local, llama a `Client::provisionUser()` con **el mismo email y password**. Así el agente logueado en el Komo puede entrar al Inbox del wacrm con las mismas credenciales. Traducción de roles: admin→admin, agent/viewer→agent.
- **Toggle IA/Humano por lead** (columna `leads.ai_enabled` boolean default true, migración `2026_07_22_000001`): botón violeta "✨ IA activa" / gris "👤 Humano" en el header del chat de la ficha. Endpoint `PATCH /leads/{lead}/ai-mode` (`leads.ai-mode`) llama a `LeadController@setAiMode` → actualiza el lead + espeja a wacrm via `Client::setAiMode()`. Permisos: si el lead **no tiene responsable**, solo admin/owner puede togglear; si tiene, solo el responsable o el admin. Otros agents ven el estado como badge readonly.
- **Restricción por rol** (todas las secciones):
    - **Middleware `admin.only`** (`Http/Middleware/AdminOnly.php`, alias en `bootstrap/app.php`): 403 para no-admin. Aplicado en `routes/web.php` a Formularios, Campos, Equipo, Integración (grupo `Route::middleware('admin.only')`). Notificaciones queda fuera del grupo (todos las ven).
    - **Sidebar** (`Layouts/AuthenticatedLayout.jsx`): items del NAV marcados `adminOnly: true` se filtran por `visibleNav` según `user.account_role`. Ocultos para agent: Formularios, Campos, Equipo, Integración.
    - **`LeadController`**: `index` filtra por `responsible_user_id = user.id` para no-admin; `authorizeLead` bloquea Show/update/whatsapp/etc de leads ajenos; `update` descarta `responsible_user_id` del validated si el user no es admin (agent no puede reasignarse ni pasarlo a otro).
    - **`InboxController`**: el agente ve **exclusivamente** los leads con `responsible_user_id = user.id`. **Ya no ve los leads sin responsable** (antes el scope era `suyos OR whereNull`): con el round-robin repartiendo automáticamente, un lead sin asignar es trabajo que el admin todavía no distribuyó, no una bandeja común. Las pestañas "Sin asignar" y "Todo el equipo" son admin-only en `Inbox/Index.jsx`, y un `?filter=` de admin llegado a mano cae a `mine` en vez de mostrar un vacío que se lee como "no hay nada". Tests en `InboxScopeTest`.
    - **`TaskController`**: el agente ve **solo sus tareas** — `mine` queda forzado a `true` para no-admin, así un `?mine=0` a mano no abre la agenda del equipo (el toggle "Solo mías" es del admin y se oculta para el resto). `authorizeTask()` cubre complete/uncomplete/snooze/destroy: antes solo comprobaban la cuenta, así que con el ID de una tarea ajena se podía completar o borrar la agenda de un compañero. `store`: agent solo puede crear tareas en leads asignados a él.
    - **Empresas (`/companies`) es admin-only** y vive en la sección CONFIGURACIÓN del sidebar: es un catálogo que se mantiene, no una pantalla de trabajo diario. El agente sigue pudiendo asociar una empresa desde la ficha del lead — ese desplegable se alimenta del `LeadController`, no de estas rutas.
    - **`LeadController@destroy` es admin-only.** Borrar un lead se lleva por delante su historial de conversación y no hay vuelta atrás; un responsable no puede hacer desaparecer el registro de lo que habló con el contacto. La "Zona peligrosa" de `Leads/Show` se oculta para no-admin **y** el servidor corta con 403. El borrado pide **escribir el nombre del contacto** en un modal (`DeleteLeadModal`) en vez de un `confirm()`, que se acepta por reflejo sin leerlo.
    - **Tablero de leads**: el filtro por responsable es **admin-only**. `$filters['responsible']` se fuerza a `null` para no-admin — si se aplicara, un `?responsible=<otro>` le devolvería lista vacía, que se lee como "no hay leads" y no como "eso no es tuyo". El agente ve un chip "Tus leads" en lugar del desplegable. `isAdmin` viaja como prop desde el controlador (el mismo que decide qué leads devuelve) en vez de derivarse en el front.
    - **`/pipelines` es un alias de `/leads`** (mismo `LeadController@index`): en el wacrm el tablero vive en esa URL, así que las dos apps se navegan igual. `/leads` sigue funcionando para links viejos y el item del sidebar se marca activo en ambas (`isActive` acepta array de patterns).
    - Tests en `RoleScopeTest` (10): todos nacieron del mismo patrón — la UI ocultaba la opción pero el servidor no la cortaba.
    - **`ContactController@index` + `CompanyController@index`**: filtro `whereHas('leads', responsible_user_id=user.id)` para no-admin.
    - **`DashboardController`**: todos los KPIs y `recentLeads` scoped por responsable (agent solo ve sus números). `myTasks` ya filtraba por user.
    - **`ReportController`**: mismo scope. `byUser` (ranking del equipo) queda vacío para no-admin (solo admin compara equipo). Pasa `isAdmin` como prop.
    - **`Show.jsx`** (frontend): campo Responsable es dropdown solo para admin; para agent aparece como texto readonly con el nombre. Consumido de `auth.user.account_role`.
- **Botón "🔄 Regenerar link" en invitaciones pendientes** (`TeamController@regenerateInvitation` + `Settings/Team.jsx`): crea un token nuevo para una invitación existente (útil cuando el admin perdió el link original que solo se muestra una vez). Ruta `POST /settings/team/invitations/{invitation}/regenerate` (`team.invitations.regenerate`). Renueva `expires_at` a +7 días.
- **Artisan `komo:sync-assignments`** (`Console/Commands/SyncAssignmentsToWacrm.php`): recorre todos los leads con `wacrm_conversation_id` + `responsible_user_id` y llama a `Client::assignConversation` para cada uno. Con `--account={uuid}` filtra a una cuenta. Barra de progreso + total OK/fallos. Uso: `php artisan komo:sync-assignments` — one-shot para migrar asignaciones existentes cuando se activa la restricción por primera vez.
- **Requisito operativo importante**: los agentes deben existir en AMBOS sistemas (Komo + wacrm) con **exactamente el mismo email** (la correlación es por email). La auto-provisión cubre esto automáticamente para agentes creados vía invitación; los agentes viejos (pre-Fase 12) hay que provisionarlos a mano (tinker: `\App\Models\User::create(...)` en ambas apps).
- **Trampa conocida**: si el `wacrm_conversation_id` de un lead apunta a una conversación que fue borrada/reseteada en el wacrm, el sync devuelve 404 "No query results for model [App\Models\Conversation]". Fix rápido: buscar la conversación actual por `phone_normalized` en el wacrm y actualizar el `wacrm_conversation_id` del lead.

## Fase 12 (2026-07-22) — Integración con Komo Invoice — suite 63/63 (284 aserciones)

Cierre del ciclo comercial: el lead ganado se conecta con Komo Invoice (5ª app del ecosistema, puerto 8004) para cotizar y cobrar. Cambios:

- Migración `2026_07_22_000001_add_invoice_fields_to_leads`: `leads.invoiced_cents` + `collected_cents` (revenue REAL cobrado, alimentado por Invoice via API).
- Migración `2026_07_22_000002_add_invoice_integration`: `integrations.invoice_url` + `invoice_api_key` (cifrada). El hub la cablea en la Fase 4 F4-Invoice.
- **`PATCH /api/v1/leads/{id}/revenue`** (scope `leads:write`) — Invoice lo llama al emitir factura y registrar pagos. Actualiza absolutos (no delta) para tolerar reenvíos. Cuando `collected_cents >= invoiced_cents > 0` notifica al owner (`lead_fully_paid`).
- **`Services\Invoice\Client`** (patrón wacrm) — usa `invoice_url`/`invoice_api_key` de `Integration`.
- **Botón "Cotizar"** en `Leads/Show` (`LeadController@createQuote`) → POST a Invoice `/api/v1/quotes` con contacto+lead pre-llenados → redirige al `edit_url` de la cotización en Invoice para terminarla.
- `ProvisionController` acepta bloque `invoice_integration` en el /provision — el hub lo cablea en su 3ª llamada al komo.
- Cotización creada registra evento `quote_created` en el timeline del lead.

## Fase 11 (2026-07-19) — Equipo centralizado — suite 63/63 (284 aserciones)

Fase 7 del Komo Hub: `ProvisionController` acepta `account_id` (uuid existente) + `account_role`. Si llegan, el user se une a la cuenta remota con ese rol sin sembrar pipeline extra; sin ellos, mantiene el comportamiento original (owner + pipeline por defecto via AccountProvisioner). Test `ProvisionMemberTest`.

## Fase 10 (2026-07-19) — Notificaciones consolidadas — suite 62/62 (279 aserciones)

Fase 5 del Komo Hub: **`GET /api/v1/notifications`** (`Api\NotificationApiController`, scope `notifications:read` añadido a `ApiKey::SCOPES`) devuelve las notifs del user dueño de la key con `link_path = /leads/{id}` (o `/notifications` si no hay `lead_id`), soporta `?since=` y `?limit=`. `SsoController@consume` acepta `?next=` (path relativo) para encadenar el salto con un deep-link.

## Fase 9 (2026-07-16) — Provisión del ecosistema — suite 61/61 (274 aserciones)

Fase 3 del Komo Hub: **`POST /api/v1/provision`** (`Api\ProvisionController`, sin api.key) firmado HMAC con `HUB_PROVISION_SECRET` (mismo valor en las 4 apps). Crea user+account con pipeline (AccountProvisioner, idempotente por email), emite API key con scopes y cablea la `Integration` con el wacrm (url+key+webhook_secret que manda el hub). Tests en `ProvisionTest`.

## Fase 8 (2026-07-16) — SSO del ecosistema — suite 58/58 (252 aserciones)

Fase 2 del **Komo Hub** (`C:\xampp_82_12\htdocs\laravel_nuevo_proyecto`, 4º proyecto): `SsoController@consume` (ruta pública `GET /sso/consume`, `APP_ID='komo'`) acepta tokens de un solo uso del hub — firma HMAC con `HUB_SSO_SECRET` (`.env` + `services.hub.sso_secret`, mismo valor en las 4 apps), expiración 60s, nonce anti-replay en cache, login por email. `SESSION_COOKIE=komo_session` en `.env`. Tests en `SsoConsumeTest`.

## Asignación de leads (2026-07-26) — round-robin sin admin + espejo en wacrm

Dos bugs de producción corregidos juntos porque son el mismo flujo (suite 77/77):

- **El round-robin ya no reparte al owner/admin.** `RoundRobin::ASSIGNABLE_ROLES` es ahora `[ROLE_AGENT]` (antes incluía owner y admin, por eso los leads entrantes caían en el Administrador). Viewer sigue fuera. **Si la cuenta no tiene ningún agente, el lead queda SIN responsable a propósito** — aparece como "sin asignar" para que un admin lo derive a mano; preferimos eso antes que volver a cargárselo al Administrador.
- **Toda asignación se espeja en la conversación del wacrm.** Antes el sync vivía en un método privado `LeadController@syncAssignmentToWacrm()` que **solo** corría en el cambio manual de la ficha: los leads auto-asignados por round-robin quedaban "Sin asignar" en el Inbox del wacrm. Ahora hay un único `Jobs\SyncLeadAssignmentToWacrmJob` (en cola, `tries=1`, falla silenciosa con Log::warning) invocado desde los **tres** caminos que cambian el responsable:
    - `Lead::booted()` created → tras el round-robin automático.
    - `LeadController@update` → cambio manual en la ficha.
    - `LeadController@bulk` acción `assign` → reasignación masiva (también le faltaba).
- **La correlación es por email** y ya no exige provisión previa: si el wacrm responde 422 `user_not_found`, el job da de alta al agente con `POST /team/provision` (rol `admin` si es owner/admin en Komo, si no `agent`) y reintenta el assign una vez. **La API key de la integración necesita el scope `team:write` además de `conversations:write`** — sin él el fallback no puede provisionar y la conversación se queda "Sin asignar".
- **`handle()` traga y loguea; `sync()` lanza.** El comando de reparación usa `sync()` para poder reportar de verdad qué leads fallaron; la cola usa `handle()` para que un wacrm caído no llene `failed_jobs`.
- **Red de seguridad**: `php artisan komo:sync-assignments` reenvía todas las asignaciones existentes al wacrm (útil para reparar los leads que quedaron desincronizados antes de este fix). Reusa el job, así que también auto-provisiona.
- Tests en `LeadAssignmentTest` (8): no asigna a owner/admin, sin agentes queda null, elige al agente con menos leads abiertos, los leads manuales no se reasignan, el espejo se dispara en automático y en manual, no llama al wacrm si el lead no tiene conversación, y el fallback de provisión + reintento.

## Seguimiento del admin (2026-07-26) — `/supervision`

Panel admin-only (`admin.only` en la ruta) para supervisar **el proceso**, no el resultado: Reportes ya mide ganados/conversión/embudo, esto mide si el equipo está atendiendo. `SupervisionController` + `Services\Supervision\ResponseMetrics` + `Pages/Supervision/Index.jsx`. Ventanas de 7/15/30/90 días por query `?days=`.

Todo sale de `lead_events` (`message_in`/`message_out`) — no consulta al wacrm. **Definiciones que hay que respetar si se toca el cálculo** (los tests de `SupervisionMetricsTest` las fijan):

- **La respuesta de la IA NO cierra la espera.** Para el admin lo relevante es si contestó un humano; la IA solo gana tiempo. Por eso un lead con auto-respuesta de IA y sin humano sigue contando como "esperando" y como "sin respuesta humana".
- **El reloj arranca en el PRIMER mensaje de la ráfaga.** Si el contacto manda cinco seguidos, esperó desde el primero, no desde el último.
- **Un saliente humano sin espera abierta es seguimiento proactivo, no respuesta** — no entra en los promedios.
- **"Quién contestó 1º"** distingue `responsable` de `otro_agente`: es lo que deja ver si el dueño del lead lo trabaja o se lo están cubriendo. Requiere saber _qué_ usuario mandó cada saliente.
- La ventana recorta los eventos: una conversación anterior al periodo se mide solo por lo que pasó dentro de él.

**Cambio en el wacrm que esto exigió**: `Messenger::dispatchOutbound()` ahora manda `sender_email` en el webhook `message.sent`. `EventProcessor::resolveSender()` lo resuelve a un `User` de la cuenta y lo guarda en `lead_events.user_id` (antes siempre null para salientes). Sin eso no se puede atribuir la respuesta a nadie. Para eventos viejos sin `sender_email` cae a coincidencia exacta de `sender_name` dentro de la cuenta — los que no matcheen quedan como `sin_identificar` (cuentan como respuesta humana para los tiempos, pero no se le adjudican a nadie).

## Avisos al equipo + modal de resultado (2026-07-26)

- **`prompt()` nativo eliminado al completar tareas.** Estaba en tres lugares (calendario y lista en `Tasks/Index.jsx`, ficha en `Leads/Show.jsx`) y se veía como un aviso del navegador. Ahora `Components/CompleteTaskModal.jsx`, montado **una sola vez en el layout** junto a `UndoToast` y disparado con `completeTask(task, { onCompleted })` — mismo patrón de API global (`let openFn`) que `showUndo`. Se hizo así porque los tres botones viven dentro de subcomponentes distintos y pasar el estado por props obligaba a encadenarlo tres niveles. Queda un `prompt()` en `Leads/Index.jsx` (nombre de lista guardada), fuera de este alcance.
- **`/team-messages`** (admin-only, `TeamMessageController`): notas y recordatorios del admin a uno o varios responsables. Aterrizan en `app_notifications`, así que el destinatario los ve en la campana y en `/notifications` sin tocar nada de la lectura.
    - **Apartados** (`AppNotification::CATEGORIES`): `seguimiento` | `personal` | `marketing`. Null en las notificaciones automáticas del sistema.
    - **Los recordatorios NO usan cola ni cron.** La fila se crea al instante con `deliver_at` a futuro y queda oculta hasta ese momento. **Toda lectura de notificaciones DEBE pasar por el scope `AppNotification::delivered()`** — están cubiertos los tres caminos (contador de la campana en `HandleInertiaRequests`, listado en `NotificationController@index`, y acceso directo en `@go`, que devuelve 404 si aún no toca). `markAllRead` también lo filtra: marcar leído un recordatorio futuro lo dejaría invisible para siempre.
    - **`batch_id`**: un envío masivo son N filas idénticas salvo el destinatario; el batch las reagrupa en el historial del admin ("1 aviso a 4 personas") sin adivinar por título + timestamp.
    - Migración `2026_07_26_000007`: `category`, `deliver_at`, `sent_by_user_id`, `batch_id`, y `body` pasa de `varchar(255)` a `text` (corto para una nota redactada a mano).
    - Atajos desde `/supervision`: botón "Enviar aviso" en el header y un ícono por fila que preselecciona a ese responsable (`?to=<userId>`).
    - Tests en `TeamMessagesTest` (9), con un caso por cada camino de lectura del recordatorio pendiente.
- **`/notifications` con pestañas** (`Todas` / `Nuevas` / `Leídas`) + filtro por apartado. **El filtrado va en el servidor**, no en el cliente: con paginación, filtrar la página ya traída deja pestañas vacías aunque haya resultados en la siguiente. Los contadores por apartado se calculan **dentro de la pestaña activa** para que los números cuadren con lo que se ve. Nueva ruta `POST /notifications/{notification}/read` (`notifications.read`) que alterna leída/nueva de a una — antes un aviso sin lead asociado solo se podía marcar con "marcar todas". Layout de una sola columna agrupado por Hoy / Ayer / Esta semana / Anteriores (el grid de 2-3 columnas anterior obligaba a leer en zigzag). Tests en `NotificationTabsTest` (7).

## IA: sin mensaje de relleno al cliente + indicador en el header (2026-07-26)

- **El wacrm ya NO le manda nada al cliente cuando la IA falla.** Antes enviaba "Un asesor te atenderá en breve": delataba que había un bot y dejaba una respuesta de relleno en lugar de una real. Ahora la conversación queda intacta, la IA se apaga en ese chat y **el aviso al responsable es el único canal** — si se pierde, el contacto queda sin respuesta.
- **Evento `ai.unavailable`** (wacrm → Komo, `EventProcessor@handleAiUnavailable`) con `reason` `failed` | `limit_reached`. Crea una `AppNotification` para el **responsable del lead** (owner si no tiene). Tipos `ai_unavailable` / `ai_limit_reached`. Tests en `AiUnavailableTest`.
- **Indicador de IA en el header**, al lado de la campana (`AiStatusBadge` en `AuthenticatedLayout`). Sale del prop compartido `aiStatus`, que es **lazy** (`fn () => …`) y está **cacheado 2 min**: es un HTTP al wacrm y no puede colgar el render de cada pantalla. Si el wacrm no responde, muestra "Sin conexión" en vez de tumbar la página. Estados: IA activa / apagada / manual / caída / fuera de horario / sin conexión / sin configurar.
    - Lo alimenta `GET /api/v1/ai/status` en el wacrm (scope `conversations:read`), que **comprueba de verdad que Ollama responda** (`/api/tags`, cacheado 60s allá). "Disponible" no es solo que el toggle esté encendido: con Ollama caído la IA está configurada pero no contesta.
- **Ojo con el tope por conversación**: `InboundProcessor` resetea `ai_reply_count` a 0 en **cada mensaje entrante del cliente**, así que el "Máximo N respuestas por conversación" de Ajustes en la práctica solo limita ráfagas seguidas del bot, no la conversación entera. El aviso `limit_reached` existe y funciona, pero casi nunca se dispara con ese reset puesto. Decidir si el tope debe ser por conversación de verdad (quitar el reset) o si la UI debería decir otra cosa.

## Ventana de servicio de WhatsApp (2026-07-26) — control de gasto

`Services\WhatsApp\ServiceWindow` calcula cuánto queda para escribirle a un contacto **sin que Meta cobre**. Existe el gemelo `Services\WhatsApp\ServiceWindow` en el wacrm con el mismo cálculo — **si cambia una regla hay que tocar los dos** (y sus dos `ServiceWindowTest`).

Las reglas de Meta que implementa. **Las dos ventanas NO se comportan igual — confundirlas cuesta plata:**

- **24 h de servicio — SE REINICIA con cada mensaje.** Cada entrante del cliente abre/renueva 24 h de texto libre gratis contadas desde ese mensaje. Vencidas, solo se puede escribir con plantilla aprobada — y eso se factura.
- **72 h de free entry point — NO se reinicia.** Corren desde el clic en el anuncio Click-to-WhatsApp y punto: que el cliente siga escribiendo no las estira. Dentro de esas 72 h **todo es gratis, incluidas las plantillas**. Solo un clic NUEVO en un anuncio abre otras 72 h — por eso se toma `MAX(created_at)` de los entrantes con referral, no el primero.
- **Corren en paralelo, vale la que venza más tarde.** El caso que hay que tener claro: el cliente toca el anuncio y escribe recién en la **hora 71**; al vencer las 72 h la conversación NO se corta, quedan las 24 h estándar desde su último mensaje — o sea hasta la **hora 95**. Por eso no alcanza con mirar el último mensaje, ni con mirar solo el anuncio: se toma el máximo de las dos.
- Los cuatro casos límite están fijados en `ServiceWindowTest`: la hora 71, que las 72 h no se reinician al escribir, que un clic nuevo sí abre otras 72 h, y el cruce inverso (mensaje reciente que gana a un anuncio por vencer).

Detalles de implementación:

- Komo calcula desde `lead_events` (no consulta al wacrm ni a Meta), así que sirve en listados sin costo de red. `forLeads()` hace 2 queries para todos los leads en vez de 2 por lead.
- El entrante guarda `payload.ad_referral` (bool) desde `EventProcessor`. Para leads anteriores a ese cambio hay **fallback**: si el lead tiene `source_ref` se usa su `created_at` como momento del clic.
- Se muestra en `/inbox` y `/leads` (badge compacto) y en la ficha del lead y del contacto (`ServiceWindowCard` con origen, cuenta regresiva, barra y fechas). En el wacrm, en el header del chat y en la lista de conversaciones.
- Verde / ámbar (< 4 h) / rojo (cerrada). El rojo es el que importa: ahí escribir cuesta plata.

## Provisión de miembros desde el wacrm (2026-07-27)

`POST /api/v1/team/provision` (scope **`team:write`**, nuevo en `ApiKey::SCOPES`). Cierra el puente de usuarios, que era de ida solamente: Komo creaba el user en el wacrm al aceptar una invitación, pero un miembro dado de alta **allá** no existía acá — y acá es donde se asignan los contactos.

Idempotente por email. **No pisa el password** si el usuario ya existe: si el miembro entró y lo cambió, una re-provisión no debe revertirlo. 409 si el email pertenece a otra cuenta.

**Trampa operativa — las contraseñas no viajan hacia atrás.** Los miembros que llegan por `wacrm:sync-team-to-komo` (el backfill de los que ya existían) se crean con clave **aleatoria**: las del wacrm están hasheadas y no se pueden reenviar. Y el correo de producción sigue en driver `log`, así que el "olvidé mi contraseña" no llega. Para eso está `php artisan komo:set-password EMAIL [--password=]` — sin argumentos lista los miembros. **Los creados desde el modal "Crear miembro sin link" del wacrm sí comparten contraseña**, porque ahí se manda en claro en el momento del alta.

Utilidades: `php artisan komo:api-key --list` (cuentas con su UUID) y `komo:api-key "wacrm" --account=UUID --scopes=team:write` para generar la key sin pasar por la UI — la clave en claro se muestra una sola vez.

## Ficha del Agente / Responsable (2026-08-06) — drill-down desde `/supervision`

La ficha individual que quedaba pendiente ya está construida. Objetivo cubierto: entrar a un responsable y ver SU proceso y SUS pendientes. Todo se calcula con datos locales (`lead_events`, `leads`, `tasks`, `ServiceWindow`) — **no se consulta al wacrm para nada de esto**.

Ruta y control de acceso:

- `GET /supervision/agents/{user}` (nombre `supervision.agent`), dentro del grupo `admin.only`. En el controlador además `abort_unless($user->account_id === $viewer->account_id, 403)`.
- Entrada: cada fila de `Supervision/Index.jsx` ahora es **clicable** hacia la ficha del responsable (el nombre es un `Link`; el chevron sigue expandiendo la fila).
- Ventana por `?days=` (7/15/30/90, default 30), mismo patrón del index.

Backend — **no se extiende `ResponseMetrics`**: tiene gemelo con sus propios tests y sus definiciones. Se agregaron métodos en `Services\Supervision\ResponseMetrics` que **reusan los mismos recorridos** de `build()`:

- `forResponsible($userId)` → perfil del agente + KPIs (bucket del agregador) + `histogram()` + serie diaria + `leads` (filas de ese responsable).
- `conversionFor($userId)` → ganados/perdidos/% conversión/ticket promedio, cerrados sobre `closed_at` en el periodo.
- `operativesFor($userId)` → los **pendientes de AHORA**: leads esperando respuesta (último evento = `message_in`), ventana de servicio cerrada, leads estancados > 7 días en la misma etapa, y el **conteo de tareas vencidas** (`Task::pending()->overdue()`).

Página `Pages/Supervision/Agent.jsx`:

1. KPIs del periodo (proceso): conversaciones @ atendidos %, esperando/SLA, 1ª respuesta, respuesta más lenta.
2. KPIs de cierre del ciclo: **ganados / perdidos / % conversión / ticket promedio**.
3. Histograma de primera respuesta humana (baldes <1 m, 1-5, 5-15, 15-30, 30 m-1 h, >1 h) + embudo de sus leads abiertos por etapa (acumulado) + volúmenes diario y respuesta/SLA.
4. Tabla **Pendientes operativos**: leads unificados con badges de motivo (Esperando respuesta / estancado / Ventana cerrada), ventana de servicio, link a `leads.show`, conteo de tareas vencidas y botón ** "Enviar aviso"** que preselecciona al responsable en `/team-messages?to={id}`.

Tests `Tests/Feature/SupervisionAgentDetailTest` (nuevo):
- 403 para un agente (no-admin) y para un admin de otra cuenta.
- La ficha carga con `agent.name`, `kpis`, `histogram`, `leads`, `conversion`, `operatives` y `sla_minutes`.

No requiere migraciones. Suite total de supervisión: **14/14 en verde**.

De la especificación original hay dos extremos que no se implementaron (se documentan como futuro): la **serie semanal** (creados/ganados/perdidos por semana) y la **posición en el ranking** del responsable dentro del periodo. No se to me el wacrm: `lead_events.user_id` ya viene poblado y el webhook `message.sent` manda `sender_email`.

## Pendiente (futuro, no bloquea)

Email SMTP/IMAP (módulo grande; requiere credenciales reales), calendario visual de tareas, tiempo real con Reverb (hoy sin polling — Inertia recarga por navegación).
