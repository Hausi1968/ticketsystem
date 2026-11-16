<?php

namespace App\Providers;

use App\Mail\Inbound\ImapMailFetcher;
use App\Mail\Inbound\MailFetcher;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(MailFetcher::class, ImapMailFetcher::class);
    }

    public function boot(): void
    {
        // 60 Anfragen pro Minute und API-Schlüssel
        RateLimiter::for('anliego-api', fn (Request $request) => Limit::perMinute(60)
            ->by('api-key:'.($request->attributes->get('api_key')?->getKey() ?? $request->ip())));

        RateLimiter::for('anliego-agent', fn (Request $request) => Limit::perMinute(240)
            ->by('agent:'.($request->user()?->getKey() ?? $request->ip())));

        // Listener in app/Listeners werden automatisch registriert.
    }
}
