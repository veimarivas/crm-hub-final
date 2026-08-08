# Komo (ESAM HUB) — Reportes legibles y analizables

## Instrucciones de trabajo para el agente

> **Objetivo:** que `/reports` deje de ser tablas y barras sueltas y pase a ser un dashboard analítico: tendencias, embudo con % de conversión entre etapas, fuentes con revenue, equipo comparado y tiempo de cierre. Después, mejorar `/supervision` con comparativas. Ejecuta en orden; el Paso 0 es prerequisito.

---

## 0. Reglas del proyecto (lectura obligatoria)

- Stack: Laravel 13 + Inertia + React 18 + Vite, MariaDB. `npm install` **siempre** con `--legacy-peer-deps`. Puerto local 8001.
- Build de producción en el servidor (`/public/build` en `.gitignore`).
- Multi-tenant por `account_id` (trait `BelongsToAccount`), PKs UUID, atributos `#[Fillable]`.
- Estilo Velzon vigente (cards `rounded-2xl shadow-sm border-gray-100`, gradiente de marca `from-[#045474] to-[#1c486c]`).
- **Semántica de color:** emerald = ganado/positivo; amber = advertencia; rose = perdido/peligro; purple = IA; gradiente de marca = serie principal. Los colores de etapa del pipeline ya existen: reusarlos en el embudo y en las barras del equipo.
- Filtros server-side con query params + `router.get` con debounce (patrón de `/leads` y `/notifications`). Prohibido filtrar en cliente datos paginados (lección de `NotificationTabsTest`).
- Roles: middleware `admin.only` donde corresponda; `agent` ve solo sus leads. `ReportController` ya scopa y pasa `isAdmin` como prop: los gráficos nuevos deben respetarlo (sección de equipo solo para admin).
- Sin migraciones salvo necesidad estricta; todo sale de `leads`, `lead_events`, `tasks`.
- Tests feature por endpoint; suite `php artisan test` en verde. Operación local: `serve --port=8001` + `queue:work` + `schedule:work`.
- Una tarea = un commit.

---

## 1. Paso 0 — Capa base de gráficos (prerequisito)

1. `npm install recharts --legacy-peer-deps`.
2. Crear `resources/js/Components/Charts/` con:
    - `chartTheme.js` (paleta según semántica de arriba), `format.js` (`fmtNumber` es-BO compacto, `fmtMoney` Bs, `fmtDuration`, `fmtPct`).
    - `ChartCard.jsx` con título/subtítulo/acciones y estado vacío `EmptyChart` ("Sin datos en este periodo").
    - `TrendArea.jsx` (área multi-serie con gradiente de marca), `CompareBars.jsx` (con `ReferenceLine`), `FunnelSteps.jsx` (count + **% de paso entre etapas** + % caída), `DonutChart.jsx`, `HeatmapGrid.jsx` (hora × día), `WindowPicker.jsx` (`?days=` 7/15/30/90, patrón de `/supervision`).
3. Tooltips con valor absoluto **y** %; leyendas clicables.

**Criterio de aceptación:** la capa renderiza en `/reports` sin regresiones y suite en verde.

---

## 2. Tareas por prioridad

### P0 · T1 — Rediseño analítico de `/reports` (`ReportController` + `Reports/Index.jsx`)

Agregar al controller (queries agregadas, scope de rol existente, `isAdmin` prop):

1. **`weekly_series`** (6 meses): leads creados por semana (`created_at`) + ganados/perdidos por semana (`closed_at`). → `TrendArea` de 3 series. Es el gráfico que hoy NO existe y el que más informa: muestra si el negocio acelera o se enfría.
2. **`funnel`**: etapas del pipeline default en orden (abiertas + ganado), con count y **% de paso respecto a la etapa anterior**. → `FunnelSteps`. El embudo actual son barras sin %: el dato accionable es _dónde se cae el lead_.
3. **`sources`** extendido: la tabla de conversión por fuente (Fase 16) ya existe; agregarle **revenue** (suma de `value_cents` de ganados por fuente). → `DonutChart` de distribución + barras horizontales de conversión, manteniendo la tabla debajo con sus badges.
4. **`team_stages`**: `byUser[].stages` ya existe → `CompareBars` **apiladas** por etapa (colores de etapa), manteniendo la tabla de "Equipo este mes". Solo admin.
5. **`revenue`**: card de facturado vs cobrado en el periodo (`invoiced_cents` / `collected_cents`, datos de Invoice) + ticket promedio; serie mensual de revenue de ganados (`value_cents` por mes de `closed_at`). → `TrendArea`.
6. **`close_time_histogram`**: días entre `created_at` y `closed_at` de ganados/perdidos, en baldes (‹1 d / 1-3 / 3-7 / 7-14 / 14-30 / ›30). → `CompareBars`. Responde "cuánto tarda en cerrar un lead".

**Frontend:** grilla de dashboard: fila de KPI cards **con delta vs periodo anterior** (flecha + %), luego los gráficos 1-6 en grid de 2 columnas (`lg:grid-cols-2`), selector `WindowPicker` arriba que aplica a todo.

**Drill-down (clic → filtro):**

- Clic en etapa del embudo → `/leads?stage_id=X`; clic en fuente → `/leads?source=X`.
- Si `LeadController@index` no acepta `stage_id`/`source` server-side, agregarlos (con tests, respetando el scope de rol: para no-admin el filtro de responsable se fuerza a él).

**Criterio de aceptación:** un agent ve `/reports` solo con sus números y sin sección de equipo; el admin ve todo; sin datos → estados vacíos, no ceros rotos.

### P1 · T2 — `/supervision` → comparativas de equipo

**Hoy:** index con tabla + ficha `Agent.jsx` (KPIs, histograma, embudo, pendientes). Todo desde `lead_events`, sin consultar al wacrm.

- Index: **comparativa horizontal** de mediana de primera respuesta por responsable con `ReferenceLine` de SLA (`sla_minutes` ya existe).
- Index: **tendencia diaria de cumplimiento SLA** (% dentro del objetivo).
- Index: **heatmap hora × día** de `message_in` — cuándo escriben los clientes vs cuándo se atiende.
- Index: **antigüedad del backlog**: baldes de horas desde el último `message_in` sin respuesta humana, reusando la detección de `operativesFor()` pero agregada. Vivir en método nuevo del controller o clase nueva — **no redefinir nada dentro de `ResponseMetrics`**.
- `Agent.jsx`: superponer la **línea de promedio del equipo** sobre las series del agente.

### P1 · T3 — Dashboard ejecutivo mínimo

- KPI cards actuales (abiertos / ganados mes / tareas hoy / leads sin tarea) con **delta vs periodo anterior**. La métrica "leads sin tarea" (regla Kommo) merece además una mini-lista clicable de los 5 peores.

### P2 · T4 — Transversales

- **Exportar CSV** en `/reports` (leads del periodo con fuente/etapa/valor): replicar el patrón del wacrm `ContactController@exportCsv` (stream chunked 500, BOM UTF-8, separador `;`).
- Clic en fila del ranking de equipo → `/supervision/agents/{user}` (ya existe).

---

## 3. Advertencias duras

- **GEMELO:** `Services\Supervision\ResponseMetrics` es idéntico al del wacrm y sus definiciones están fijadas por `SupervisionMetricsTest` (la IA no cierra la espera; el reloj arranca en el primer mensaje de la ráfaga; saliente sin espera = seguimiento proactivo). Si se toca UNA definición o método, replicar en el wacrm y correr ambos tests. Los gráficos nuevos del index deben ir en código nuevo, no en el gemelo.
- No consultar al wacrm para nada de esto: `lead_events` ya tiene `message_in`/`message_out` con `user_id` y `payload.ad_referral`.
- Agregaciones con `GROUP BY`, sin N+1; si pesan, caché corta (60-300 s) invalidada por huella.
- No tocar flujos de escritura (leads, tareas, automatizaciones): esto es solo lectura/agregación.

## 4. Definición de terminado (por tarea)

- [ ] Endpoint con `?days=` y scope de rol en el servidor (`admin.only` o filtro forzado).
- [ ] Gráficos con la capa del Paso 0, tooltips absoluto + %, estados vacíos.
- [ ] Tests feature nuevos + suite completa en verde.
- [ ] Sin filtrado cliente de datos paginados; sin `confirm()` nativos.
- [ ] Commit único por tarea.

## 5. Despliegue (al cerrar cada ronda)

```bash
cd /var/www/crm-komo && git pull origin main && npm ci && npm run build && php artisan optimize:clear
sudo systemctl restart crm-komo-queue.service   # solo si se tocaron jobs
```
