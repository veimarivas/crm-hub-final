# PLAN — Deduplicación wacrm ↔ Komo

Documento de trabajo para el agente. Se ejecuta por fases (D1→D5). Cada fase es un commit
independiente que deja **las dos suites** en verde y actualiza los `CLAUDE_*.md` de ambos
proyectos (convención existente: documentar rondas, trampas y tests).

**Regla de oro:** una fase no se mezcla con la siguiente. Si aparece trabajo de otra fase,
se anota acá y se descarta del commit.

**Relación con `plan_omnicanal.md`:** este plan es **previo**, no paralelo. F0 del omnicanal
asume que los gemelos se mantienen a mano y que cada canal nuevo toca los dos repos. Si D3 y
D4 entran antes de F0, `ChannelRules` nace en un solo lugar; si entran después, se duplica
tres veces más (F1 Telegram, F2 Messenger/IG, F4 SMS/WebChat). Ver §6.

---

## 0. Contexto

| Proyecto | Rol | Producción |
| --- | --- | --- |
| **wacrm** (`laravel_crm_whatsapp`) | Motor de mensajería, flows, automatizaciones, IA, broadcasts | `crm-whatsapp.posgradosinnovaciencia.com` |
| **Komo** (`laravel_komo_crm`) | Leads, pipeline, tareas, segmentos, workflows, supervisión, reportes | `komo.posgradosinnovaciencia.com` |

Los dos nacieron del mismo esqueleto (Laravel 13 + Inertia/React 18 + MariaDB, Breeze,
multi-tenant por `account_id`). Komo se creó copiando la base del wacrm, y desde entonces
las dos copias evolucionaron por separado.

---

## 1. Inventario medido (no estimado)

Comparación de rutas de archivo idénticas en `app/` y `resources/js/` de ambos repos:

**99 archivos comparten ruta exacta. 36 son byte-idénticos, 63 ya divergieron.**

La cifra que importa es la segunda: no es que haya duplicación, es que **la duplicación ya
se está separando sola y nada lo detecta**.

### 1.a Duplicación de plataforma (mismo propósito, cero razón para ser dos)

| Bloque | Archivos | Estado |
| --- | --- | --- |
| Breeze (8 controllers `Auth/`, `LoginRequest`, 6 páginas) | 15 | idéntico salvo `Login.jsx` y `RegisteredUserController` |
| Componentes UI (`Modal`, `Dropdown`, `TextInput`, botones, `NavLink`, `Checkbox`…) | 14 | idéntico salvo `Modal.jsx` |
| Hooks en vivo (`useLiveBoard`, `useBoardActivity`, `LiveIndicator`, `LiveInbound`) | 4 | idéntico salvo `LiveInbound.jsx` |
| **Capa de gráficos** (`Components/Charts/*`) | 11 | **8 de 11 divergidos** |
| `BelongsToAccount`, `ApiKey`, `AuthenticateApiKey`, `SsoController` | 4 | difieren solo en el literal `wacrm`/`komo` |
| `Controller`, `ProfileController`, `ProfileUpdateRequest`, perfil | 6 | 3 idénticos |

**La capa de gráficos es el caso testigo.** Se creó explícitamente como «una sola» en la ronda
de analítica (ver `CLAUDE_komo.md`, «Paso 0 — capa de gráficos»), y en un mes ya hay dos
`format.js`, dos `chartTheme.js` y dos `TrendArea.jsx`. Nadie lo notó porque nada lo comprueba.
Ese es exactamente el mecanismo de falla que este plan ataca: **la convención sin test es una
intención, no una garantía.**

### 1.b Duplicación de dominio (el mismo concepto, dos implementaciones)

| # | Concepto | wacrm | Komo | ¿Sincroniza? |
| - | --- | --- | --- | --- |
| 1 | Pipelines y etapas | `Pipeline`/`PipelineStage` | `Pipeline`/`PipelineStage` | **Sí** (`POST /api/v1/pipelines/sync`, Komo manda) |
| 2 | Objeto comercial | `Deal` | `Lead` | Parcial, por nombre de etapa, **bidireccional** |
| 3 | Contactos | `contacts` | `contacts` | **No** — importación manual `POST /contacts/import-wacrm` |
| 4 | Etiquetas | `Tag` + `AutoTagRule` | `Tag` | **No** |
| 5 | Campos personalizados | `CustomField` | `CustomField` | **No** |
| 6 | Envíos masivos | `Broadcast` + `Creator` + `SendBroadcastJob` | `Broadcast` + `SendBroadcastMessageJob` | **No** — dos motores |
| 7 | Métricas de respuesta | `ResponseMetrics` (500 líneas) | `ResponseMetrics` (573) | Gemelo declarado, sin test cruzado |
| 8 | Ventana de servicio | `ServiceWindow` (190) | `ServiceWindow` (185) | Gemelo declarado, sin test cruzado |
| 9 | Supervisión | `/supervision` + `BacklogCharts` | `/supervision` + `TeamComparison` | — |
| 10 | Dashboard, Notificaciones, Feedback de IA, Equipo | dos veces cada uno | | — |

---

## 2. Decisiones de diseño (antes de escribir código)

1. **No se fusionan los proyectos.** Son dos apps con dos ciclos de deploy y dos dominios
   distintos. Lo que se extrae es lo que **no tiene dueño**: infraestructura y presentación.

2. **Cada dato tiene un dueño único, y el otro lado tiene un espejo *read-only*.** Hoy varios
   datos tienen dos dueños y se reconcilian a mano. La regla nueva: quien no es dueño no
   escribe — muestra lo que le llega y enlaza al dueño para editar.

3. **El motor de mensajería vive en el wacrm, sin excepciones.** Todo lo que sale hacia Meta
   (plantillas, ventana de 24/72 h, rate limit, costo) es del wacrm. Komo decide **a quién** y
   **qué**, nunca **cómo se manda**. La violación de esta regla es lo que hace que hoy Komo
   tenga un motor de envíos paralelo (§3, D1).

4. **Un gemelo sin fixture compartida no es un gemelo, es una copia.** Las definiciones que
   deben coincidir en ambos repos se fijan con **el mismo archivo de casos**, versionado en el
   paquete compartido y ejecutado por los tests de los dos lados.

5. **La deduplicación no puede requerir un deploy simultáneo.** Los dos proyectos se despliegan
   por separado; cada fase deja el sistema funcionando con una sola de las dos mitades
   desplegada. En cada fase se declara el orden de deploy.

6. **No se toca `ResponseMetrics` en esta ronda.** Sus definiciones están fijadas por
   `SupervisionMetricsTest` (Komo) y `SupervisionTest` (wacrm) y son la base de toda la
   analítica. D4 le agrega verificación cruzada; no le cambia una línea de comportamiento.

---

## 3. Fases

### D1 — Un solo motor de envíos masivos · ✅ HECHO (2026-09-01)

> **Ejecutada en dos commits**, como se preveía por el orden de deploy: **D1a** en el wacrm
> (`body_type=text`, `audience=phones`, guardián de ventana en el envío, informe de audiencia) y
> **D1b** en Komo (delegación, borrado de `SendBroadcastMessageJob`, detalle con contadores
> remotos). Suites: wacrm **417/417**, Komo **389/389**. Detalle y trampas en los dos
> `CLAUDE_*.md`.
>
> **Cambio de alcance respecto de lo planificado:** el plan decía «el endpoint ya existe, Komo
> nunca lo usó». Era cierto a medias — existía, pero **solo enviaba plantillas**, y los
> broadcasts de Komo son texto libre. Delegar sin más habría obligado al equipo a redactar una
> plantilla aprobada por Meta para cada aviso, que es un cambio de producto, no de arquitectura.
> Por eso D1a agregó el cuerpo de texto en vez de forzar la plantilla. Lo que sí se cerró es el
> agujero real: **el texto ya no sale fuera de la ventana**, se descarta con motivo y se dice
> en pantalla a cuántos alcanzó y a cuántos no.
>
> **Pendiente declarado (D1c, no bloqueante):** que la pantalla de creación ofrezca elegir una
> plantilla aprobada para alcanzar a los que quedaron afuera. Hoy los excluye y lo informa —
> mejor que antes, donde lo intentaba igual y fallaba en silencio— pero no ofrece el camino
> alternativo. Requiere exponer las plantillas del wacrm por API (`GET /api/v1/templates`), que
> hoy no existe.



**Por qué primero:** es lo único de este plan que hoy cuesta dinero y no solo mantenimiento,
y es independiente de todo lo demás.

**El problema, con la evidencia:** el wacrm envía broadcasts con **plantilla aprobada de Meta**
(`Services\Broadcasts\Creator` + `SendBroadcastJob`, que resuelve `header_type`, sustituye
variables por contacto y correlaciona las respuestas). Komo tiene un motor propio,
`Jobs\SendBroadcastMessageJob`, que manda **texto suelto** por `POST /api/v1/messages`. Su
propio docblock lo admite:

> *«Meta cobra por conversacion iniciada fuera de ventana 24h — usar templates aprobados desde
> el wacrm si aplica; este job envia texto simple asumiendo que el contacto ya esta en ventana»*

Fuera de la ventana de 24 h eso **falla o factura sin plantilla**, y el envío no aparece en las
métricas de broadcast del wacrm (`/broadcasts-metrics`).

**Y el endpoint que hace falta ya existe:** `POST /api/v1/broadcasts` (scope `broadcasts:write`,
`ApiController@storeBroadcast` → `Creator@create`). Komo nunca lo usó.

#### Trabajo

- **Komo** — `BroadcastController@store` deja de crear `broadcast_recipients` locales y despachar
  jobs; llama a `Wacrm\Client::createBroadcast()` con la audiencia resuelta por `SegmentQuery`
  (que es lo que Komo sabe hacer y el wacrm no). Se **borra** `SendBroadcastMessageJob`.
- **wacrm** — `storeBroadcast` hoy solo acepta `audience: all|tags`. Se agrega
  `audience: 'contacts'` + `contact_ids[]` / `phones[]`, que es la forma en que Komo expresa un
  segmento. **Aditivo:** los dos valores viejos siguen funcionando igual.
- **Komo** — `/broadcasts/{broadcast}` pasa a leer el estado desde `GET /api/v1/broadcasts/{id}`
  (ya existe, scope `broadcasts:read`) en vez de contar filas propias. La tabla local queda como
  registro de **qué se pidió**, no de qué se envió.
- **Komo** — la pantalla de creación exige elegir **plantilla aprobada** cuando hay contactos
  fuera de ventana, y muestra el costo con `MessagingCost`. Hoy deja mandar texto libre a
  cualquiera sin decir nada.

#### Trampas D1

- ⚠️ **La audiencia de Komo puede incluir contactos que el wacrm no conoce** (leads de web form,
  email, importación). El endpoint tiene que responder **qué números descartó y por qué**, y Komo
  mostrarlo antes de confirmar — no en silencio. Un envío de 300 que sale a 40 sin avisar es peor
  que un error.
- ⚠️ `broadcast_recipients` de Komo es una **foto histórica** a propósito (`CLAUDE_komo.md`, T4:
  «quién recibió qué es un hecho histórico»). No se borra la tabla: se deja de escribir el estado
  de envío en ella, se sigue escribiendo la audiencia congelada.
- ⚠️ Los broadcasts ya enviados desde Komo tienen estado local y no existen del otro lado. La
  pantalla tiene que soportar las dos formas: sin `wacrm_broadcast_id` → mostrar lo local (legado).

**Deploy:** wacrm primero (endpoint aditivo), Komo después.
**Tests:** `BroadcastDelegationTest` (Komo: no se despacha job local, se llama al endpoint,
audiencia parcial se reporta), `ApiBroadcastContactsAudienceTest` (wacrm: `audience=contacts`,
números desconocidos se reportan y no rompen el envío del resto).
**DoD:** un broadcast creado en Komo aparece en `/broadcasts-metrics` del wacrm; ningún envío
sale sin plantilla fuera de ventana; `SendBroadcastMessageJob` no existe.

---

### D2 — Taxonomía compartida: etiquetas y campos personalizados · ✅ HECHO (2026-09-01)

> **D2a** en el wacrm (`POST /api/v1/taxonomy/sync`, `external_id` en `tags`/`custom_fields`,
> corte de escritura sobre lo que administra Komo) y **D2b** en Komo (`SyncTaxonomyToWacrmJob`,
> disparo desde los dos controladores, comando `komo:sync-taxonomy --dry-run`).
> Suites: wacrm **429/429**, Komo **396/396**.
>
> **Resuelta la decisión #4 del §7 sin preguntar, porque la respuesta correcta no era ninguna
> de las dos opciones que se planteaban.** «Gana Komo» y «revisar caso por caso» daban por
> sentado que la fusión implica que una fila sobreviva y la otra muera. No hace falta: ante un
> nombre duplicado el sync **enlaza** —adopta el uuid de Komo sobre la fila que ya existía en el
> wacrm— así que Komo pasa a ser dueño de la *definición* (nombre, color) y el wacrm conserva
> su fila con todas sus asociaciones. Ningún contacto queda sin etiquetar y ninguna regla de
> auto-tag se pierde.
>
> **Y la trampa era peor de lo que decía el plan.** No es que una regla de auto-tag quede
> apuntando al vacío: `auto_tag_rules.tag_id` es `cascadeOnDelete`, así que borrar la etiqueta
> **borra la regla**. El auto-etiquetado dejaría de funcionar sin un solo aviso. Por eso una
> etiqueta en uso no se borra nunca: se desvincula y sigue siendo local.
>
> **Alcance menor que el planificado, a propósito:** el plan decía «`/tags` y `/custom-fields`
> del wacrm pasan a read-only». Se hizo más fino — crear una etiqueta local sigue permitido
> (un agente que necesita marcar algo en el momento no puede quedar bloqueado esperando a un
> admin en otro sistema); lo que se bloquea es renombrar o borrar lo que administra Komo, que
> es lo que el sync pisaría igual.



**El problema:** los dos proyectos tienen catálogo propio de `Tag` y `CustomField`, y **no se
sincronizan** — a diferencia de los pipelines, que sí. Una etiqueta puesta en el inbox del wacrm
no existe en Komo; un campo personalizado definido en Komo no se ve al atender. Es la
inconsistencia más gratuita del sistema: **el mecanismo ya está escrito y probado.**

#### Trabajo

- **Komo es el dueño** (es donde se define la operación comercial), igual que con los pipelines.
- **wacrm** — `POST /api/v1/taxonomy/sync`, calcado de `PipelineSyncController`: match por
  `external_id` (uuid de Komo) y, si no, por nombre normalizado, para absorber las etiquetas que
  ya existen de los dos lados. Lo sincronizado que deja de venir se borra; **lo local sin
  `external_id` se conserva** (ver trampas).
- **Komo** — `SyncTaxonomyToWacrmJob`, calcado de `SyncPipelinesToWacrmJob`, disparado desde
  `TagController` y `CustomFieldController`.
- **wacrm** — `/tags` y `/custom-fields` pasan a **read-only** con enlace a Komo. Las
  `AutoTagRule` siguen siendo del wacrm (son reglas de mensajería, no taxonomía) pero solo pueden
  apuntar a etiquetas sincronizadas.

#### Trampas D2

- ⚠️ **El borrado es destructivo y asimétrico.** En pipelines, una etapa que desaparece reasigna
  sus deals. Acá, una etiqueta borrada en Komo **desetiqueta contactos del wacrm**. El primer
  sync tiene que correr con `--dry-run` y reporte, y no borrar nada que no tenga `external_id`.
- ⚠️ **Fusión inicial por nombre**: si las dos bases tienen «Interesado» y «interesado», el match
  normalizado los une y hay que decidir cuál sobrevive. Comando con reporte antes de ejecutar.
- ⚠️ Las etiquetas del wacrm que hoy usan las `AutoTagRule` no pueden desaparecer sin dejar la
  regla apuntando al vacío — la regla se desactiva **diciendo por qué** (patrón de
  `saveSteps`/`unenrolled` de T3.b).

**Deploy:** wacrm primero, Komo después.
**Tests:** `TaxonomySyncTest` (ambos repos), `AutoTagOrphanRuleTest` (wacrm).
**DoD:** una etiqueta creada en Komo aparece en el inbox del wacrm sin intervención; borrar una
etiqueta reporta cuántos contactos afecta antes de confirmar.

---

### D3 — Paquete compartido `crm-core` (infraestructura y presentación) · ⏸ BLOQUEADA · red puesta (2026-09-01)

> **Sigue pendiente** por la decisión #1 del §7: el `npm run build` corre en el VPS, así que el
> paquete JS necesita un registry privado o git+ssh con deploy key. Es acceso a infraestructura,
> no una decisión de diseño.
>
> **Lo que sí se hizo mientras tanto: la red.** `tests/Fixtures/twins/shared-files.json` (el
> manifiesto de los 36 archivos que deben ser idénticos) + `SharedFilesDriftTest` en cada repo,
> que los compara contra el hermano cuando lo encuentra al lado y se salta solo en el VPS y en
> CI. Verificado que detecta: se derivó `PrimaryButton.jsx` a propósito con la suite en verde y
> el test del otro repo lo señaló por nombre.
>
> Es explícitamente **menos** que D3: los archivos siguen siendo dos y hay que arreglarlos a
> mano cuando el test avisa. Pero cubre la falla real —la deriva silenciosa, que ya había pasado
> con la capa de gráficos— sin agregar una dependencia de despliegue que hoy no se puede
> sostener.
>
> **Cuando D3 se haga**, el manifiesto es la lista de extracción, y además hay que mudar al
> paquete las fixtures de D4 y borrar sus copias.
>
> **Los 61 archivos que hoy divergen quedan fuera del manifiesto**, incluida la capa de gráficos
> (8 de 11 separados). Meterlos exige decidir cuál versión gana archivo por archivo — eso es
> trabajo de D3, no de su red.



**Qué se extrae:** solo lo que no tiene dueño de dominio.

| Grupo | Contenido |
| --- | --- |
| PHP | `Concerns\BelongsToAccount`, `ApiKey` (base abstracta + prefijo por app), `AuthenticateApiKey`, `SsoController` (base + `APP_ID` por app), Breeze completo, `LoginRequest`, `ProfileUpdateRequest` |
| JS | `Components/` UI (14 archivos), `Components/Charts/` (11), `Hooks/` (2), `Layouts/GuestLayout`, `Pages/Auth/` (6), `Pages/Profile/Partials/` |
| Compartido futuro | **`ChannelRules`** del `plan_omnicanal.md` §T0.2 nace acá, no duplicado |

**Cómo se consume:** repositorio git propio (`crm-core`), consumido por composer (`vcs`) y npm
(`file:` no sirve — el VPS hace `git pull`, no tiene el hermano al lado). **No** un repositorio
`path` con symlink: en Windows/XAMPP los symlinks de composer requieren permisos elevados y en
el VPS el árbol de deploy no tiene el otro repo.

#### Trampas D3

- ⚠️ **`ApiKey::issue()` tiene un `substr` distinto en cada repo y NO es un bug**: `substr(…, 19)`
  para `wacrm_live_` (11 chars) y `18` para `komo_live_` (10). Al extraer la clase, el prefijo
  tiene que derivarse del literal (`strlen($prefix) + 8`), no quedar hardcodeado — copiar el 19 a
  Komo cortaría un carácter de la clave y ninguna clave nueva validaría.
- ⚠️ **`Login.jsx` diverge de verdad** (143 vs 201 líneas) y `AuthenticatedLayout.jsx` también
  (596 vs 496). Esos **no se extraen**: son la identidad visual de cada app. El paquete lleva
  `GuestLayout`, no `AuthenticatedLayout`.
- ⚠️ **`Modal.jsx` y `LiveInbound.jsx` tienen la misma cantidad de líneas pero difieren.** Antes
  de extraer, diffear y decidir cuál gana **con el dueño del proyecto**; adoptar la del wacrm por
  ser el original rompería pantallas de Komo en silencio.
- ⚠️ El build de producción (`npm run build`) corre **en el VPS**. El paquete npm tiene que estar
  disponible ahí: o se publica en un registry privado, o se instala por git+ssh con deploy key.
  **Decidir esto antes de empezar D3, no durante.**
- ⚠️ Las migraciones **no** se comparten. `api_keys` tiene esquema propio en cada base y unificar
  el esquema no aporta nada.

**Deploy:** los dos repos, en cualquier orden (el paquete se congela por versión en
`composer.lock`/`package-lock.json`).
**Tests:** las suites existentes de ambos repos **sin un solo test nuevo de comportamiento** — si
D3 necesita cambiar un test, es que cambió algo y hay que revisarlo.
**DoD:** las dos suites verdes; `git grep` no encuentra ninguna de las 36 rutas idénticas
duplicada; un cambio en `PrimaryButton` se ve en las dos apps con un bump de versión.

---

### D4 — Gemelos con fixtures compartidas · ✅ HECHO (2026-09-01)

> `tests/Fixtures/twins/{service-window,response-metrics}.json`, byte-idénticos en los dos
> repos, más `TwinContractTest` en cada uno. **133 aserciones de cada lado, el mismo número** —
> la evidencia de que hoy los gemelos están de acuerdo de verdad, midiendo desde fuentes
> distintas (`messages` vs `lead_events`).
> Suites: wacrm **439/439**, Komo **405/405**.
>
> **Se hizo sin esperar a D3, que era la dependencia declarada.** El plan ponía las fixtures
> dentro del paquete compartido, y D3 sigue bloqueada por el hosting del npm. Pero el archivo
> duplicado ya cierra el caso real —alguien toca la definición que tiene enfrente y su propia
> suite se pone roja— y F0 del omnicanal está por meter `ChannelRules` justo en
> `ServiceWindow::build()`. Esperar habría dejado ese cambio sin red.
>
> **Lo que queda sin cubrir, dicho en el propio test:** editar las fixtures de los dos repos de
> forma inconsistente sigue siendo posible. Se cierra cuando el archivo viva una sola vez, o
> sea al hacer D3. Cuando pase, las dos copias se borran y el test apunta al paquete.
>
> **Se verificó que el guardián detecta, no solo que pasa.** Con la suite verde se rompió la
> definición a propósito dos veces —`WARNING_HOURS` 4→3 y `max($adExpiry)`→`min()`— y las dos
> salieron en rojo con el caso nombrado. Un test que nunca se vio fallar es una intención, no
> una garantía.



**El problema:** `ServiceWindow` y `ResponseMetrics` están documentados como «si cambia una
definición hay que tocar los dos» (`plan_omnicanal.md` §1). El mecanismo que lo garantiza es
**acordarse**. Ya divergieron en superficie: Komo agregó `conversionFor()`/`operativesFor()` y
renombró `forAgent`→`forResponsible`. La divergencia de superficie es legítima (las fuentes de
datos son distintas: `messages` vs `lead_events`); **la de definición no tendría cómo detectarse.**

#### Trabajo

- `crm-core` publica `fixtures/service-window.json` y `fixtures/response-metrics.json`: casos de
  entrada abstractos (instantes de entrantes/salientes, marca de anuncio, autor) con la salida
  esperada.
- Cada repo estrena un test que **construye su propio estado** desde la fixture (mensajes en
  wacrm, `lead_events` en Komo) y compara contra la misma salida esperada.
- Los casos que hay que fijar sí o sí, porque ya están escritos en prosa en los `CLAUDE_*.md`:
  el reloj arranca en el **primer** mensaje de la ráfaga; **la IA no cierra la espera**; un
  saliente sin espera abierta es seguimiento proactivo; la ventana vigente es **la que venza más
  tarde** (24 h estándar vs 72 h de anuncio); el caso «clic en anuncio + escribe en la hora 71 →
  la ventana llega a la hora 95».
- **Nada de comportamiento cambia en esta fase.** Si un test de fixture falla al escribirlo,
  encontró una divergencia real: se anota, se arregla en un commit aparte y se dice cuál de los
  dos estaba mal.

**Deploy:** ninguno (solo tests) salvo que aparezca una divergencia real.
**Tests:** `ServiceWindowFixtureTest` y `ResponseMetricsFixtureTest` en **ambos** repos.
**DoD:** cambiar `STANDARD_HOURS` en un solo repo pone la suite del otro en rojo.

---

### D5 — Pipelines y objeto comercial: dueño único, espejo read-only · ✅ HECHO (2026-09-01)

> **D5a** en el wacrm (uuid en el webhook y en la API de etapa, estructura del pipeline
> sincronizado no editable, controles ocultos en la UI) y **D5b** en Komo (manda el uuid, lo
> usa al recibir, y el lead abierto gana sobre el más reciente).
> Suites: wacrm **435/435**, Komo **401/401**.
>
> **Se hizo lo que el plan identificaba como el riesgo real y NO se hizo el resto**, que era la
> parte cuestionable:
>
> - ✅ Correlación por `external_id` en las dos direcciones, con caída al nombre.
> - ✅ La ambigüedad de `->latest()->first()`: era peor de lo que decía el plan. No es solo
>   «gana el más nuevo en silencio» — es que **arrastrar la tarjeta reabría un negocio ya
>   cerrado** si el más nuevo estaba cerrado. Ahora gana el abierto.
> - ✅ Estructura del pipeline no editable en el wacrm (ya no sobrevivía al sync; ahora se dice).
> - ❌ **`/pipelines` NO quedó read-only entero.** Mover un deal entre columnas sigue
>   permitido: es el gesto operativo del asesor, se espeja bien en las dos direcciones y
>   bloquearlo habría roto un flujo que funciona. El corte es sobre la estructura, no sobre la
>   operación.
> - ❌ **`DealController@store/destroy` no se tocó.** El plan proponía quitarlos de la UI, pero
>   `Deal` está en `InboundProcessor`, `ResponseMetrics`, `ContactMergeController`,
>   `TeamApiController`, `SyncDealAssignments`, 7 páginas JSX y 4 tests: es el refactor de
>   T0.2b del omnicanal, no una tarea de deduplicación. Sigue pendiente y sigue sin ser urgente.



**⚠️ Corrección respecto de la primera lectura.** La idea de «eliminar `Deal` del wacrm» era
más barata en la conversación que en el código. `Deal` está usado por:

`InboundProcessor` (`createLeadDeal` en el camino de entrada) · `ResponseMetrics` ·
`ContactMergeController` · `Api\V1\TeamApiController` (`setConversationStage`) ·
`Console\Commands\SyncDealAssignments` · `DashboardController` · `InboxController` ·
`Contact`/`Pipeline`/`PipelineStage` · 7 páginas JSX · los tests `PipelinesTest`,
`PipelineSyncTest`, `DealAssignmentSyncTest`, `PipelineServerFilteringTest`.

Borrarlo es un refactor del camino de entrada y de la supervisión, o sea el mismo riesgo que
T0.2b del omnicanal. **No se borra. Se degrada a espejo.**

#### El riesgo real que sí hay que arreglar

La etapa se sincroniza **en las dos direcciones**:

- Komo → wacrm: `Lead::moveToStage` → `SyncLeadStageToWacrmJob` → `setConversationStage`
- wacrm → Komo: webhook `deal.stage_changed` → `EventProcessor::handleDealStageChanged`

El bucle está cortado por «misma etapa → no rebota», que funciona. Pero la correspondencia se
hace **por nombre de etapa**:

```php
$stage = $lead->pipeline->stages()->where('name', $stageName)->first();
```

y el lead se busca con `where('wacrm_conversation_id', $convId)->latest()->first()`. Dos
consecuencias, ninguna documentada:

- Dos etapas con el mismo nombre en pipelines distintos → el cambio puede aterrizar en la etapa
  equivocada.
- Dos leads sobre la misma conversación → gana el más nuevo, en silencio.

#### Trabajo

- **La etapa se correlaciona por `external_id`, no por nombre.** El uuid ya viaja en
  `pipelines/sync`; el webhook `deal.stage_changed` pasa a mandarlo (aditivo; si no viene, cae al
  nombre, que es el camino de hoy).
- **`/pipelines` del wacrm pasa a read-only:** se quitan `DealController@store/update/destroy` de
  la UI y el kanban deja de ser arrastrable, con enlace a Komo. La etapa sigue cambiándose desde
  el inbox (`setConversationStage`), que es el gesto operativo legítimo del asesor.
- **`PipelineController`/`PipelineStageController` del wacrm dejan de exponer escritura**: las
  columnas ya las manda Komo y editarlas allá se pierde en el próximo sync — hoy es una pantalla
  que promete algo que no cumple.
- `SyncDealAssignments` se conserva: la asignación sí es un dato del wacrm (quién atiende la
  conversación).

#### Trampas D5

- ⚠️ Un lead con **dos** conversaciones (o dos leads sobre una) hace ambiguo el espejo. Contar los
  casos en producción **antes** de tocar nada, con reporte.
- ⚠️ Quitar escritura de una pantalla que el equipo usa es un cambio de flujo de trabajo, no solo
  de código. Va con aviso en pantalla, no en silencio.

**Deploy:** wacrm primero (campo aditivo en el webhook), Komo después.
**Tests:** `DealStageCorrelationTest` (ambos repos: por `external_id`, con caída al nombre),
`PipelineReadOnlyTest` (wacrm).
**DoD:** renombrar una etapa en Komo no rompe la sincronización de etapa en ninguna dirección.

---

## 4. Lo que este plan NO hace, y por qué

- **No unifica Contactos.** Es el mismo trabajo que `contact_identities` de **F0b del
  `plan_omnicanal.md`**, que además lo necesita para desbloquear Telegram. Hacerlo dos veces
  sería el colmo de un plan de deduplicación. Lo único que sí corresponde acá: dejar anotado que
  `POST /contacts/import-wacrm` (importación manual) **muere en F0b**, no antes.
- **No unifica Supervisión ni Dashboard.** Se parecen, pero miden cosas distintas sobre datos
  distintos (conversaciones vs leads) y las dos audiencias existen. Lo que sí se unifica es su
  *definición* (D4) y sus *gráficos* (D3).
- **No toca la IA, los flows ni las automatizaciones.** Viven solo en el wacrm; no hay duplicación.
- **No unifica las bases de datos.** Dos apps, dos ciclos de deploy, dos esquemas.

---

## 5. Orden, esfuerzo y encaje con el plan omnicanal

| Fase | Esfuerzo | Deploy | Depende de | Gana |
| --- | --- | --- | --- | --- |
| ~~**D1** Broadcasts~~ ✅ | hecho | wacrm → Komo | — | Deja de facturar mal / envíos visibles en métricas |
| ~~**D2** Taxonomía~~ ✅ | hecho | wacrm → Komo | — | Etiquetas y campos coherentes entre pantallas |
| **D3** Paquete `crm-core` ⏸ | 1-2 sem | ambos | **decisión de hosting npm** | 36 archivos dejan de ser dos; `ChannelRules` nace único |
| ~~D3-red~~ ✅ guardián de deriva | hecho | ninguno | — | La deriva de esos 36 deja de ser invisible |
| ~~**D4** Fixtures de gemelos~~ ✅ | hecho | ninguno | ~~D3~~ | La convención pasa a ser garantía |
| ~~**D5** Pipeline read-only~~ ✅ | hecho | wacrm → Komo | — | Se cierra la correlación por nombre |

**Queda solo D3** (~1-2 semanas), la última estructural. **Sigue bloqueada** por la decisión #1
del §7 (hosting del paquete npm); su mitad PHP, que solo necesita composer `vcs`, no lo está.

Cuando D3 se haga, además de extraer los 36 archivos idénticos hay que **mover ahí las fixtures
de D4** y borrar las dos copias: es lo que cierra el único agujero que D4 dejó abierto.

**Encaje con `plan_omnicanal.md`:**

- **D3 y D4 van antes de F0.** `ChannelRules` está especificado como «clase sin dependencias que
  consumen `ServiceWindow` y `MessagingCost` en **ambos** repos» — o sea, un gemelo más. Si el
  paquete existe, nace una vez. Si no, se copia, y con F1/F2/F4 se copia tres veces más.
- **D1 va antes de F0** también: el omnicanal agrega `channel` a los broadcasts (§T0.3). Hacerlo
  sobre dos motores es hacerlo dos veces.
- **D5 puede ir después de F0** sin costo.
- **D2 es independiente** de todo.

---

## 6. Checklist por fase (mismas reglas que el omnicanal)

1. Las **dos** suites en verde antes y después; tests nuevos nombrados en el commit y en el CLAUDE.
2. Contratos de API/webhook **aditivos**: el receptor viejo sigue funcionando (los deploys no son
   simultáneos).
3. Cortes de rol en el servidor, nunca solo en la UI.
4. Nada que envíe salientes a cliente cambia de camino sin un test que fije el costo.
5. Antes de refactorizar algo con muchos consumidores, **primero** el test de caracterización que
   fija el comportamiento actual.
6. Jobs nuevos o borrados → `systemctl restart crm-*-queue.service` en el deploy.
7. Todo borrado masivo (etiquetas, etapas, deals) va con `--dry-run` y reporte antes.
8. Actualizar `CLAUDE_crm_whatsapp.md` y `CLAUDE_komo.md` con la ronda.

---

## 7. Decisiones pendientes del dueño del proyecto

Estas bloquean su fase y no las puede tomar el agente:

1. **D3 — hosting del paquete npm.** El build corre en el VPS: ¿registry privado, o git+ssh con
   deploy key? (Composer no tiene este problema: `vcs` sobre el repo git alcanza.)
2. **D3 — `Modal.jsx` y `LiveInbound.jsx`:** difieren con el mismo largo. ¿Cuál de las dos
   versiones gana?
3. ~~**D5 — `/pipelines` del wacrm queda read-only**~~ — resuelta en la ejecución: el corte
   quedó sobre la estructura y no sobre la operación, así que no hay cambio de flujo que
   consultar. Ver la nota de D5.
4. ~~**D2 — fusión inicial de etiquetas**~~ — resuelta en la ejecución: no hace falta que gane
   ninguna, se enlazan. Ver la nota de D2.
