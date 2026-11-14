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
        // Listener in app/Listeners werden automatisch registriert.
    }
}
