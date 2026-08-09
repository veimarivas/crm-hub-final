<?php

namespace App\Http\Controllers;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Services\Email\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conexión de casillas corporativas de Google Workspace (T6).
 *
 * Cada usuario conecta **su propia** casilla: el token que se guarda da acceso
 * a ese correo, y un admin conectando la casilla de otro sería darle acceso a
 * la correspondencia ajena sin que se entere.
 */
class EmailAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Settings/Email', [
            'mailboxes' => EmailAccount::forAccount($accountId)
                ->with('owner:id,name')
                ->orderBy('email')
                ->get()
                ->map(fn (EmailAccount $m) => [
                    'id' => $m->id,
                    'email' => $m->email,
                    'owner' => $m->owner?->name,
                    'is_mine' => $m->user_id === $request->user()->id,
                    'is_active' => $m->is_active,
                    'last_synced_at' => $m->last_synced_at?->toIso8601String(),
                    'last_error' => $m->last_error,
                    // Sin refresh token la casilla muere en una hora: hay que
                    // decirlo antes de que deje de sincronizar en silencio.
                    'needs_reconnect' => ! $m->refresh_token,
                ]),
            'configured' => (bool) config('services.google.client_id'),
            'domain' => config('services.google.workspace_domain'),
        ]);
    }

    /** Manda al usuario a autorizar en Google. */
    public function connect(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $state = Str::random(40);
        // El `state` va en sesión y se compara al volver: sin eso, cualquiera
        // puede inducir a un admin a conectar una casilla que no es suya.
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    /** Vuelta de Google con el código de autorización. */
    public function callback(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        if ($request->query('error')) {
            return redirect()->route('settings.email')
                ->with('error', 'Se canceló la conexión con Google.');
        }

        $expected = $request->session()->pull('google_oauth_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('settings.email')
                ->with('error', 'La respuesta de Google no coincide con la solicitud. Probá de nuevo.');
        }

        try {
            $tokens = $oauth->exchangeCode((string) $request->query('code'));
        } catch (\RuntimeException $e) {
            return redirect()->route('settings.email')->with('error', $e->getMessage());
        }

        if (! $oauth->belongsToWorkspace($tokens['email'])) {
            return redirect()->route('settings.email')->with(
                'error',
                'Esa casilla no pertenece al dominio de la institución ('.config('services.google.workspace_domain').').',
            );
        }

        $mailbox = EmailAccount::updateOrCreate(
            ['account_id' => $request->user()->account_id, 'email' => $tokens['email']],
            array_filter([
                'user_id' => $request->user()->id,
                'provider' => 'google',
                'access_token' => $tokens['access_token'],
                // Google no reenvía el refresh token si ya lo dio antes: se
                // conserva el guardado en vez de pisarlo con null.
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'is_active' => true,
                'last_error' => null,
            ], fn ($v) => $v !== null),
        );

        // La primera pasada solo fija el punto de partida: no importa meses de
        // correo viejo al timeline.
        SyncEmailAccountJob::dispatch($mailbox->id);

        return redirect()->route('settings.email')
            ->with('success', "Casilla {$tokens['email']} conectada.");
    }

    public function sync(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $this->authorizeMailbox($request, $emailAccount);

        SyncEmailAccountJob::dispatch($emailAccount->id);

        return back()->with('success', 'Sincronización en curso.');
    }

    public function destroy(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $this->authorizeMailbox($request, $emailAccount);
        $emailAccount->delete();

        return back()->with('success', 'Casilla desconectada.');
    }

    /**
     * Solo el dueño de la casilla la administra.
     *
     * Un admin puede ver que existe, pero no sincronizarla ni desconectarla:
     * es correspondencia de otra persona.
     */
    private function authorizeMailbox(Request $request, EmailAccount $mailbox): void
    {
        abort_if($mailbox->account_id !== $request->user()->account_id, 403);
        abort_if($mailbox->user_id !== $request->user()->id, 403, 'Solo el dueño de la casilla puede administrarla.');
    }
}
