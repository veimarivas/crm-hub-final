<?php

namespace App\Http\Controllers;

use App\Jobs\SyncTaxonomyToWacrmJob;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Desde D2 este catálogo es el ÚNICO: cada cambio se replica al wacrm, que
 * hasta ahora tenía etiquetas propias sin relación con estas. Una etiqueta
 * puesta en el inbox no existía acá y viceversa.
 */
class TagController extends Controller
{
    /** Paleta fija: elegir color a mano termina en 30 grises distintos. */
    public const PALETTE = [
        '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
        '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#64748b',
    ];

    /**
     * Administración de etiquetas.
     *
     * Hasta ahora solo se podían crear al vuelo desde la ficha de un lead, así
     * que no había dónde ver las que existen, renombrar una mal escrita ni
     * saber cuántos leads la usan antes de borrarla.
     */
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Tags/Index', [
            'tags' => Tag::forAccount($accountId)
                ->withCount(['leads', 'contacts', 'companies'])
                ->orderBy('name')
                ->get(),
            'palette' => self::PALETTE,
            'newLeadTag' => Tag::NEW_LEAD,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]);

        $accountId = $request->user()->account_id;

        // Idempotente por nombre dentro de la cuenta: dos «Nuevo» que se ven
        // igual y filtran distinto son un problema silencioso, y ahora las
        // etiquetas se crean desde tres pantallas distintas.
        $existing = Tag::forAccount($accountId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])
            ->first();

        if ($existing) {
            return back()->with('success', "La etiqueta «{$existing->name}» ya existía.");
        }

        Tag::create([
            'account_id' => $accountId,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#10b981',
        ]);

        SyncTaxonomyToWacrmJob::dispatch($accountId);

        return back()->with('success', 'Etiqueta creada.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        abort_if($tag->account_id !== $request->user()->account_id, 403);

        $tag->update($request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]));

        SyncTaxonomyToWacrmJob::dispatch($tag->account_id);

        return back()->with('success', 'Etiqueta actualizada.');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        abort_if($tag->account_id !== $request->user()->account_id, 403);

        $accountId = $tag->account_id;
        $tag->delete();

        // Del otro lado, borrarla NO es incondicional: si allá está en uso
        // (etiqueta contactos o alimenta una regla de auto-etiquetado) se
        // desvincula y sobrevive como etiqueta local. Borrarla en cascada
        // habría roto el auto-etiquetado del wacrm sin un solo aviso.
        SyncTaxonomyToWacrmJob::dispatch($accountId);

        return back()->with('success', 'Etiqueta eliminada.');
    }
}
