<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avisos del admin al equipo: notas y recordatorios, a un responsable o a
 * varios de golpe.
 *
 * Aterrizan en las notificaciones del destinatario (misma campana, misma
 * pantalla) clasificados por apartado — seguimiento / personal / marketing —
 * para que el agente sepa de qué le están hablando antes de abrirlo.
 *
 * Un aviso con fecha futura es un recordatorio: la fila se crea al instante
 * pero queda oculta hasta su `deliver_at` (ver `AppNotification::delivered()`).
 * Sin cola ni cron: si el servidor se cae, el recordatorio sigue ahí.
 *
 * Admin-only por la ruta (middleware admin.only).
 */
class TeamMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('TeamMessages/Index', [
            'members' => User::where('account_id', $user->account_id)
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'account_role']),
            'categories' => AppNotification::CATEGORIES,
            // Historial de lo enviado: quién lo recibió, si ya lo leyó y si
            // todavía está programado. Se agrupa por envío (mismo título +
            // instante) para no repetir una fila por destinatario.
            'sent' => $this->sentHistory($user),
            'preselected' => $request->query('to'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',
            'category' => ['required', Rule::in(AppNotification::CATEGORIES)],
            'deliver_at' => 'nullable|date|after:now',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'uuid',
        ], [
            'user_ids.required' => 'Elegí al menos un destinatario.',
            'deliver_at.after' => 'Un recordatorio tiene que quedar en el futuro.',
        ]);

        // Solo miembros de la misma cuenta, y nunca uno mismo: mandarse un
        // aviso a sí mismo ensucia la campana del admin sin aportar nada.
        $recipients = User::where('account_id', $user->account_id)
            ->whereIn('id', $validated['user_ids'])
            ->where('id', '!=', $user->id)
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return back()->withErrors(['user_ids' => 'Ninguno de los destinatarios pertenece a tu equipo.']);
        }

        $isReminder = ! empty($validated['deliver_at']);
        $batchId = (string) Str::uuid();

        DB::transaction(function () use ($recipients, $validated, $user, $isReminder, $batchId) {
            foreach ($recipients as $recipientId) {
                AppNotification::create([
                    'account_id' => $user->account_id,
                    'user_id' => $recipientId,
                    'type' => $isReminder ? AppNotification::TYPE_TEAM_REMINDER : AppNotification::TYPE_TEAM_NOTE,
                    'category' => $validated['category'],
                    'title' => $validated['title'],
                    'body' => $validated['body'] ?? null,
                    'deliver_at' => $validated['deliver_at'] ?? null,
                    'sent_by_user_id' => $user->id,
                    'batch_id' => $batchId,
                ]);
            }
        });

        $count = $recipients->count();
        $what = $isReminder ? 'Recordatorio programado' : 'Nota enviada';

        return back()->with('success', "{$what} para {$count} ".($count === 1 ? 'responsable.' : 'responsables.'));
    }

    /**
     * Un envío masivo son N filas con el mismo título y created_at. Se
     * reagrupan para que el historial muestre "1 aviso a 4 personas" y no
     * cuatro líneas iguales.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sentHistory(User $user): array
    {
        return AppNotification::forAccount($user->account_id)
            ->where('sent_by_user_id', $user->id)
            ->with('recipient:id,name')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->groupBy(fn (AppNotification $n) => $n->batch_id ?? $n->id)
            ->take(30)
            ->map(fn ($group) => [
                'title' => $group->first()->title,
                'body' => $group->first()->body,
                'category' => $group->first()->category,
                'created_at' => $group->first()->created_at,
                'deliver_at' => $group->first()->deliver_at,
                'pending' => $group->first()->deliver_at?->isFuture() ?? false,
                'recipients' => $group->count(),
                'read' => $group->filter(fn ($n) => $n->read_at !== null)->count(),
                'names' => $group->map(fn ($n) => $n->recipient?->name)->filter()->values()->all(),
            ])
            ->values()
            ->all();
    }
}
