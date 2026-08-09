# Komo (ESAM HUB) — Copiloto, widgets, workflows, segmentos y feedback de IA

## Instrucciones de trabajo para el agente

> **Objetivo:** cinco capacidades nuevas que convierten al CRM de *registro* en *asistente*: que priorice solo (score), que cada quien vea su tablero (widgets), que ejecute procesos de varios pasos (workflows), que arme audiencias vivas (segmentos) y que la IA aprenda de las correcciones del equipo (feedback).
>
> **No se ejecutan las cinco de una.** El orden de abajo respeta dependencias reales: T0 desbloquea T4, T1 alimenta T2 y T4, y T5 depende de trabajo en el **otro repo**. Una tarea = un commit; una tarea = una ronda desplegable.

---

## 0. Reglas del proyecto (lectura obligatoria)

- Stack: Laravel 13 + Inertia + React 18 + Vite, MariaDB. `npm install` **siempre** con `--legacy-peer-deps`. Puerto local 8001.
- Build de producción en el servidor (`/public/build` en `.gitignore`).
- Multi-tenant por `account_id` (trait `BelongsToAccount`), PKs UUID.
- Estilo Velzon vigente (cards `rounded-2xl shadow-sm border-gray-100`, gradiente `from-[#045474] to-[#1c486c]`). Semántica de color: emerald = positivo, amber = advertencia, rose = peligro, purple = IA.
- Gráficos: usar la capa de `@/Components/Charts` (Paso 0 de `mejoras.md`), no recharts pelado.
- Filtros server-side con query params. Prohibido filtrar en cliente datos paginados.
- Roles: `admin.only` donde corresponda; el `agent` ve solo lo suyo, **cortado en el servidor**.
- Tests feature por endpoint; `php artisan test` en verde. Local: `serve --port=8001` + `queue:work` + `schedule:work`.

### Lo que ya existe y hay que reusar (no reinventar)

| Pieza | Dónde | Sirve para |
|---|---|---|
| `Services\Supervision\ResponseMetrics` | GEMELO del wacrm | señales de atención (espera, 1ª respuesta) |
| `Services\Supervision\TeamComparison` | Komo | agregados de equipo, backlog |
| `Services\WhatsApp\ServiceWindow` | Komo | si se le puede escribir gratis |
| `Services\WhatsApp\MessagingCost` | Komo | costo estimado de un envío |
| `Services\DigitalPipeline\{Runner,Simulator,Recipes}` | Komo | base de T3 |
| `App\Jobs\*` + `queue:work` + `schedule:work` | Komo | ejecución diferida |
| `saved_segments` (tabla + modelo + controller) | Komo | base de T4 |
| `Automation`/`AutomationStep`/`AutomationPendingExecution` | **wacrm** | **forma** del motor de T3 |
| `AiConfig`/`AiKnowledgeChunk`/`AiReplyAttempt` | **wacrm** | destino del feedback de T5 |

---

## 1. P0 · T0 — Unificar el filtrado de leads (prerequisito de T4, habilitador de T1)

**El problema ya está en producción, no es preparativo teórico.** La misma cadena de filtros está escrita dos veces y **ya divergió**:

- `LeadController@index` acepta `stage_id`, no acepta `tags[]` ni `include_closed`.
- `BroadcastController@recipientPhones` acepta `tags[]` e `include_closed`, no acepta `stage_id`.
- `saved_segments.filters` guarda un JSON que ambos interpretan **distinto**: una lista guardada desde `/leads` con `stage_id` se ignora en silencio al usarla en un broadcast.

**Trabajo:**

1. `Services\Leads\LeadFilter` (clase nueva): recibe `array $filters` + el usuario, devuelve un `Builder` ya scopeado por cuenta y por rol. Único lugar donde vive el `when()` de cada criterio.
2. `LeadController@index`, `LeadController@export`, `BroadcastController@{preview,store}` y `SavedSegmentController` pasan a usarla.
3. Contrato explícito de `filters` documentado en el docblock de la clase + validación: una clave desconocida **falla en tests**, no se ignora.
4. Test de regresión: la misma definición de segmento da el **mismo conteo** en `/leads` y en la vista previa del broadcast.

**Criterio de aceptación:** ningún `when($filters[...])` fuera de `LeadFilter`. Tamaño: **S**.

---

## 2. P0 · T1 — Copiloto: scoring predictivo explicable

> ⚠️ **Acá es donde este proyecto puede mentir más fácil.** No hay infraestructura de ML ni datos etiquetados. Un "87% de probabilidad de cierre" salido de pesos inventados se lee como ciencia y es decoración. El plan es deliberadamente conservador: **primero un score explicable y calibrado con la propia historia de la cuenta**, y recién después —si el volumen lo justifica— un modelo de verdad.

### T1.a — Motor de señales (`Services\Copilot\LeadSignals`)

Señales que salen de datos que **ya existen**, sin migraciones nuevas:

| Señal | Fuente | Por qué predice |
|---|---|---|
| Días desde el último `message_in` | `lead_events` | un lead frío no cierra |
| Mediana de respuesta al lead | `ResponseMetrics` | mal atendido = se pierde |
| Mensajes entrantes totales | `lead_events` | interés real del cliente |
| Días estancado en la etapa | `lead_events.stage_changed` | pipeline trabado |
| Avance neto de etapas | `lead_events` | ¿progresa o retrocede? |
| Tarea pendiente sí/no | `tasks` | la regla Kommo del lead olvidado |
| Tasa histórica de la fuente | `leads` agregado | de dónde vienen los que cierran |
| Valor vs. ticket promedio | `leads` | los grandes se trabajan distinto |
| Ventana de servicio | `ServiceWindow` | ¿se le puede escribir hoy? |

### T1.b — Score con bandas por percentil

- Pesos **fijos y documentados** en una constante, no mágicos. Cada uno con su justificación en comentario.
- El score crudo se convierte en banda (`caliente`/`tibio`/`frío`) por **percentil dentro de la cuenta**, no por umbral absoluto: 60 puntos significa cosas distintas en una cuenta con 40 leads y en una con 4.000.
- **Calibración honesta:** el motor calcula, contra los leads ya cerrados de la cuenta, qué % de los que estaban en cada banda terminó ganado. Ese número se muestra en la UI ("de los que estuvieron en 'caliente', cerró el 34%"). Si hay **menos de 200 cerrados**, la banda se muestra como **«sin calibrar»** y se dice en pantalla. Nunca se inventa un porcentaje.
- Migración mínima: `leads.score` (int), `leads.score_band` (string), `leads.score_factors` (json), `leads.scored_at`. Los factores se guardan **con el score** porque un score sin el "por qué" no se acciona ni se audita.
- `RecalculateLeadScoresJob` nocturno + recálculo puntual al cambiar de etapa o entrar un mensaje.

### T1.c — Capa prescriptiva («qué hago ahora»)

Reglas sobre las señales, no texto generado. Cada sugerencia dice **el motivo** y ofrece **la acción en un clic**:

- «Esperando hace 3 h» → botón *Responder* (abre el chat).
- «Ventana cierra en 2 h» → *Escribir ahora* (con el costo si se pasa).
- «Sin tarea hace 12 días» → *Agendar seguimiento*.
- «Estancado en Negociación 9 días» → *Mover etapa* / *Marcar perdido*.
- «Score cayó de caliente a frío» → *Revisar*.

**Dónde vive:** panel «Copiloto» en `Leads/Show.jsx` + widget de "Prioridades de hoy" (T2) con los 10 leads de mayor score que además tienen una acción pendiente.

### T1.d — Resumen en lenguaje natural (OPCIONAL, detrás de flag)

Llamada al LLM del wacrm para redactar 2 líneas de contexto del lead. **Fuera del alcance inicial**: cuesta tokens por lead, depende de T5 y no aporta nada que las señales no digan ya. Dejar la interfaz preparada, no implementarla en esta ronda.

**Criterio de aceptación:** un lead sin actividad no rompe el score (devuelve banda «sin datos», no 0); un agent ve solo sus leads en el ranking; los factores se ven en pantalla. Tamaño: **L**.

---

## 3. P1 · T2 — Panel ejecutivo personalizable (widgets)

**Hoy** `DashboardController@index` calcula **todo** para todos en cada carga (8+ agregados, `computeUrgentLeads` trae todos los eventos de mensaje de la cuenta). Widgetizar no es solo UX: es dejar de calcular lo que nadie mira.

1. **Registro de widgets en el servidor** (`Services\Dashboard\WidgetRegistry`): cada widget declara `key`, `label`, `descripción`, `adminOnly`, `tamaños permitidos` y un **resolver**. El controller resuelve **solo los widgets activos del usuario**. Un widget `adminOnly` no se resuelve nunca para un agent — no se oculta en el cliente.
2. **Migración** `dashboard_widgets`: `account_id`, `user_id`, `widget_key`, `position`, `size` (`sm|md|lg`), `config` (json), `is_visible`. Sin fila = layout por defecto según rol.
3. **Widgets iniciales** (los actuales, ya troceados): KPIs con delta, leads urgentes, olvidados, mis tareas, leads recientes, embudo, tendencia semanal, ranking de equipo (admin), backlog SLA (admin), **prioridades del copiloto** (T1).
4. **Frontend**: grilla con drag & drop y selector de widgets. Usar `@dnd-kit` (`--legacy-peer-deps`). Persistir con un `PATCH` debounced, no en cada píxel.
5. **Reset a por defecto** siempre visible: un tablero que el usuario rompió y no sabe restaurar es peor que uno fijo.

**Trampa:** el layout es **por usuario**, no por cuenta. Si un admin acomoda su tablero no puede moverle el de nadie más. Un layout "de cuenta" como plantilla inicial se puede sumar después.

**Criterio de aceptación:** el dashboard no ejecuta la query de un widget desactivado (verificable con `DB::listen` en el test). Tamaño: **M**.

---

## 4. P1 · T3 — Workflows: automatización de procesos complejos

**Hoy** `StageAutomation` es plano: un disparador (entrar a etapa), 3 acciones (`send_whatsapp`, `create_task`, `add_note`), sin esperas, sin condiciones, sin ramas, ejecución inmediata.

**El wacrm ya resolvió esto** (`Automation` + `AutomationStep` con árbol + `AutomationPendingExecution` para los `wait` + `Conditions::evaluate()` + `Simulator`). **Reusar la forma, no el archivo**: el dominio es distinto (leads vs. conversaciones) y copiar el código crearía un **tercer gemelo** que habría que mantener sincronizado a mano. Se documenta como paralelo deliberado.

### Esquema

- `workflows`: `account_id`, `name`, `trigger_type`, `trigger_config` (json), `is_active`, `execution_count`.
- `workflow_steps`: árbol con `parent_id` + `branch` (`yes|no|null`), `position`, `step_type`, `config`.
- `workflow_runs`: `workflow_id`, `lead_id`, `status`, `started_at`, `finished_at`.
- `workflow_run_events`: traza paso a paso (**imprescindible para depurar**: hoy un fallo de automatización solo deja un `Log::warning` que nadie lee).
- `workflow_pending_executions`: los `wait` pendientes, barridos por el scheduler.

### Disparadores (dominio de leads)

`lead_created`, `stage_changed`, `tag_added`, `value_changed`, `status_changed` (ganado/perdido), `task_overdue`, `no_activity_for` (N días), `form_submitted`, `booking_created`, `segment_entered` (une con T4), `score_band_changed` (une con T1).

### Pasos

`send_whatsapp`, `create_task`, `add_note`, `add_tag`, `remove_tag`, `change_stage`, `assign_responsible`, `notify_user`, `webhook`, `wait`, `condition` (rama sí/no).

### ⚠️ Guardarraíles — la parte que hay que hacer *primero*, no al final

Un motor con `change_stage` + disparador `stage_changed` **se llama a sí mismo**. Con `send_whatsapp` adentro, eso es una tormenta de mensajes a clientes reales y una factura de Meta. Antes de habilitar el primer workflow:

1. **Tope de pasos por corrida** (p. ej. 50) → la corrida se marca `failed` con motivo.
2. **Detección de reentrada**: un lead no puede entrar dos veces al mismo workflow en N minutos.
3. **Tope de corridas por lead y por día**.
4. **Idempotencia** de los pasos que mandan mensajes (clave por `run_id` + `step_id`).
5. **Modo borrador obligatorio**: un workflow nace inactivo y **no se puede activar sin pasar por el simulador**.
6. **Kill switch por cuenta**: un botón que para todo, sin deploy.

### Migración de lo existente

`stage_automations` sigue vivo y en uso. Plan: convertirlas a workflows de un solo paso con disparador `stage_changed` mediante migración de datos, y que la pantalla actual de Digital Pipeline lea del motor nuevo. **La pantalla vieja no se rompe.** Test que compara el comportamiento antes/después con las mismas automatizaciones.

**Criterio de aceptación:** el simulador recorre el árbol en pantalla sin escribir nada (mismo criterio que el del wacrm); los guardarraíles tienen test propio **antes** de que el motor mande el primer WhatsApp. Tamaño: **XL** — es la tarea más grande y la más peligrosa; va sola en su ronda.

---

## 5. P1 · T4 — Segmentación dinámica de audiencias

Depende de **T0**. Hoy `saved_segments` guarda un JSON plano de 6 claves y se interpreta distinto en cada pantalla.

1. **Criterios ricos con grupos AND/OR** (`filters` pasa a un árbol versionado, con `version` para poder migrar sin romper las listas guardadas):
    - Atributos: etapa, pipeline, fuente, `utm_*`, responsable, etiquetas, valor, moneda, empresa, campos personalizados.
    - **Comportamiento**: sin actividad hace N días, respondió/no respondió, ventana de servicio abierta, cantidad de entrantes, tiene/no tiene tarea pendiente.
    - **Copiloto (T1)**: banda de score, score subió/bajó.
    - Temporales: creado/cerrado en el periodo.
2. **Dinámico = el segmento es una consulta, no una lista.** Se evalúa al momento de usarlo. **El broadcast sigue congelando destinatarios al enviar** (eso ya está bien y no se toca: quién recibió qué es un hecho histórico, no una consulta).
3. **Vista previa con lo que ya existe**: conteo, cuántos dentro y fuera de ventana, y **costo estimado** de los de afuera (`MessagingCost`). Ya está construido en `BroadcastController@preview` — se reusa, no se duplica.
4. **Evolución del tamaño del segmento** en el tiempo (mini-serie con la capa de gráficos).
5. **Une con T3**: disparador `segment_entered` → un lead que entra al segmento arranca un workflow. Acá es donde "dinámico" deja de ser una etiqueta de marketing y hace algo.

**Guardarraíl:** un segmento que crece solo + un workflow que manda WhatsApp = envío masivo no supervisado. `segment_entered` exige confirmación explícita al activarse y respeta los topes de T3.

**Criterio de aceptación:** una lista guardada con la estructura vieja sigue funcionando (migración de `filters` con `version`). Tamaño: **M** (con T0 hecho).

---

## 6. P2 · T5 — Mejora continua de la IA con feedback

> ⚠️ **Esta tarea NO se puede completar solo en Komo.** La IA vive en el wacrm: `AiConfig`, `AiKnowledgeDocument`, `AiKnowledgeChunk`, `AiReplyAttempt` y el `pinned_knowledge`. Komo *muestra* los mensajes de la IA, no los genera. Sin un canal de vuelta, capturar feedback en Komo es un formulario que no alimenta nada.

**Reparto:**

### En Komo
1. Migración `ai_feedback`: `account_id`, `lead_id`, `lead_event_id`, `user_id`, `rating` (`up|down`), `correction` (texto, opcional), `created_at`.
2. UI: 👍/👎 + «corregir» en cada mensaje de la IA del chat del lead (`Leads/Show.jsx`). El agente que ve la respuesta mala es quien tiene el contexto para arreglarla — capturarlo ahí y no en una pantalla de configuración es todo el punto.
3. `SendAiFeedbackJob` → `POST /api/v1/ai/feedback` del wacrm (**endpoint nuevo, no existe**). Reintentos y tolerancia a que el wacrm esté caído.

### En el wacrm (repo `laravel_crm_whatsapp`)
4. Endpoint `POST /api/v1/ai/feedback` que ata el feedback al `AiReplyAttempt` correspondiente.
5. **Cola de revisión** en `/settings/ai`: un admin ve las correcciones y decide cuál se convierte en conocimiento (`AiKnowledgeChunk` / `pinned_knowledge`).
6. En `/settings/ai/stats`: tasa de 👎 y de fallbacks **en el tiempo**, para ver si el ciclo mejora algo o solo genera trabajo.

### ⚠️ La cola de revisión es obligatoria, no un lujo
Enchufar correcciones directo a la base de conocimiento la envenena: un agente apurado escribe algo mal y la IA lo repite a todos los clientes. **Ningún texto entra al conocimiento sin que un humano lo apruebe.** Si se recorta alcance, se recorta otra cosa.

**Coordinación:** exige deploy de los dos repos. El endpoint del wacrm va **primero** (Komo tolera su ausencia; al revés no).

**Criterio de aceptación:** con el wacrm apagado, Komo guarda el feedback y lo reintenta; nada se pierde. Tamaño: **M** en Komo + **M** en el wacrm.

---

## 7. Orden sugerido y dependencias

```
T0 (filtros)  ──┬──> T4 (segmentos) ──┐
                │                     ├──> T3 (workflows: segment_entered)
T1 (copiloto) ──┴──> T2 (widgets)     │
                     └────────────────┘
T5 (feedback IA) — independiente, pero exige trabajo en el wacrm
```

| Ronda | Tarea | Tamaño | Por qué en ese orden |
|---|---|---|---|
| 1 | T0 | S | arregla un bug real y desbloquea T4 |
| 2 | T1 a/b/c | L | alimenta widgets y segmentos |
| 3 | T2 | M | valor visible pronto, riesgo bajo |
| 4 | T4 | M | ya con T0 y T1 hechos |
| 5 | T3 | XL | va sola; guardarraíles antes que funciones |
| 6 | T5 | M+M | cross-repo, cuando lo demás esté estable |

**Recomendación:** no arrancar T3 hasta que T0–T2 estén desplegadas y en uso. Es la única de las cinco que puede causar daño hacia afuera (mensajes a clientes reales).

---

## 7.b Ronda siguiente (planificar al cerrar T0–T5, no antes)

Tres módulos acordados para después de esta tanda. Se listan ahora para que las decisiones de T0–T5 no los bloqueen; **el plan detallado de cada uno se escribe cuando la tanda anterior esté desplegada**, no ahora.

### T6 — Email SMTP/IMAP
Módulo grande y de naturaleza distinta a todo lo anterior: es un **canal nuevo**, no una vista sobre datos existentes. Puntos que hay que resolver antes de escribir una línea:
- **Requiere credenciales reales** para probarse de punta a punta. Nada de esto se valida con datos de prueba: hace falta una casilla dedicada y decidir OAuth (Google/Microsoft) vs. contraseña de aplicación.
- Almacenamiento de credenciales por cuenta cifrado en reposo; **nunca en el repo ni en `.env` compartido**.
- IMAP es *polling* con estado (UIDVALIDITY, UIDNEXT): exige job dedicado, no `schedule` ingenuo.
- Hilos: atar mensajes a leads por `Message-ID`/`In-Reply-To`, no por asunto.
- Decidir si el email entra al timeline del lead como `message_in`/`message_out` (reusa todo lo de supervisión y copiloto) o como tipo de evento propio. **Esa decisión conviene tomarla temprano** porque cambia qué mide `ResponseMetrics`.
- Rebotes, quejas de spam y baja obligatoria si se usa para envíos.

### T7 — Calendario visual de tareas
El más chico de los tres. `tasks` ya tiene `due_at`, `assigned_to`, `task_type` y `completed_at`: es una vista nueva sobre datos que ya están.
- Vista mes/semana/día con arrastrar para reprogramar (`PATCH` de `due_at`).
- Filtro por responsable (admin) y por tipo de tarea; el agent ve las suyas.
- Se cruza con `BusinessHours\Schedule` (ya existe) para pintar el horario laboral.
- Ojo con la zona horaria: hoy todo se guarda en UTC y se muestra con `translatedFormat`; un calendario expone cualquier inconsistencia que hoy pasa desapercibida.

### T8 — Tiempo real con Reverb
**Estado actual: no hay polling ni websockets.** La UI se actualiza porque Inertia recarga al navegar. Eso significa que hoy un mensaje entrante no aparece hasta que alguien cambia de pantalla.
- Reverb + Echo, canales privados **por cuenta** (`account.{id}`) y por lead; autorización en `routes/channels.php` con el mismo corte de rol del resto.
- Candidatos naturales: mensajes entrantes en el chat del lead e Inbox, notificaciones, tablero de leads al moverse una tarjeta, contadores del dashboard.
- Requiere proceso extra en el servidor (`reverb:start` como servicio, igual que la cola) y WebSocket a través del proxy — hay que tocar Nginx.
- **Decisión previa:** qué se emite por websocket y qué sigue viniendo por navegación. Emitir todo es la forma fácil de duplicar la lógica de permisos en el canal y filtrarla mal.

---

## 8. Advertencias duras

- **GEMELO:** `Services\Supervision\ResponseMetrics` es idéntico al del wacrm y sus definiciones están fijadas por `SupervisionMetricsTest`. T1 lo **consume**, no lo modifica. Si hiciera falta una definición nueva, va en clase nueva (patrón de `TeamComparison`).
- **No crear un tercer gemelo:** T3 reusa la *forma* del motor del wacrm, no sus archivos. Documentarlo como paralelo deliberado en `CLAUDE_komo.md`.
- **Nada de scores inventados:** si no hay datos para calibrar, la UI lo dice. Un porcentaje falso destruye la confianza en todo el módulo.
- **T3 y T4 juntos pueden mandar WhatsApp masivo sin supervisión.** Topes, idempotencia y kill switch antes que cualquier función nueva.
- Agregaciones con `GROUP BY`, sin N+1. Si pesan, caché corta (60-300 s) invalidada por huella.
- El scoring y los workflows **escriben**: a diferencia de la ronda de `mejoras.md` (solo lectura), acá sí se tocan datos. Cada job debe ser idempotente y reintentable.

## 9. Definición de terminado (por tarea)

- [ ] Scope de rol cortado en el servidor.
- [ ] Migraciones reversibles (`down()` real) y datos existentes migrados sin romper pantallas.
- [ ] Jobs idempotentes, con reintentos y tolerantes a que el wacrm no responda.
- [ ] Tests feature nuevos + suite completa en verde.
- [ ] Sin `confirm()` nativos; sin filtrado cliente de datos paginados.
- [ ] Estados vacíos y «sin datos» explícitos, nunca ceros que mientan.
- [ ] Commit único por tarea + entrada en `CLAUDE_komo.md` con las trampas encontradas.

## 10. Despliegue (al cerrar cada ronda)

```bash
cd /var/www/crm-komo && git pull origin main && php artisan migrate --force && npm ci --legacy-peer-deps && npm run build && php artisan optimize:clear
sudo systemctl restart crm-komo-queue.service   # obligatorio en T1, T3 y T5 (jobs nuevos)
```
