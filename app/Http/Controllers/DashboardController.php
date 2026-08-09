<?php

namespace App\Http\Controllers;

use App\Models\DashboardWidget;
use App\Services\Dashboard\WidgetContext;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard por widgets (T2 de mejoras2.md).
 *
 * Hasta esta tarea el controlador calculaba **todo para todos en cada carga**,
 * aunque el usuario mirara dos tarjetas. Ahora el catálogo vive en
 * `WidgetRegistry` y acá solo se resuelve **lo que el usuario tiene visible**:
 * personalizar es, de paso, dejar de calcular lo que nadie mira. Hay un test
 * que lo verifica contando queries.
 */
class DashboardController extends Controller
{
    /** Mismo umbral que InboxController: >30 min sin respuesta = urgente. */
    private const SLA_MINUTES = 30;

    public function index(Request $request, WidgetRegistry $registry): Response
    {
        $user = $request->user();
        $context = WidgetContext::for($user, self::SLA_MINUTES);

        $layout = $registry->layoutFor($user, $context->isAdmin);

        // El payload solo trae los visibles. Los ocultos viajan en el layout
        // (para poder reactivarlos desde el panel) pero sin datos: resolver un
        // widget apagado es exactamente lo que esta tarea vino a eliminar.
        $data = collect($layout)
            ->filter(fn ($w) => $w['is_visible'])
            ->mapWithKeys(fn ($w) => [$w['widget_key'] => $registry->resolve($w['widget_key'], $context)])
            ->all();

        return Inertia::render('Dashboard', [
            'layout' => $layout,
            'widgets' => $data,
            'catalog' => $registry->catalogFor($context->isAdmin),
            'currency' => $context->currency,
            'isAdmin' => $context->isAdmin,
        ]);
    }

    /**
     * Guarda el layout del usuario (orden, tamaño y visibilidad).
     *
     * Reemplaza el layout completo en vez de aplicar diferencias: el cliente
     * manda el estado final de la grilla, y reconciliar altas/bajas/reordenes
     * campo por campo sería más código para el mismo resultado.
     */
    public function saveLayout(Request $request, WidgetRegistry $registry): RedirectResponse
    {
        $allowed = array_keys($registry->definitions());

        $validated = $request->validate([
            'widgets' => 'required|array|max:50',
            'widgets.*.widget_key' => ['required', 'string', Rule::in($allowed)],
            'widgets.*.size' => ['required', 'string', Rule::in(WidgetRegistry::SIZES)],
            'widgets.*.is_visible' => 'required|boolean',
        ]);

        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(\App\Models\User::ROLE_ADMIN);
        $definitions = $registry->definitions();

        // El corte de rol también acá: sin esto, un agente podría activarse el
        // widget del equipo mandando el key a mano.
        $rows = collect($validated['widgets'])
            ->reject(fn ($w) => $definitions[$w['widget_key']]['adminOnly'] && ! $isAdmin)
            ->unique('widget_key')
            ->values();

        DB::transaction(function () use ($rows, $user) {
            DashboardWidget::where('user_id', $user->id)->delete();

            $rows->each(fn ($w, $i) => DashboardWidget::create([
                'account_id' => $user->account_id,
                'user_id' => $user->id,
                'widget_key' => $w['widget_key'],
                'position' => $i,
                'size' => $w['size'],
                'is_visible' => $w['is_visible'],
            ]));
        });

        return back()->with('success', 'Tablero guardado.');
    }

    /**
     * Vuelve al layout por defecto del rol.
     *
     * Borrar las filas alcanza: sin filas, `layoutFor()` devuelve el default.
     * Un tablero que el usuario rompió y no sabe restaurar es peor que uno fijo.
     */
    public function resetLayout(Request $request): RedirectResponse
    {
        DashboardWidget::where('user_id', $request->user()->id)->delete();

        return back()->with('success', 'Tablero restaurado.');
    }
}
