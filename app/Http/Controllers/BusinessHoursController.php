<?php

namespace App\Http\Controllers;

use App\Services\BusinessHours\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessHoursController extends Controller
{
    public function edit(Request $request): Response
    {
        $account = $request->user()->account;
        $schedule = $account->business_hours_schedule ?: Schedule::DEFAULT_SCHEDULE;

        // Normalizar: asegurar que cada dia tiene la clave (aunque valor null)
        foreach (Schedule::DAYS as $d) {
            if (! array_key_exists($d, $schedule)) {
                $schedule[$d] = null;
            }
        }

        return Inertia::render('Settings/BusinessHours', [
            'settings' => [
                'business_hours_enabled' => (bool) $account->business_hours_enabled,
                'out_of_hours_reply_enabled' => (bool) $account->out_of_hours_reply_enabled,
                'out_of_hours_message' => $account->out_of_hours_message ?: Schedule::DEFAULT_MESSAGE,
                'business_hours_timezone' => $account->business_hours_timezone ?: 'America/La_Paz',
                'schedule' => $schedule,
            ],
            'isOpenNow' => app(Schedule::class)->isOpenNow($account),
            'timezones' => [
                'America/La_Paz', 'America/Lima', 'America/Bogota', 'America/Santiago',
                'America/Buenos_Aires', 'America/Mexico_City', 'America/Guayaquil',
                'America/Caracas', 'America/Panama', 'America/Asuncion', 'Europe/Madrid', 'UTC',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_hours_enabled' => 'required|boolean',
            'out_of_hours_reply_enabled' => 'required|boolean',
            'out_of_hours_message' => 'nullable|string|max:1000',
            'business_hours_timezone' => 'required|string|max:64',
            'schedule' => 'nullable|array',
            'schedule.*.from' => 'nullable|regex:/^\d{2}:\d{2}$/',
            'schedule.*.to' => 'nullable|regex:/^\d{2}:\d{2}$/',
        ]);

        // Filtrar solo dias validos y normalizar (dia sin from/to = null = cerrado)
        $clean = [];
        foreach (Schedule::DAYS as $day) {
            $slot = $validated['schedule'][$day] ?? null;
            if ($slot && ! empty($slot['from']) && ! empty($slot['to'])) {
                $clean[$day] = ['from' => $slot['from'], 'to' => $slot['to']];
            } else {
                $clean[$day] = null;
            }
        }

        $account = $request->user()->account;
        $account->fill([
            'business_hours_enabled' => $validated['business_hours_enabled'],
            'out_of_hours_reply_enabled' => $validated['out_of_hours_reply_enabled'],
            'out_of_hours_message' => $validated['out_of_hours_message'] ?? null,
            'business_hours_timezone' => $validated['business_hours_timezone'],
            'business_hours_schedule' => $clean,
        ])->save();

        return back();
    }
}
