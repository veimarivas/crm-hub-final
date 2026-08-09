<?php

namespace App\Services\Dashboard;

use App\Models\DashboardWidget;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Copilot\LeadSignals;
use App\Services\Copilot\NextActions;
use App\Services\WhatsApp\ServiceWindow;

/**
 * Catálogo de widgets del dashboard: qué existe, quién puede verlo y cómo se
 * calcula.
 *
 * El punto no es solo la personalización. Hasta esta tarea `DashboardController`
 * calculaba **todo para todos en cada carga** —ocho agregados más el recorrido
 * de eventos de la cuenta entera— aunque el usuario mirara dos tarjetas.
 * Acá cada widget trae su propio `resolver`, y el controlador **solo ejecuta el
 * de los widgets visibles**: personalizar es, de paso, dejar de calcular lo que
 * nadie mira.
 *
 * `adminOnly` se corta en el servidor: un widget que un agente no puede ver no
 * se resuelve nunca, no se esconde en el cliente.
 */
class WidgetRegistry
{
    public const SIZES = ['sm', 'md', 'lg', 'full'];

    /**
     * Definiciones. `resolver` recibe el contexto y devuelve el payload del
     * widget; no se llama hasta que se sabe que el widget está visible.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'kpis' => [
                'label' => 'Indicadores clave',
                'description' => 'Abiertos, ganados del mes, tareas de hoy y leads sin tarea, con su variación.',
                'size' => 'full',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\KpiWidget)->resolve($c),
            ],
            'urgent_leads' => [
                'label' => 'Necesitan respuesta ya',
                'description' => 'Leads con un mensaje entrante sin contestar hace más del SLA.',
                'size' => 'full',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\UrgentLeadsWidget)->resolve($c),
            ],
            'copilot_priorities' => [
                'label' => 'Prioridades del copiloto',
                'description' => 'Los leads de mayor puntaje que además tienen algo pendiente.',
                'size' => 'md',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\CopilotPrioritiesWidget)->resolve($c),
            ],
            'forgotten_leads' => [
                'label' => 'Los más olvidados',
                'description' => 'Abiertos hace más tiempo sin una sola tarea agendada.',
                'size' => 'md',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\ForgottenLeadsWidget)->resolve($c),
            ],
            'recent_leads' => [
                'label' => 'Leads recientes',
                'description' => 'Los últimos que entraron al embudo, con su ventana de servicio.',
                'size' => 'lg',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\RecentLeadsWidget)->resolve($c),
            ],
            'my_tasks' => [
                'label' => 'Mis próximas tareas',
                'description' => 'Tus tareas pendientes ordenadas por vencimiento.',
                'size' => 'md',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\MyTasksWidget)->resolve($c),
            ],
            'pipeline_funnel' => [
                'label' => 'Embudo actual',
                'description' => 'Cuántos leads abiertos hay en cada etapa ahora mismo.',
                'size' => 'md',
                'adminOnly' => false,
                'resolver' => fn (WidgetContext $c) => (new Widgets\PipelineFunnelWidget)->resolve($c),
            ],
            'team_ranking' => [
                'label' => 'Equipo este mes',
                'description' => 'Ganados y valor cerrado por responsable.',
                'size' => 'md',
                'adminOnly' => true,
                'resolver' => fn (WidgetContext $c) => (new Widgets\TeamRankingWidget)->resolve($c),
            ],
        ];
    }

    /**
     * Layout por defecto según rol, en el orden en que conviene leerlo: lo que
     * quema primero, lo que se planifica después.
     *
     * @return array<int, array{widget_key: string, size: string, position: int}>
     */
    public function defaultLayout(bool $isAdmin): array
    {
        $order = ['urgent_leads', 'kpis', 'copilot_priorities', 'forgotten_leads', 'recent_leads', 'my_tasks'];

        if ($isAdmin) {
            $order[] = 'team_ranking';
        }

        $definitions = $this->definitions();

        return collect($order)
            ->values()
            ->map(fn (string $key, int $i) => [
                'widget_key' => $key,
                'size' => $definitions[$key]['size'],
                'position' => $i,
            ])
            ->all();
    }

    /**
     * Layout efectivo del usuario: el guardado, o el de por defecto si nunca
     * tocó nada.
     *
     * Los widgets que el rol no permite se filtran acá, así que aunque quedara
     * una fila vieja de cuando el usuario era admin, no se resuelve.
     *
     * @return array<int, array<string, mixed>>
     */
    public function layoutFor(User $user, bool $isAdmin): array
    {
        $definitions = $this->definitions();

        $saved = DashboardWidget::where('user_id', $user->id)
            ->orderBy('position')
            ->get(['widget_key', 'position', 'size', 'is_visible', 'config']);

        $layout = $saved->isEmpty()
            ? collect($this->defaultLayout($isAdmin))->map(fn ($w) => $w + ['is_visible' => true, 'config' => null])
            : $saved->map(fn ($w) => $w->only(['widget_key', 'position', 'size', 'is_visible', 'config']));

        return $layout
            ->filter(fn ($w) => isset($definitions[$w['widget_key']]))
            ->filter(fn ($w) => $isAdmin || ! $definitions[$w['widget_key']]['adminOnly'])
            ->values()
            ->all();
    }

    /**
     * Resuelve el payload de un widget. Lanza si el rol no alcanza — el corte
     * de verdad, por si alguien llama al resolver directo.
     */
    public function resolve(string $key, WidgetContext $context): mixed
    {
        $definition = $this->definitions()[$key] ?? null;

        if (! $definition) {
            throw new \InvalidArgumentException("Widget desconocido: «{$key}».");
        }

        if ($definition['adminOnly'] && ! $context->isAdmin) {
            throw new \InvalidArgumentException("El widget «{$key}» es solo para administradores.");
        }

        return ($definition['resolver'])($context);
    }

    /**
     * Catálogo para la pantalla de personalización (sin resolvers ni widgets
     * que el usuario no puede ver).
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogFor(bool $isAdmin): array
    {
        return collect($this->definitions())
            ->reject(fn ($d) => $d['adminOnly'] && ! $isAdmin)
            ->map(fn ($d, $key) => [
                'key' => $key,
                'label' => $d['label'],
                'description' => $d['description'],
                'defaultSize' => $d['size'],
            ])
            ->values()
            ->all();
    }
}
