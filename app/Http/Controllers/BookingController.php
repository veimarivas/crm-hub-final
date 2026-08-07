<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use App\Services\Booking\SlotCalculator;
use App\Services\Wacrm\Client;
use App\Services\WhatsApp\ServiceWindow;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    // ---------- PUBLICO (sin auth) ----------

    /** Página de reserva pública en /book/{slug} */
    public function publicShow(string $slug): Response
    {
        $host = User::where('booking_slug', $slug)->where('booking_enabled', true)->firstOrFail();
        $slots = app(SlotCalculator::class)->slotsForHost($host);

        return Inertia::render('Public/BookingPage', [
            'host' => [
                'name' => $host->name,
                'slug' => $host->booking_slug,
                'duration' => $host->booking_duration_min,
            ],
            'timezone' => $host->account->business_hours_timezone ?: 'America/La_Paz',
            'days' => $slots,
        ]);
    }

    /** POST /book/{slug} — crea la reserva + contacto + lead + tarea */
    public function publicStore(Request $request, string $slug): RedirectResponse
    {
        $host = User::where('booking_slug', $slug)->where('booking_enabled', true)->firstOrFail();

        $validated = $request->validate([
            'guest_name' => 'required|string|max:120',
            'guest_phone' => 'required|string|max:32',
            'guest_email' => 'nullable|email|max:150',
            'notes' => 'nullable|string|max:2000',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $tz = $host->account->business_hours_timezone ?: 'America/La_Paz';
        $scheduledAt = Carbon::parse($validated['scheduled_at'], $tz)->utc();

        // Dedup: mismo host + mismo slot ya ocupado
        $exists = Booking::where('host_user_id', $host->id)
            ->where('status', 'confirmed')
            ->where('scheduled_at', $scheduledAt)
            ->exists();
        abort_if($exists, 409, 'Ese horario ya fue reservado. Elegí otro.');

        $phoneNorm = preg_replace('/[^\d]/', '', $validated['guest_phone']);

        DB::transaction(function () use ($host, $validated, $scheduledAt, $phoneNorm, &$booking) {
            // Contacto (dedup por phone_normalized)
            $contact = Contact::firstOrCreate(
                ['account_id' => $host->account_id, 'phone_normalized' => $phoneNorm],
                ['name' => $validated['guest_name'], 'phone' => $validated['guest_phone'], 'email' => $validated['guest_email'] ?? null]
            );

            // Si el contacto YA tiene un lead con una conversación WhatsApp
            // activa (wacrm_conversation_id), reusamos ESE lead: así el historial
            // del chat aparece en el lead que se abre desde /leads y no se duplica
            // con el de /inbox. Solo se crea lead nuevo si el contacto no tiene uno.
            $existing = Lead::forAccount($host->account_id)
                ->where('contact_id', $contact->id)
                ->whereNotNull('wacrm_conversation_id')
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $lead = $existing;
                $reused = true;
            } else {
                // Lead nuevo con source=booking en la primera etapa open del pipeline default
                $pipeline = Pipeline::forAccount($host->account_id)->where('is_default', true)->first()
                    ?? Pipeline::forAccount($host->account_id)->first();
                $stage = $pipeline?->stages()->where('stage_type', 'open')->orderBy('position')->first();

                $lead = Lead::create([
                    'account_id' => $host->account_id,
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stage->id,
                    'contact_id' => $contact->id,
                    'responsible_user_id' => $host->id,
                    'title' => 'Reunión: '.$validated['guest_name'],
                    'source' => 'booking',
                ]);
                $reused = false;
            }

            // Tarea "meet" con due_at = scheduled_at
            $task = Task::create([
                'account_id' => $host->account_id,
                'lead_id' => $lead->id,
                'contact_id' => $contact->id,
                'assigned_to' => $host->id,
                'created_by' => $host->id,
                'task_type' => 'meet',
                'text' => 'Reunión agendada con '.$validated['guest_name'].(($validated['notes'] ?? '') ? ' — '.$validated['notes'] : ''),
                'due_at' => $scheduledAt,
            ]);

            $booking = Booking::create([
                'account_id' => $host->account_id,
                'host_user_id' => $host->id,
                'contact_id' => $contact->id,
                'lead_id' => $lead->id,
                'task_id' => $task->id,
                'guest_name' => $validated['guest_name'],
                'guest_phone' => $validated['guest_phone'],
                'guest_email' => $validated['guest_email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'scheduled_at' => $scheduledAt,
                'duration_min' => $host->booking_duration_min,
                'status' => 'confirmed',
            ]);

            $lead->recordEvent(
                $reused ? 'booking' : 'created',
                null,
                ['source' => 'booking', 'scheduled_at' => $scheduledAt->toIso8601String()],
            );

            // Confirmación por WhatsApp. Solo se envía si la ventana de
            // servicio está abierta (24 h, o 72 h si vino de un anuncio):
            // fuera de ella Meta cobra el texto libre, así que NO se corre el
            // riesgo. En ese caso se deja una tarea en el lead (o se crea el
            // lead con esa tarea si el contacto aún no tenía conversación).
            $tzLocal = $host->account->business_hours_timezone ?: 'America/La_Paz';
            $scheduledLocal = $scheduledAt->timezone($tzLocal);
            $fechaLegible = $scheduledLocal->translatedFormat('d/m/Y');
            $horaLegible = $scheduledLocal->format('H:i');

            $window = app(ServiceWindow::class)->forLead($lead);
            $integration = $host->account->integration;

            $confirmNeeded = $window['is_open'] ?? false;

            if ($confirmNeeded && $integration?->is_active && $lead->contact?->phone) {
                try {
                    Client::for($integration)->sendMessage(
                        $lead->contact->phone_normalized ?? $lead->contact->phone,
                        'Se registró la reunión agendada para el '.$fechaLegible.' a las '.$horaLegible.'.',
                    );
                } catch (\RuntimeException $e) {
                    Task::create([
                        'account_id' => $host->account_id,
                        'lead_id' => $lead->id,
                        'contact_id' => $contact->id,
                        'assigned_to' => $host->id,
                        'created_by' => $host->id,
                        'task_type' => 'call',
                        'text' => 'No se envió la confirmación de la reserva por WhatsApp (error de envío). Reunión agendada para el '.$fechaLegible.' a las '.$horaLegible.'.',
                        'due_at' => $scheduledAt,
                    ]);
                }
            } elseif (! $confirmNeeded) {
                Task::create([
                    'account_id' => $host->account_id,
                    'lead_id' => $lead->id,
                    'contact_id' => $contact->id,
                    'assigned_to' => $host->id,
                    'created_by' => $host->id,
                    'task_type' => 'call',
                    'text' => 'No se envió la confirmación de la reserva: fuera de la ventana de servicio (24/72 h). Reunión agendada para el '.$fechaLegible.' a las '.$horaLegible.'.',
                    'due_at' => $scheduledAt,
                ]);
            }

            AppNotification::notify(
                $host->account_id,
                $host->id,
                'booking_created',
                'Nueva reunión agendada',
                $validated['guest_name'].' reservó para el '.$scheduledAt->timezone($host->account->business_hours_timezone ?: 'America/La_Paz')->translatedFormat('D d M, H:i'),
                $lead->id,
            );
        });

        return redirect()->route('book.confirmed', ['slug' => $slug, 'when' => $scheduledAt->timestamp]);
    }

    /** Confirmación pública */
    public function publicConfirmed(Request $request, string $slug): Response
    {
        $host = User::where('booking_slug', $slug)->firstOrFail();
        $when = Carbon::createFromTimestamp((int) $request->query('when'), $host->account->business_hours_timezone ?: 'America/La_Paz');

        return Inertia::render('Public/BookingConfirmed', [
            'host' => ['name' => $host->name],
            'scheduled_at' => $when->translatedFormat('l d \\d\\e F, H:i'),
        ]);
    }

    // ---------- ADMIN ----------

    /** Lista de reservas del user (o de todos si es admin y ?all=1) */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);
        $showAll = $isAdmin && $request->boolean('all');

        $bookings = Booking::forAccount($user->account_id)
            ->with(['host:id,name', 'contact:id,name', 'lead:id,title'])
            ->when(! $showAll, fn ($q) => $q->where('host_user_id', $user->id))
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get();

        return Inertia::render('Bookings/Index', [
            'bookings' => $bookings,
            'showAll' => $showAll,
            'isAdmin' => $isAdmin,
            'bookingUrl' => $user->booking_slug ? route('book.show', $user->booking_slug) : null,
            'bookingEnabled' => (bool) $user->booking_enabled,
            'slug' => $user->booking_slug,
        ]);
    }

    /** Cancelar una reserva */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        abort_if($booking->account_id !== $request->user()->account_id, 403);
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);
        abort_if(! $isAdmin && $booking->host_user_id !== $request->user()->id, 403);

        $booking->update(['status' => 'cancelled']);
        if ($booking->task_id) {
            Task::whereKey($booking->task_id)->update(['completed_at' => now(), 'result_note' => 'Cancelada']);
        }

        return back()->with('success', 'Reserva cancelada.');
    }
}
