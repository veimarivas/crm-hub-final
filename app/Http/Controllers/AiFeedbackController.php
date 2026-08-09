<?php

namespace App\Http\Controllers;

use App\Jobs\SendAiFeedbackJob;
use App\Models\AiFeedback;
use App\Models\Lead;
use App\Models\LeadEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Captura del feedback sobre las respuestas de la IA, desde el chat del lead.
 *
 * Se captura **acá y no en una pantalla de configuración** porque el agente
 * que ve la respuesta mala es el único que tiene el contexto para arreglarla,
 * y lo tiene justo en ese momento. Pedirle que después vaya a otra pantalla a
 * reportarlo es garantizar que no lo haga.
 *
 * Guardar es local e inmediato; el envío al wacrm va en cola. Si el envío
 * fuera sincrónico y el wacrm estuviera caído, el agente escribiría la
 * corrección y se perdería.
 */
class AiFeedbackController extends Controller
{
    public function store(Request $request, Lead $lead): RedirectResponse
    {
        abort_if($lead->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'lead_event_id' => 'required|uuid',
            'rating' => ['required', Rule::in([AiFeedback::UP, AiFeedback::DOWN])],
            'correction' => 'nullable|string|max:5000',
        ]);

        // El evento tiene que ser de este lead y ser un saliente de la IA:
        // corregir un mensaje que escribió una persona no tiene sentido.
        $event = LeadEvent::where('lead_id', $lead->id)
            ->where('event_type', 'message_out')
            ->findOrFail($validated['lead_event_id']);

        abort_unless(($event->payload['sender'] ?? null) === 'bot', 422,
            'Ese mensaje no lo escribió la IA.');

        $feedback = AiFeedback::updateOrCreate(
            ['lead_event_id' => $event->id, 'user_id' => $request->user()->id],
            [
                'account_id' => $lead->account_id,
                'lead_id' => $lead->id,
                'rating' => $validated['rating'],
                'correction' => $validated['correction'] ?? null,
                // Cambiar el voto lo vuelve a poner en cola: allá se reabre la
                // revisión, así que acá no puede quedar marcado como enviado.
                'synced_at' => null,
            ],
        );

        SendAiFeedbackJob::dispatch($feedback->id);

        return back()->with('success', $validated['rating'] === AiFeedback::DOWN
            ? 'Gracias: la corrección va a revisión antes de enseñarle a la IA.'
            : 'Gracias por la señal.');
    }
}
