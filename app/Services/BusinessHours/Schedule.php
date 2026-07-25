<?php

namespace App\Services\BusinessHours;

use App\Models\Account;
use Carbon\Carbon;

class Schedule
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    // Fallback si el JSON del account esta vacio: L-V 9-18, sab/dom cerrado
    public const DEFAULT_SCHEDULE = [
        'mon' => ['from' => '09:00', 'to' => '18:00'],
        'tue' => ['from' => '09:00', 'to' => '18:00'],
        'wed' => ['from' => '09:00', 'to' => '18:00'],
        'thu' => ['from' => '09:00', 'to' => '18:00'],
        'fri' => ['from' => '09:00', 'to' => '18:00'],
        'sat' => null,
        'sun' => null,
    ];

    public const DEFAULT_MESSAGE = '¡Hola! Gracias por escribirnos. Nuestro horario de atención es de lunes a viernes de 9:00 a 18:00. Un asesor te responderá en el próximo horario laboral.';

    /**
     * Devuelve true si "ahora" (en el timezone de la cuenta) cae dentro del schedule.
     * Si business_hours_enabled=false, siempre es TRUE (todo el dia es horario).
     */
    public function isOpenNow(Account $account, ?Carbon $now = null): bool
    {
        if (! $account->business_hours_enabled) {
            return true;
        }

        $tz = $account->business_hours_timezone ?: 'America/La_Paz';
        $moment = ($now ?? Carbon::now())->copy()->setTimezone($tz);
        $schedule = $account->business_hours_schedule ?: self::DEFAULT_SCHEDULE;

        $dayKey = self::DAYS[$moment->dayOfWeekIso - 1]; // dayOfWeekIso: 1=Mon..7=Sun
        $slot = $schedule[$dayKey] ?? null;

        if (! $slot || empty($slot['from']) || empty($slot['to'])) {
            return false;
        }

        [$fromH, $fromM] = array_map('intval', explode(':', $slot['from']));
        [$toH, $toM] = array_map('intval', explode(':', $slot['to']));

        $current = $moment->hour * 60 + $moment->minute;
        $fromMin = $fromH * 60 + $fromM;
        $toMin = $toH * 60 + $toM;

        return $current >= $fromMin && $current < $toMin;
    }
}
