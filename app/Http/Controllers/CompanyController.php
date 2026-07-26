<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        return Inertia::render('Companies/Index', [
            'companies' => Company::forAccount($accountId)
                ->with('tags:id,name,color')
                ->withCount(['contacts', 'leads as open_leads_count' => fn ($q) => $q->where('status', 'open')])
                // Agent solo ve empresas con al menos un lead asignado a él.
                ->when(! $isAdmin, fn ($query) => $query->whereHas('leads', fn ($q) => $q->where('responsible_user_id', $user->id)))
                ->when($request->query('q'), fn ($query, $q) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'allTags' => Tag::forAccount($accountId)->orderBy('name')->get(['id', 'name', 'color']),
            'customFields' => CustomField::forAccount($accountId)
                ->where('entity', 'company')->orderBy('position')->get(),
            'filters' => $request->only(['q']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = Company::create([...$this->validated($request), 'account_id' => $request->user()->account_id]);
        $this->syncExtras($request, $company);

        return back()->with('success', 'Empresa creada.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_if($company->account_id !== $request->user()->account_id, 403);
        $company->update($this->validated($request));
        $this->syncExtras($request, $company);

        return back()->with('success', 'Empresa actualizada.');
    }

    private function syncExtras(Request $request, Company $company): void
    {
        $extras = $request->validate([
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'uuid',
            'custom_values' => 'nullable|array',
            'custom_values.*' => 'nullable|string|max:1000',
        ]);

        if (($extras['tag_ids'] ?? null) !== null) {
            $valid = Tag::forAccount($company->account_id)
                ->whereIn('id', $extras['tag_ids'])
                ->pluck('id');
            $company->tags()->sync($valid);
        }

        if (($extras['custom_values'] ?? null) !== null) {
            $company->syncCustomFieldValues($extras['custom_values'], 'company');
        }
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        abort_if($company->account_id !== $request->user()->account_id, 403);
        $company->delete();

        return back()->with('success', 'Empresa eliminada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
        ]);
    }
}
