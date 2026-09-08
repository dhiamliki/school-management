<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Laravel's own status for a request whose session could not be used.
     * Symfony has no constant for it, so it is named here.
     */
    private const HTTP_SESSION_UNAVAILABLE = 419;

    /**
     * Log the user in and start a stateful session for the SPA.
     */
    public function login(Request $request): JsonResponse
    {
        // Checked before the credentials are read, not after.
        //
        // Sanctum only puts the session middleware on requests coming from a
        // host in SANCTUM_STATEFUL_DOMAINS. From anywhere else there is no
        // session to regenerate, and regenerating one after a successful
        // Auth::attempt used to raise a 500 while a wrong password still
        // answered 422 - which told an unauthenticated caller, one guess at a
        // time, when it had found the right password. Refusing up front makes
        // the answer identical whatever the password is.
        if (! $request->hasSession()) {
            return response()->json([
                'message' => "Connexion impossible depuis cette adresse : l'application doit être ouverte depuis un domaine déclaré dans SANCTUM_STATEFUL_DOMAINS.",
            ], self::HTTP_SESSION_UNAVAILABLE);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json($request->user());
    }

    /**
     * Return the currently authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        // Guarded for the same reason as login(): a request that arrived
        // without the session middleware has no store to invalidate, and
        // reaching for one raised a 500 on what is meant to be the safe way
        // out. Signing out still succeeds, there is simply nothing to clear.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
