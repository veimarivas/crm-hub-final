<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Settings/Pipelines', [
            'pipelines' => Pipeline::forAccount($accountId)
                ->with(['stages' => fn ($q) => $q->orderBy('position')])
                ->withCount('leads')
                ->orderBy('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);
        $accountId = $request->user()->account_id;

        DB::transaction(function () use ($validated, $accountId) {
            $pipeline = Pipeline::create([
                'account_id' => $accountId,
                'name' => $validated['name'],
                'is_default' => false,
            ]);

            // Etapas base al crear: Nuevo (open) + Ganado (won) + Perdido (lost)
            PipelineStage::insert([
                ['id' => (string) \Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'color' => '#0ea5e9', 'position' => 1, 'stage_type' => 'open', 'created_at' => now(), 'updated_at' => now()],
                ['id' => (string) \Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Ganado', 'color' => '#10b981', 'position' => 100, 'stage_type' => 'won', 'created_at' => now(), 'updated_at' => now()],
                ['id' => (string) \Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Perdido', 'color' => '#ef4444', 'position' => 101, 'stage_type' => 'lost', 'created_at' => now(), 'updated_at' => now()],
            ]);
        });

        return back()->with('success', 'Pipeline creado.');
    }

    public function update(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'is_default' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($pipeline, $validated) {
            if (! empty($validated['is_default'])) {
                Pipeline::forAccount($pipeline->account_id)->update(['is_default' => false]);
            }
            $pipeline->update([
                'name' => $validated['name'],
                'is_default' => $validated['is_default'] ?? $pipeline->is_default,
            ]);
        });

        return back()->with('success', 'Pipeline actualizado.');
    }

    public function destroy(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);
        abort_if($pipeline->is_default, 422, 'No se puede borrar el pipeline default. Marca otro como default primero.');
        abort_if($pipeline->leads()->exists(), 422, 'Este pipeline tiene leads — muévelos o bórralos antes.');

        $pipeline->delete();

        return redirect()->route('settings.pipelines')->with('success', 'Pipeline eliminado.');
    }

    // ---- Stages ----

    public function storeStage(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $maxOpenPos = (int) $pipeline->stages()->where('stage_type', 'open')->max('position');
        PipelineStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => $validated['name'],
            'color' => $validated['color'],
            'position' => $maxOpenPos + 1,
            'stage_type' => 'open',
        ]);

        return back()->with('success', 'Etapa creada.');
    }

    public function updateStage(Request $request, PipelineStage $stage): RedirectResponse
    {
        abort_if($stage->pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $stage->update($validated);

        return back()->with('success', 'Etapa actualizada.');
    }

    public function destroyStage(Request $request, PipelineStage $stage): RedirectResponse
    {
        abort_if($stage->pipeline->account_id !== $request->user()->account_id, 403);
        abort_if($stage->stage_type !== 'open', 422, 'No se pueden borrar etapas Ganado/Perdido — son requeridas.');
        abort_if($stage->leads()->exists(), 422, 'Esta etapa tiene leads — muévelos primero.');

        $stage->delete();

        return back()->with('success', 'Etapa eliminada.');
    }

    public function reorderStages(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'uuid',
        ]);

        // Solo reordena stages open del pipeline (posiciones 1..N).
        // Won/Lost quedan en posiciones 100/101 sin tocar.
        $openStages = $pipeline->stages()->where('stage_type', 'open')->pluck('id')->all();
        $validIds = array_intersect($validated['order'], $openStages);

        DB::transaction(function () use ($validIds) {
            foreach ($validIds as $index => $id) {
                PipelineStage::where('id', $id)->update(['position' => $index + 1]);
            }
        });

        return back()->with('success', 'Orden guardado.');
    }
}
