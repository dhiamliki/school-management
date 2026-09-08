<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Laravel's status for an unusable session. Symfony has no constant. */
    private const HTTP_SESSION_UNAVAILABLE = 419;

    public function login(Request $request): JsonResponse
    {
        // Before the credentials are read, so the answer is identical whatever
        // the password is. Checking after meant a correct password raised a 500
        // while a wrong one answered 422, which is a password oracle.
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

    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        // No session middleware means nothing to clear; signing out still works.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
