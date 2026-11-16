<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eintrag im Sicherheitsprotokoll (nur für Administratoren sichtbar) */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public const TYPES = [
        'login' => 'Anmeldung (Web)',
        'login_failed' => 'Anmeldung fehlgeschlagen (Web)',
        'lockout' => 'Zu viele Anmeldeversuche',
        'logout' => 'Abmeldung (Web)',
        'app_login' => 'Anmeldung (App)',
        'app_login_failed' => 'Anmeldung fehlgeschlagen (App)',
        'app_logout' => 'Abmeldung (App)',
        'device_revoked' => 'App-Gerät abgemeldet',
        'device_expired' => 'App-Gerät abgelaufen',
        'password_changed' => 'Passwort geändert',
        'user_created' => 'Mitarbeiter angelegt',
        'user_changed' => 'Mitarbeiter geändert',
        'membership_changed' => 'Mandant/Rolle geändert',
    ];

    public const FAILURES = ['login_failed', 'lockout', 'app_login_failed'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
