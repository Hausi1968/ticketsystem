<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(\App\Mail\Inbound\MailFetcher::class, \App\Mail\Inbound\ImapMailFetcher::class);
    }

    public function boot(): void
    {
        // 60 Anfragen pro Minute und API-Schlüssel
        \Illuminate\Support\Facades\RateLimiter::for('anliego-api', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
            ->by('api-key:'.($request->attributes->get('api_key')?->getKey() ?? $request->ip())));

        // Listener in app/Listeners werden automatisch registriert.
    }
}
