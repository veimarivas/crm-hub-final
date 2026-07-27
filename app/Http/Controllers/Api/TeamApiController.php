<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Provisión de miembros desde el wacrm.
 *
 * Es el espejo del `TeamApiController@provision` del wacrm y cierra el
 * puente: hasta ahora la sincronización de usuarios era de ida solamente
 * (Komo creaba el user allá al aceptar una invitación), así que un miembro
 * dado de alta EN el wacrm no existía acá — y como acá es donde se asignan
 * los contactos, no aparecía en ningún desplegable de responsable.
 *
 * Idempotente por email: si ya existe en esta cuenta actualiza nombre y rol
 * sin tocar el password; si existe en otra cuenta responde 409 (no se roban
 * usuarios entre tenants).
 */
class TeamApiController extends Controller
{
    public function provision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:180',
            'password' => 'nullable|string|min:8|max:100',
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
        ]);

        $accountId = $request->attributes->get('account_id');
        $role = $validated['role'] ?? User::ROLE_AGENT;

        $existing = User::where('email', $validated['email'])->first();

        if ($existing && $existing->account_id !== $accountId) {
            return response()->json([
                'message' => 'El email ya pertenece a otra cuenta en Komo.',
                'code' => 'email_in_other_account',
            ], 409);
        }

        if ($existing) {
            // El password no se pisa: si el miembro ya entró y lo cambió, una
            // re-provisión no debe devolverlo al que mandó el wacrm.
            $existing->update(['name' => $validated['name'], 'account_role' => $role]);

            return response()->json([
                'user' => $existing->only(['id', 'name', 'email', 'account_role']),
                'created' => false,
            ]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Sin password explícito queda uno aleatorio: el miembro entra por
            // "olvidé mi contraseña". Nunca se deja una clave adivinable.
            'password' => Hash::make($validated['password'] ?? Str::random(32)),
            'account_id' => $accountId,
            'account_role' => $role,
        ]);

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'account_role']),
            'created' => true,
        ], 201);
    }
}
