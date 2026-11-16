<?php

namespace App\Http\Middleware;

use App\Models\AgentToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mitarbeiter-App: Authorization: Bearer agt_…. Meldet den Mitarbeiter für diese Anfrage an;
 * damit greift die Mandantentrennung genau wie in der Weboberfläche.
 */
class AuthenticateAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = AgentToken::findByPlain((string) $request->bearerToken());

        if (! $token || ! $token->user?->active) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Bitte neu anmelden.'], 401);
        }

        Auth::setUser($token->user);
        $request->attributes->set('agent_token', $token);

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
