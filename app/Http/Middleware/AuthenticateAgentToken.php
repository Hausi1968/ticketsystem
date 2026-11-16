<?php

namespace App\Http\Middleware;

use App\Models\AgentToken;
use App\Security\SecurityLog;
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

        // Lange nicht benutzte Anmeldungen verfallen (z. B. ein verlorenes oder ausgemustertes Handy)
        $days = (int) config('anliego.agent_token_days');
        if ($days > 0 && $token->last_used_at && $token->last_used_at->lt(now()->subDays($days))) {
            SecurityLog::record('device_expired', $token->user, ['device' => $token->name], actor: $token->user);
            $token->delete();

            return response()->json(['error' => 'unauthorized', 'message' => 'Die Anmeldung ist abgelaufen. Bitte neu anmelden.'], 401);
        }

        Auth::setUser($token->user);
        $request->attributes->set('agent_token', $token);

        // Version der App merken (Kopfzeilen X-App-Version, X-App-Platform, X-App-Update)
        $app = [
            'app_version' => self::header($request, 'X-App-Version', 30),
            'app_platform' => self::header($request, 'X-App-Platform', 20),
            'app_update' => self::header($request, 'X-App-Update', 60),
        ];
        $app = array_filter($app, fn ($v) => $v !== null);
        $changed = collect($app)->contains(fn ($v, $k) => $token->{$k} !== $v);

        if ($changed || ! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()] + $app)->saveQuietly();
        }

        return $next($request);
    }

    private static function header(Request $request, string $name, int $max): ?string
    {
        $value = trim((string) $request->header($name));

        return $value === '' ? null : mb_substr(preg_replace('/[^\w.\-: ]/u', '', $value), 0, $max);
    }
}
