<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\SavedSegment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\SegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Segmentos: audiencias definidas por una **pregunta**, no por una lista.
 *
 * La definición se guarda ya validada y en la versión vigente del formato, así
 * que en la base no conviven formas raras. Las listas creadas antes de T4
 * (JSON plano) se leen igual: `SegmentQuery::upgrade()` las sube al vuelo, sin
 * migración de datos y sin dejar a nadie sin sus listas.
 */
class SavedSegmentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        $segments = SavedSegment::forAccount($accountId)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->with('owner:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'filters', 'user_id', 'is_shared', 'updated_at']);

        return Inertia::render('Segments/Index', [
            'segments' => $segments->map(fn (SavedSegment $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'definition' => SegmentQuery::upgrade($s->filters ?? []),
                'is_shared' => $s->is_shared,
                'is_mine' => $s->user_id === $user->id,
                'owner' => $s->owner?->name,
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->all(),
            'catalog' => SegmentQuery::catalog(),
            'options' => $this->optionsFor($accountId, $isAdmin),
            'isAdmin' => $isAdmin,
        ]);
    }

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
            'filters' => $this->definitionOrFail($validated['filters']),
            'is_shared' => $validated['is_shared'] ?? false,
        ]);

        return back()->with('success', 'Lista guardada.');
    }

    public function update(Request $request, SavedSegment $savedSegment): RedirectResponse
    {
        $this->authorizeOwnership($request, $savedSegment);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'filters' => 'required|array',
            'is_shared' => 'nullable|boolean',
        ]);

        $savedSegment->update([
            'name' => $validated['name'],
            'filters' => $this->definitionOrFail($validated['filters']),
            'is_shared' => $validated['is_shared'] ?? false,
        ]);

        return back()->with('success', 'Lista actualizada.');
    }

    public function destroy(Request $request, SavedSegment $savedSegment): RedirectResponse
    {
        $this->authorizeOwnership($request, $savedSegment);
        $savedSegment->delete();

        return back()->with('success', 'Lista eliminada.');
    }

    /**
     * Cuántos leads caen en una definición **ahora mismo**.
     *
     * Es lo que hace visible que el segmento sea dinámico: se edita un
     * criterio y el número se mueve, sin guardar nada. También es la única
     * defensa real contra activar una audiencia sin saber a cuántos alcanza.
     */
    public function count(Request $request): JsonResponse
    {
        try {
            $definition = SegmentQuery::validate($request->input('filters', []));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = $request->user();

        $base = fn () => SegmentQuery::for($user)->apply(
            Lead::forAccount($user->account_id),
            $definition,
        );

        return response()->json([
            'total' => $base()->count(),
            'open' => $base()->where('status', 'open')->count(),
            // Sin teléfono no hay envío posible: decirlo acá evita la sorpresa
            // de un segmento de 300 que termina alcanzando a 40.
            'reachable' => $base()->where('status', 'open')
                ->whereHas('contact', fn ($q) => $q->whereNotNull('phone_normalized'))
                ->count(),
        ]);
    }

    /** Valida la definición y la devuelve canónica, o 422 con el motivo. */
    private function definitionOrFail(array $filters): array
    {
        try {
            return SegmentQuery::validate($filters);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['filters' => $e->getMessage()]);
        }
    }

    /**
     * Editar y borrar es del creador. Compartir da lectura, no control: si
     * cualquiera pudiera reescribir una lista compartida, el resto del equipo
     * mandaría envíos a una audiencia que cambió sin avisar.
     */
    private function authorizeOwnership(Request $request, SavedSegment $segment): void
    {
        abort_if($segment->account_id !== $request->user()->account_id, 403);
        abort_if($segment->user_id !== $request->user()->id, 403, 'Solo el creador puede modificar la lista.');
    }

    /**
     * Valores posibles de los criterios que apuntan a otra tabla, para que el
     * constructor muestre nombres y no UUIDs.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(string $accountId, bool $isAdmin): array
    {
        return [
            'stage' => Pipeline::forAccount($accountId)->with('stages')->get()
                ->flatMap(fn ($p) => $p->stages->map(fn ($s) => [
                    'value' => $s->id, 'label' => "{$p->name} · {$s->name}", 'color' => $s->color,
                ]))->values(),
            'pipeline' => Pipeline::forAccount($accountId)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name]),
            'tag' => Tag::forAccount($accountId)->orderBy('name')
                ->get(['id', 'name', 'color'])->map(fn ($t) => ['value' => $t->id, 'label' => $t->name, 'color' => $t->color]),
            'company' => Company::forAccount($accountId)->orderBy('name')->limit(500)
                ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
            // El desplegable de responsables es del admin: un agente no arma
            // audiencias de la cartera de otro.
            'user' => $isAdmin
                ? User::where('account_id', $accountId)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
                : [],
            'status' => [
                ['value' => 'open', 'label' => 'Abierto'],
                ['value' => 'won', 'label' => 'Ganado'],
                ['value' => 'lost', 'label' => 'Perdido'],
            ],
            'source' => collect([
                'whatsapp' => 'WhatsApp', 'booking' => 'Formulario de reserva',
                'lead_ad' => 'Meta Lead Ad', 'web_form' => 'Formulario web',
                'manual' => 'Manual', 'api' => 'API externa',
            ])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'band' => [
                ['value' => 'caliente', 'label' => 'Caliente'],
                ['value' => 'tibio', 'label' => 'Tibio'],
                ['value' => 'frio', 'label' => 'Frío'],
            ],
        ];
    }
}
