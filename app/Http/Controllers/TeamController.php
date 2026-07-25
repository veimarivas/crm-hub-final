<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Equipo: miembros e invitaciones por link (mismo patrón que el wacrm). */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Team', [
            'members' => User::where('account_id', $user->account_id)
                ->orderBy('created_at')
                ->get(['id', 'name', 'email', 'account_role', 'created_at']),
            'invitations' => AccountInvitation::forAccount($user->account_id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(['id', 'role', 'label', 'expires_at']),
            'apiKeys' => ApiKey::forAccount($user->account_id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'key_prefix', 'scopes', 'last_used_at', 'revoked_at', 'created_at']),
            'apiScopes' => ApiKey::SCOPES,
            'isAdmin' => $user->hasRoleAtLeast(User::ROLE_ADMIN),
            'isOwner' => $user->isOwner(),
            'newInviteUrl' => session('invite_url'),
            'newApiKey' => session('api_key_plaintext'),
            'autoAssignLeads' => (bool) $user->account->auto_assign_leads,
        ]);
    }

    public function toggleAutoAssign(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $account = $request->user()->account;
        $account->auto_assign_leads = ! $account->auto_assign_leads;
        $account->save();

        return back();
    }

    // ---- API keys (consumidas por meta_ads para atribución y Lead Ads) ----

    public function storeApiKey(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1',
            'scopes.*' => Rule::in(ApiKey::SCOPES),
        ]);

        [, $plaintext] = ApiKey::issue(
            $request->user()->account_id,
            $request->user()->id,
            $validated['name'],
            $validated['scopes'],
        );

        return back()->with('api_key_plaintext', $plaintext);
    }

    public function revokeApiKey(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($apiKey->account_id !== $request->user()->account_id, 403);

        $apiKey->update(['revoked_at' => now()]);

        return back()->with('success', 'API key revocada.');
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
            'label' => 'nullable|string|max:100',
        ]);

        $token = Str::random(48);

        AccountInvitation::create([
            'account_id' => $request->user()->account_id,
            'token_hash' => hash('sha256', $token),
            'role' => $validated['role'],
            'label' => $validated['label'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        return back()->with('invite_url', route('invitations.accept', $token));
    }

    public function revokeInvitation(Request $request, AccountInvitation $invitation): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($invitation->account_id !== $request->user()->account_id, 403);
        $invitation->delete();

        return back()->with('success', 'Invitación revocada.');
    }

    /**
     * Regenera el token de una invitación pendiente y devuelve el nuevo link.
     * Útil cuando el admin necesita reenviar el link (el original solo se
     * mostraba una vez al crear la invitación).
     */
    public function regenerateInvitation(Request $request, AccountInvitation $invitation): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($invitation->account_id !== $request->user()->account_id, 403);
        abort_if($invitation->accepted_at !== null, 422, 'Esta invitación ya fue aceptada.');

        $token = Str::random(48);
        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return back()->with('invite_url', route('invitations.accept', $token));
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($member->account_id !== $request->user()->account_id, 403);

        if ($member->isOwner()) {
            return back()->withErrors(['member' => 'El rol del owner no se cambia desde aquí.']);
        }

        $member->update($request->validate([
            'account_role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
        ]));

        return back()->with('success', 'Rol actualizado.');
    }

    public function removeMember(Request $request, User $member, AccountProvisioner $provisioner): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($member->account_id !== $request->user()->account_id, 403);

        if ($member->isOwner()) {
            return back()->withErrors(['member' => 'No puedes expulsar al owner.']);
        }

        if ($member->id === $request->user()->id) {
            return back()->withErrors(['member' => 'No puedes expulsarte a ti mismo.']);
        }

        // El expulsado recupera una cuenta propia lista para usar.
        $provisioner->createForUser($member);

        return back()->with('success', 'Miembro expulsado.');
    }

    /** Transfiere la propiedad de la cuenta a otro miembro. */
    public function transferOwnership(Request $request, User $member): RedirectResponse
    {
        $owner = $request->user();

        abort_unless($owner->isOwner(), 403);
        abort_if($member->account_id !== $owner->account_id, 403);

        if ($member->id === $owner->id) {
            return back()->withErrors(['member' => 'Ya eres el owner.']);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($owner, $member) {
            $owner->account->update(['owner_user_id' => $member->id]);
            $member->update(['account_role' => User::ROLE_OWNER]);
            $owner->update(['account_role' => User::ROLE_ADMIN]);
        });

        return back()->with('success', "Ahora {$member->name} es el owner de la cuenta.");
    }

    // ---- Aceptación pública ----

    public function acceptForm(Request $request, string $token): Response
    {
        $invitation = $this->findInvitation($token);

        return Inertia::render('Invitations/Accept', $invitation ? [
            'token' => $token,
            'accountName' => $invitation->account->name,
            'role' => $invitation->role,
            'isLoggedIn' => (bool) $request->user(),
        ] : ['invalid' => true]);
    }

    public function redeem(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->withErrors(['email' => 'La invitación no es válida o expiró.']);
        }

        $user = $request->user();

        if (! $user) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|lowercase|email|max:255|unique:users,email',
                'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            auth()->login($user);
        }

        // Un owner con más miembros no puede abandonar su cuenta.
        if ($user->isOwner() && User::where('account_id', $user->account_id)->where('id', '!=', $user->id)->exists()) {
            return redirect()->route('dashboard')
                ->withErrors(['invite' => 'Transfiere tu cuenta actual antes de unirte a otra.']);
        }

        $user->update([
            'account_id' => $invitation->account_id,
            'account_role' => $invitation->role,
        ]);

        $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->id]);

        // Auto-provisión en el wacrm: crea el mismo user allá para que
        // pueda entrar al Inbox con el mismo email/password. Silencioso
        // si la integración no está configurada.
        $this->provisionInWacrm($user, $invitation->account_id, $invitation->role, $validated['password'] ?? null);

        return redirect()->route('dashboard')->with('success', "Te uniste a {$invitation->account->name}.");
    }

    /**
     * Crea (o actualiza) el mismo usuario en el wacrm por API. Usa el mismo
     * email y password para que la cuenta valga en los dos sistemas. El rol
     * se traduce: admin→admin, agent/viewer→agent (wacrm no distingue viewer
     * en el Inbox, todos los no-admin quedan restringidos igual).
     */
    private function provisionInWacrm(User $user, string $accountId, string $role, ?string $plaintextPassword): void
    {
        $integration = \App\Models\Integration::forAccount($accountId)->first();
        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return;
        }

        $wacrmRole = $role === 'admin' ? 'admin' : 'agent';

        try {
            \App\Services\Wacrm\Client::for($integration)->provisionUser(
                email: $user->email,
                name: $user->name,
                password: $plaintextPassword, // null si ya existía el user en Komo
                role: $wacrmRole,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auto-provisión en wacrm falló', [
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findInvitation(string $token): ?AccountInvitation
    {
        return AccountInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('account:id,name')
            ->first();
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRoleAtLeast(User::ROLE_ADMIN), 403);
    }
}
