<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\User;
use App\Services\BusinessHours\Schedule;
use Carbon\Carbon;

class SlotCalculator
{
    /**
     * Devuelve slots libres para los proximos $daysAhead dias.
     * Estructura: [['date' => 'YYYY-MM-DD', 'label' => 'lun 27 jul', 'slots' => ['09:00', '09:30', ...]]]
     * Solo devuelve dias con al menos 1 slot libre.
     */
    public function slotsForHost(User $host, int $daysAhead = 14): array
    {
        $account = $host->account;
        $schedule = $account->business_hours_schedule ?: Schedule::DEFAULT_SCHEDULE;
        $tz = $account->business_hours_timezone ?: 'America/La_Paz';
        $duration = max(15, (int) $host->booking_duration_min);

        // Bookings existentes del host en el rango
        $now = Carbon::now($tz);
        $end = $now->copy()->addDays($daysAhead);
        $bookings = Booking::where('host_user_id', $host->id)
            ->where('status', 'confirmed')
            ->whereBetween('scheduled_at', [$now->copy()->utc(), $end->copy()->utc()])
            ->get(['scheduled_at', 'duration_min']);

        // Set de "minute-of-day-utc" ocupados (aproximacion: por instante inicial + duracion)
        $busy = [];
        foreach ($bookings as $b) {
            $start = $b->scheduled_at->getTimestamp();
            $endTs = $start + ($b->duration_min * 60);
            for ($t = $start; $t < $endTs; $t += 60) {
                $busy[(int) $t] = true;
            }
        }

        $daysOut = [];
        $daysKeys = Schedule::DAYS;

        for ($d = 0; $d < $daysAhead; $d++) {
            $day = $now->copy()->addDays($d)->startOfDay();
            $dayKey = $daysKeys[$day->dayOfWeekIso - 1];
            $slot = $schedule[$dayKey] ?? null;
            if (! $slot || empty($slot['from']) || empty($slot['to'])) {
                continue; // dia cerrado
            }

            [$fromH, $fromM] = array_map('intval', explode(':', $slot['from']));
            [$toH, $toM] = array_map('intval', explode(':', $slot['to']));

            $cursor = $day->copy()->setTime($fromH, $fromM);
            $end = $day->copy()->setTime($toH, $toM);
            $slots = [];

            while ($cursor->copy()->addMinutes($duration) <= $end) {
                // Descartamos slots que ya pasaron hoy
                if ($cursor->lte(Carbon::now($tz))) {
                    $cursor->addMinutes($duration);

                    continue;
                }

                // Chequeo overlap con bookings existentes
                $slotStart = $cursor->getTimestamp();
                $slotEnd = $slotStart + $duration * 60;
                $overlap = false;
                for ($t = $slotStart; $t < $slotEnd; $t += 60) {
                    if (isset($busy[(int) $t])) {
                        $overlap = true;
                        break;
                    }
                }

                if (! $overlap) {
                    $slots[] = $cursor->format('H:i');
                }

                $cursor->addMinutes($duration);
            }

            if (! empty($slots)) {
                $daysOut[] = [
                    'date' => $day->format('Y-m-d'),
                    'label' => mb_strtolower($day->translatedFormat('D d M')), // "lun 27 jul"
                    'weekday' => ucfirst($day->translatedFormat('l')),
                    'slots' => $slots,
                ];
            }
        }

        return $daysOut;
    }
}
