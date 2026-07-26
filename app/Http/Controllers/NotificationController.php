<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    private const TABS = ['all', 'unread', 'read'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';
        $category = in_array($request->query('category'), AppNotification::CATEGORIES, true)
            ? $request->query('category')
            : null;

        // `delivered()` siempre: un recordatorio programado no existe para su
        // destinatario hasta que llega su momento.
        $mine = fn () => AppNotification::where('user_id', $user->id)->delivered();

        $notifications = $mine()
            ->tap(fn (Builder $q) => $this->applyTab($q, $tab))
            ->when($category, fn (Builder $q, string $c) => $q->where('category', $c))
            // `status` y el contacto viajan para poder pintar el estado del
            // lead y a quién pertenece sin abrir el aviso.
            ->with([
                'lead:id,title,status,contact_id,stage_id,source_ref,created_at',
                'lead.contact:id,name,phone',
                'lead.stage:id,name,color',
                'sender:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Ventana del lead al que apunta el aviso: si alguien espera respuesta,
        // saber cuánto queda para contestar gratis decide si se atiende ahora.
        $windows = app(ServiceWindow::class)
            ->forLeads($notifications->pluck('lead')->filter()->unique('id')->values());

        $notifications->each(
            fn (AppNotification $n) => $n->setAttribute('service_window', $windows[$n->lead_id] ?? null)
        );

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'tab' => $tab,
            'category' => $category,
            'counts' => [
                'all' => $mine()->count(),
                'unread' => $mine()->whereNull('read_at')->count(),
                'read' => $mine()->whereNotNull('read_at')->count(),
            ],
            // Los contadores por apartado se calculan DENTRO de la pestaña
            // activa: si no, los números no cuadran con lo que se ve.
            'categoryCounts' => collect(AppNotification::CATEGORIES)
                ->mapWithKeys(fn (string $c) => [
                    $c => $mine()->tap(fn (Builder $q) => $this->applyTab($q, $tab))->where('category', $c)->count(),
                ])
                ->all(),
        ]);
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            default => null,
        };
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        // Solo las que ya puede ver: marcar como leído un recordatorio futuro
        // lo dejaría invisible para siempre.
        AppNotification::where('user_id', $request->user()->id)
            ->delivered()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Marca una sola como leída sin salir de la pantalla. Sin esto, un aviso
     * del admin sin lead asociado no se podía marcar de a uno: el único
     * camino era "marcar todas".
     */
    public function markRead(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        abort_if($notification->deliver_at && $notification->deliver_at->isFuture(), 404);

        $notification->update(['read_at' => $notification->read_at ? null : now()]);

        return back();
    }

    /**
     * Marca una notificación como leída y redirige al lead asociado (o a la
     * lista si no hay lead). Se usa como href del CTA "Ver lead" en la lista
     * y desde la campana del header, para que un solo clic haga las dos cosas.
     */
    public function go(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        abort_if($notification->deliver_at && $notification->deliver_at->isFuture(), 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->lead_id
            ? redirect()->route('leads.show', $notification->lead_id)
            : redirect()->route('notifications');
    }
}
