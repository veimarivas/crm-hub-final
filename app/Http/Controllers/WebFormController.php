<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\WebForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebFormController extends Controller
{
    // ---- Administración ----

    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Settings/WebForms', [
            'forms' => WebForm::forAccount($accountId)
                ->with('pipeline:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($form) => [...$form->toArray(), 'public_url' => route('webforms.show', $form->token)]),
            'pipelines' => Pipeline::forAccount($accountId)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'pipeline_id' => 'required|uuid',
            'headline' => 'nullable|string|max:255',
        ]);

        $pipeline = Pipeline::forAccount($request->user()->account_id)->findOrFail($validated['pipeline_id']);

        WebForm::create([
            'account_id' => $request->user()->account_id,
            'pipeline_id' => $pipeline->id,
            'name' => $validated['name'],
            'headline' => $validated['headline'] ?? null,
            'token' => Str::lower(Str::random(24)),
        ]);

        return back()->with('success', 'Formulario creado.');
    }

    public function toggle(Request $request, WebForm $webForm): RedirectResponse
    {
        abort_if($webForm->account_id !== $request->user()->account_id, 403);
        $webForm->update(['is_active' => ! $webForm->is_active]);

        return back();
    }

    public function destroy(Request $request, WebForm $webForm): RedirectResponse
    {
        abort_if($webForm->account_id !== $request->user()->account_id, 403);
        $webForm->delete();

        return back()->with('success', 'Formulario eliminado.');
    }

    // ---- Público (sin auth) ----

    public function show(string $token)
    {
        $form = WebForm::where('token', $token)->where('is_active', true)->firstOrFail();

        return view('webform', ['form' => $form, 'sent' => session('webform_sent')]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $form = WebForm::where('token', $token)->where('is_active', true)->firstOrFail();

        // Honeypot: los bots rellenan el campo oculto "website".
        if ($request->filled('website')) {
            return back()->with('webform_sent', true); // finge éxito
        }

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:2000',
            'utm_source' => 'nullable|string|max:120',
            'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:191',
            'utm_content' => 'nullable|string|max:191',
            'utm_term' => 'nullable|string|max:191',
            'gclid' => 'nullable|string|max:191',
            'fbclid' => 'nullable|string|max:191',
            'ttclid' => 'nullable|string|max:191',
            'msclkid' => 'nullable|string|max:191',
            'landing_url' => 'nullable|string|max:2048',
            'referrer_url' => 'nullable|string|max:2048',
        ]);

        $tracking = array_intersect_key($validated, array_flip(Lead::TRACKING_FIELDS));

        $normalized = Contact::normalizePhone($validated['phone']);

        $contact = Contact::forAccount($form->account_id)
            ->where('phone_normalized', $normalized)
            ->first()
            ?? Contact::create([
                'account_id' => $form->account_id,
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
            ]);

        $firstStage = $form->pipeline->stages()->where('stage_type', 'open')->orderBy('position')->first();

        if ($firstStage) {
            $lead = Lead::create([
                'account_id' => $form->account_id,
                'pipeline_id' => $form->pipeline_id,
                'stage_id' => $firstStage->id,
                'contact_id' => $contact->id,
                'title' => 'Web: '.$validated['name'],
                'source' => 'web_form',
            ]);

            $lead->applyTracking($tracking);

            $lead->recordEvent('created', null, array_filter([
                'source' => 'web_form',
                'form' => $form->name,
                'utm_source' => $lead->utm_source,
                'utm_campaign' => $lead->utm_campaign,
            ]));

            AppNotification::notify(
                $form->account_id,
                $form->account->owner_user_id,
                'lead_created_web_form',
                'Nuevo lead del formulario',
                "{$validated['name']} llenó «{$form->name}»",
                $lead->id,
            );

            if ($validated['message'] ?? null) {
                $lead->notes()->create([
                    'account_id' => $form->account_id,
                    'text' => "Mensaje del formulario:\n".$validated['message'],
                ]);
                $lead->recordEvent('note_added', null, ['text' => mb_substr($validated['message'], 0, 200), 'automation' => true]);
            }
        }

        $form->increment('submissions_count');

        return back()->with('webform_sent', true);
    }
}
