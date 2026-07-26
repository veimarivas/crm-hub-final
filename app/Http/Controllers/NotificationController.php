<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => AppNotification::where('user_id', $request->user()->id)
                ->delivered()
                ->with(['lead:id,title', 'sender:id,name'])
                ->orderByDesc('created_at')
                ->paginate(30),
            'categories' => AppNotification::CATEGORIES,
        ]);
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
