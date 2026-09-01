<?php

namespace App\Http\Controllers;

use App\Jobs\SyncTaxonomyToWacrmJob;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(Request $request): Response
    {
        $fields = CustomField::forAccount($request->user()->account_id)
            ->orderBy('position')
            ->get()
            ->groupBy('entity');

        return Inertia::render('Settings/CustomFields', [
            'fields' => [
                'lead' => $fields->get('lead', collect()),
                'contact' => $fields->get('contact', collect()),
                'company' => $fields->get('company', collect()),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entity' => ['required', Rule::in(CustomField::ENTITIES)],
            'name' => 'required|string|max:60',
            'field_type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => 'nullable|array|max:20',
            'options.*' => 'string|max:100',
        ]);

        $accountId = $request->user()->account_id;

        CustomField::create([
            ...$validated,
            'account_id' => $accountId,
            'options' => $validated['field_type'] === 'select' ? ($validated['options'] ?? []) : null,
            'position' => (CustomField::forAccount($accountId)->where('entity', $validated['entity'])->max('position') ?? -1) + 1,
        ]);

        // Solo los campos de CONTACTO existen del otro lado (allá cuelgan de
        // `ContactCustomValue`): uno de lead o de empresa sería una columna que
        // nadie podría llenar nunca.
        if ($validated['entity'] === 'contact') {
            SyncTaxonomyToWacrmJob::dispatch($accountId);
        }

        return back()->with('success', 'Campo creado.');
    }

    public function destroy(Request $request, CustomField $customField): RedirectResponse
    {
        abort_if($customField->account_id !== $request->user()->account_id, 403);

        $accountId = $customField->account_id;
        $entity = $customField->entity;

        $customField->delete(); // los valores caen por FK cascade

        if ($entity === 'contact') {
            SyncTaxonomyToWacrmJob::dispatch($accountId);
        }

        return back()->with('success', 'Campo eliminado.');
    }
}
