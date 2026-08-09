<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Services\Dashboard\WidgetContext;

/**
 * Leads con un mensaje entrante sin contestar hace más del SLA.
 *
 * Mismo umbral y misma definición que el Inbox y `/supervision`: el último
 * evento de la conversación es un `message_in`. Si acá significara otra cosa,
 * el mismo lead figuraría urgente en una pantalla y atendido en otra.
 */
class UrgentLeadsWidget
{
    public function resolve(WidgetContext $c): array
    {
        $leadIds = $c->leads(Lead::forAccount($c->accountId)
            ->where('status', 'open')
            ->whereNotNull('wacrm_conversation_id'))
            ->pluck('id');

        if ($leadIds->isEmpty()) {
            return ['count' => 0, 'items' => [], 'slaMinutes' => $c->slaMinutes];
        }

        // Último evento de mensaje por lead.
        $lastEvents = LeadEvent::whereIn('lead_id', $leadIds)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->select('lead_id', 'event_type', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('lead_id')
            ->map(fn ($g) => $g->first());

        $threshold = now()->subMinutes($c->slaMinutes);
        $urgentIds = $lastEvents
            ->filter(fn ($e) => $e->event_type === 'message_in' && $e->created_at < $threshold)
            ->keys();

        if ($urgentIds->isEmpty()) {
            return ['count' => 0, 'items' => [], 'slaMinutes' => $c->slaMinutes];
        }

        $items = Lead::whereIn('id', $urgentIds)
            ->with(['contact:id,name,phone', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'stage_id'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'contact' => $l->contact ? ['name' => $l->contact->name, 'phone' => $l->contact->phone] : null,
                'stage' => $l->stage ? ['name' => $l->stage->name, 'color' => $l->stage->color] : null,
                'waiting_minutes' => (int) now()->diffInMinutes($lastEvents[$l->id]->created_at, true),
            ])
            ->sortByDesc('waiting_minutes')
            ->values()
            ->take(5);

        return [
            'count' => $urgentIds->count(),
            'items' => $items->all(),
            'slaMinutes' => $c->slaMinutes,
        ];
    }
}
