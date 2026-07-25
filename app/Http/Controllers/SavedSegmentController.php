<?php

namespace App\Http\Controllers;

use App\Models\SavedSegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedSegmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'filters' => 'required|array',
            'is_shared' => 'nullable|boolean',
        ]);

        SavedSegment::create([
            'account_id' => $request->user()->account_id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'filters' => $validated['filters'],
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
