<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft den API-Schlüssel (Authorization: Bearer ank_…). Der Schlüssel legt den Mandanten fest;
 * alle weiteren Abfragen der Anfrage sehen nur Daten dieses Mandanten.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findByPlain((string) $request->bearerToken());

        if (! $key) {
            return response()->json(['error' => 'unauthorized', 'message' => 'API-Schlüssel fehlt oder ist ungültig.'], 401);
        }

        $tenant = Tenant::query()->find($key->tenant_id);
        if (! $tenant?->isActive()) {
            return response()->json(['error' => 'tenant_inactive', 'message' => 'Der Mandant ist nicht aktiv.'], 403);
        }

        $this->tenancy->setTenantForRequest((int) $key->tenant_id);
        $request->attributes->set('api_key', $key);

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinutes(5))) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
