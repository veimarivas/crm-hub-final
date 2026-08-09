<?php

namespace App\Services\Copilot;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Services\WhatsApp\MessagingCost;
use App\Services\WhatsApp\ServiceWindow;

/**
 * «¿Qué hago ahora con este lead?»
 *
 * La capa prescriptiva del copiloto. Un score dice *cuánto importa* un lead;
 * esto dice *qué hacer*, que es lo único que cambia un resultado.
 *
 * Tres reglas de diseño, todas aprendidas de que el equipo ignore los avisos:
 *
 *  1. **Cada sugerencia dice su motivo.** «Llamalo» no se acciona; «escribió
 *     hace 3 h y nadie contestó» sí.
 *  2. **Cada sugerencia trae la acción a un clic**, no un consejo genérico.
 *  3. **Se ordenan por urgencia real y se cortan.** Cinco avisos permanentes
 *     son ruido de fondo: si todo urge, nada urge.
 *
 * No genera texto con IA: son reglas sobre hechos. Cuesta cero, no alucina y
 * se puede explicar. La redacción por LLM está prevista para más adelante y
 * detrás de flag (T1.d).
 */
class NextActions
{
    /** Máximo de sugerencias que se muestran a la vez. */
    private const MAX = 4;

    /** Minutos de espera a partir de los cuales responder es urgente. */
    private const SLA_MINUTES = 30;

    /** Días en la misma etapa a partir de los cuales el lead está estancado. */
    private const STAGNANT_DAYS = 7;

    /** Horas restantes de ventana a partir de las cuales conviene apurar. */
    private const WINDOW_URGENT_HOURS = 6;

    /**
     * @param  array<string, mixed>|null  $signals  salida de `LeadSignals`; si
     *                                             no se pasa, se calcula.
     * @return array<int, array<string, mixed>>
     */
    public function forLead(Lead $lead, ?array $signals = null): array
    {
        // Un lead cerrado no tiene «próxima acción»: cualquier sugerencia sobre
        // él es ruido, y peor, sugiere reabrir algo que el equipo ya decidió.
        if ($lead->status !== Lead::STATUS_OPEN) {
            return [];
        }

        $signals ??= (new LeadSignals($lead->account_id))->forLeads(collect([$lead]))[$lead->id];
        $window = app(ServiceWindow::class)->forLead($lead);

        $actions = array_filter([
            $this->waitingReply($lead),
            $this->windowClosing($lead, $window),
            $this->noPendingTask($lead, $signals),
            $this->cooledDown($lead),
            $this->stagnant($lead, $signals),
        ]);

        usort($actions, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice(array_values($actions), 0, self::MAX);
    }

    /**
     * El contacto escribió y todavía nadie humano contestó.
     *
     * Misma definición que `/supervision` (la IA no cierra la espera): si acá
     * significara otra cosa, el mismo lead aparecería como atendido en una
     * pantalla y desatendido en la otra.
     */
    private function waitingReply(Lead $lead): ?array
    {
        $last = LeadEvent::forAccount($lead->account_id)
            ->where('lead_id', $lead->id)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->latest('created_at')
            ->first(['event_type', 'payload', 'created_at']);

        if (! $last || $last->event_type !== 'message_in') {
            return null;
        }

        $minutes = (int) $last->created_at->diffInMinutes(now(), true);
        $breached = $minutes >= self::SLA_MINUTES;

        return [
            'key' => 'reply',
            'label' => 'Responder',
            'reason' => $minutes < 60
                ? "Escribió hace {$minutes} min y sigue sin respuesta"
                : 'Escribió hace '.round($minutes / 60).' h y sigue sin respuesta',
            'tone' => $breached ? 'danger' : 'warning',
            // Lo más urgente que puede haber: el cliente está esperando ahora.
            'priority' => $breached ? 100 : 80,
            'action' => ['type' => 'chat'],
        ];
    }

    /**
     * La ventana de servicio se está por cerrar.
     *
     * Cuando se cierra, escribirle deja de ser gratis y pasa a requerir
     * plantilla aprobada — por eso la sugerencia lleva el costo: la decisión de
     * apurarse o dejarlo pasar es económica, no de gusto.
     */
    private function windowClosing(Lead $lead, ?array $window): ?array
    {
        if (! $window || ! ($window['is_open'] ?? false)) {
            return null;
        }

        $hours = ($window['remaining_seconds'] ?? 0) / 3600;

        if ($hours > self::WINDOW_URGENT_HOURS) {
            return null;
        }

        return [
            'key' => 'window',
            'label' => 'Escribir ahora',
            'reason' => 'La ventana sin costo cierra en '.max(1, round($hours)).' h',
            'tone' => 'warning',
            'priority' => 70,
            'action' => [
                'type' => 'chat',
                'cost_after' => app(MessagingCost::class)->estimate(1),
            ],
        ];
    }

    /** La regla Kommo: ningún lead abierto sin próxima tarea. */
    private function noPendingTask(Lead $lead, array $signals): ?array
    {
        if ($signals['has_pending_task']) {
            return null;
        }

        $days = (int) round($signals['age_days']);

        return [
            'key' => 'task',
            'label' => 'Agendar seguimiento',
            'reason' => $days > 0
                ? "Abierto hace {$days} d y sin ninguna tarea agendada"
                : 'Sin ninguna tarea agendada',
            'tone' => 'warning',
            // Sube con la antigüedad: un lead de hoy sin tarea es normal, uno
            // de tres semanas sin tarea está abandonado.
            'priority' => min(75, 30 + $days * 2),
            'action' => ['type' => 'task'],
        ];
    }

    /**
     * Se enfrió: cayó de banda desde la última vez.
     *
     * Es la señal que justifica guardar la banda anterior. Un lead que estaba
     * caliente y se enfrió es algo que se estaba por cerrar y se está
     * perdiendo — muy distinto de un lead que siempre estuvo frío.
     */
    private function cooledDown(Lead $lead): ?array
    {
        $ranking = [LeadScorer::BAND_COLD => 0, LeadScorer::BAND_WARM => 1, LeadScorer::BAND_HOT => 2];

        $before = $ranking[$lead->score_band_previous] ?? null;
        $now = $ranking[$lead->score_band] ?? null;

        if ($before === null || $now === null || $now >= $before) {
            return null;
        }

        return [
            'key' => 'cooled',
            'label' => 'Revisar',
            'reason' => "Pasó de {$lead->score_band_previous} a {$lead->score_band}",
            'tone' => 'danger',
            'priority' => 85,
            'action' => ['type' => 'chat'],
        ];
    }

    /** Estancado en la misma etapa: o avanza, o se cierra como perdido. */
    private function stagnant(Lead $lead, array $signals): ?array
    {
        $days = (int) round($signals['days_in_stage']);

        if ($days < self::STAGNANT_DAYS) {
            return null;
        }

        return [
            'key' => 'stagnant',
            'label' => 'Mover de etapa',
            'reason' => "Hace {$days} d en «".($lead->stage?->name ?? 'la misma etapa').'»',
            'tone' => 'neutral',
            'priority' => min(60, 20 + $days),
            'action' => ['type' => 'stage'],
        ];
    }
}
