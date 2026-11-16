<?php

namespace App\Listeners;

use App\Models\User;
use App\Security\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Anmeldungen an der Weboberfläche ins Sicherheitsprotokoll schreiben */
class RecordSecurityEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record('login', $event->user, actor: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        SecurityLog::record('login_failed', $event->user instanceof User ? $event->user : null,
            email: (string) ($event->credentials['email'] ?? ''));
    }

    public function handleLockout(Lockout $event): void
    {
        SecurityLog::record('lockout', email: (string) $event->request->input('email', $event->request->input('data.email', '')));
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record('logout', $event->user, actor: $event->user);
        }
    }
}
