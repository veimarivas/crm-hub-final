<?php

namespace App\Http\Controllers;

use App\Models\SavedSegment;
use App\Services\Leads\LeadFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SavedSegmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'filters' => 'required|array',
            'is_shared' => 'nullable|boolean',
        ]);

        // Los filtros se normalizan ANTES de guardarse: una lista con una clave
        // que nadie sabe interpretar es la forma en que este JSON se degradó la
        // primera vez. Guardar ya normalizado deja `filters` con una sola forma
        // posible, y de paso descarta los vacíos que arrastraba el formulario.
        try {
            $filters = LeadFilter::normalize($validated['filters']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['filters' => $e->getMessage()]);
        }

        SavedSegment::create([
            'account_id' => $request->user()->account_id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'filters' => $filters,
            'is_shared' => $validated['is_shared'] ?? false,
        ]);

        return back()->with('success', 'Lista guardada.');
    }

    public function destroy(Request $request, SavedSegment $savedSegment): RedirectResponse
    {
        abort_if($savedSegment->account_id !== $request->user()->account_id, 403);
        abort_if($savedSegment->user_id !== $request->user()->id, 403, 'Solo el creador puede borrar la lista.');
        $savedSegment->delete();

        return back()->with('success', 'Lista eliminada.');
    }
}
