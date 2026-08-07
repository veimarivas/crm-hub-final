<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\StageAutomation;
use App\Models\User;
use App\Services\DigitalPipeline\Recipes;
use App\Services\DigitalPipeline\Simulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Digital Pipeline: acciones automáticas al entrar un lead a una etapa. */
class StageAutomationController extends Controller
{
    public function index(Request $request, Pipeline $pipeline): Response
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $stages = $pipeline->stages()->get();

        // Cuántos leads hay hoy en cada etapa: dimensiona a cuántos
        // alcanzaría lo que se configure acá.
        $leadCounts = Lead::forAccount($pipeline->account_id)
            ->whereIn('stage_id', $stages->pluck('id'))
            ->selectRaw('stage_id, COUNT(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id');

        return Inertia::render('Pipelines/Automations', [
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'stages' => $stages->map(fn (PipelineStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'stage_type' => $stage->stage_type,
                'leads_count' => (int) ($leadCounts[$stage->id] ?? 0),
                'automations' => StageAutomation::forAccount($pipeline->account_id)
                    ->where('stage_id', $stage->id)
                    ->orderBy('created_at')
                    ->get(),
            ]),
            'members' => User::where('account_id', $pipeline->account_id)->get(['id', 'name']),
            'whatsappEnabled' => (bool) $request->user()->account->integration?->is_active,
            'recipes' => Recipes::gallery(),
            // Para el panel de prueba: leads reales sobre los que previsualizar.
            'sampleLeads' => Lead::forAccount($pipeline->account_id)
                ->with('contact:id,name,phone')
                    ->orderByDesc('updated_at')
                ->limit(30)
                ->get(['id', 'title', 'contact_id', 'value', 'stage_id', 'responsible_user_id'])
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'contact' => $lead->contact?->name,
                    'phone' => $lead->contact?->phone,
                ]),
        ]);
    }

    public function store(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'stage_id' => 'required|uuid',
            'action_type' => ['required', Rule::in(StageAutomation::ACTIONS)],
            'config' => 'required|array',
            'config.text' => 'required|string|max:2000',
            'config.task_type' => 'nullable|in:call,meet,follow_up,email,other',
            'config.due_in_hours' => 'nullable|integer|between:1,720',
            'config.assigned_to' => 'nullable|uuid|exists:users,id',
        ]);

        // La etapa debe ser de este pipeline.
        abort_unless($pipeline->stages()->where('id', $validated['stage_id'])->exists(), 422);

        StageAutomation::create([
            'account_id' => $pipeline->account_id,
            'stage_id' => $validated['stage_id'],
            'action_type' => $validated['action_type'],
            'config' => $this->cleanConfig($validated['config']),
        ]);

        return back()->with('success', 'Acción creada.');
    }

    /**
     * Editar en el sitio. Antes solo se podía crear y borrar: corregir
     * una palabra de un mensaje obligaba a rehacer la automatización, y
     * de paso se perdía el contador de ejecuciones.
     */
    public function update(Request $request, StageAutomation $automation): RedirectResponse
    {
        abort_if($automation->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'action_type' => ['required', Rule::in(StageAutomation::ACTIONS)],
            'config' => 'required|array',
            'config.text' => 'required|string|max:2000',
            'config.task_type' => 'nullable|in:call,meet,follow_up,email,other',
            'config.due_in_hours' => 'nullable|integer|between:1,720',
            'config.assigned_to' => 'nullable|uuid|exists:users,id',
        ]);

        $automation->update([
            'action_type' => $validated['action_type'],
            'config' => $this->cleanConfig($validated['config']),
        ]);

        return back()->with('success', 'Acción actualizada.');
    }

    /** Aplica una plantilla a una etapa: crea todas sus acciones de una. */
    public function applyRecipe(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'stage_id' => 'required|uuid',
            'recipe' => ['required', 'string', Rule::in(array_column(Recipes::all(), 'slug'))],
        ]);

        abort_unless($pipeline->stages()->where('id', $validated['stage_id'])->exists(), 422);

        $recipe = Recipes::find($validated['recipe']);

        DB::transaction(function () use ($pipeline, $validated, $recipe) {
            foreach ($recipe['actions'] as $action) {
                StageAutomation::create([
                    'account_id' => $pipeline->account_id,
                    'stage_id' => $validated['stage_id'],
                    'action_type' => $action['action_type'],
                    'config' => $action['config'],
                ]);
            }
        });

        $count = count($recipe['actions']);

        return back()->with('success', "Plantilla «{$recipe['title']}» aplicada: {$count} ".($count === 1 ? 'acción creada' : 'acciones creadas').'.');
    }

    /**
     * Vista previa de lo que pasaría si un lead entrara a esta etapa.
     * No ejecuta nada.
     */
    public function simulate(Request $request, Pipeline $pipeline, Simulator $simulator): JsonResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);

        // Ruta del grupo web: un validate() fallido redirige en vez de
        // devolver 422, y axios recibiría HTML. Se arma el 422 a mano.
        $validator = Validator::make($request->all(), [
            'stage_id' => 'required|uuid',
            'lead_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();
        $stage = $pipeline->stages()->find($validated['stage_id']);

        if (! $stage) {
            return response()->json(['message' => 'Esa etapa no es de este pipeline.'], 422);
        }

        $lead = ! empty($validated['lead_id'])
            ? Lead::forAccount($pipeline->account_id)->with('contact', 'stage')->find($validated['lead_id'])
            : null;

        return response()->json($simulator->preview($stage, $lead));
    }

    public function toggle(Request $request, StageAutomation $automation): RedirectResponse
    {
        abort_if($automation->account_id !== $request->user()->account_id, 403);

        $automation->update(['is_active' => ! $automation->is_active]);

        return back();
    }

    public function destroy(Request $request, StageAutomation $automation): RedirectResponse
    {
        abort_if($automation->account_id !== $request->user()->account_id, 403);
        $automation->delete();

        return back()->with('success', 'Acción eliminada.');
    }

    /** Los campos vacíos no se guardan: `assigned_to: ''` rompería el fallback al responsable del lead. */
    private function cleanConfig(array $config): array
    {
        return array_filter($config, fn ($v) => $v !== null && $v !== '');
    }
}
