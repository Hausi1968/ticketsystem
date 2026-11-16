<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Security\SecurityLog;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_admin', 'active', 'notification_preferences'];

    protected $attributes = ['is_admin' => false, 'active' => true];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'active' => 'boolean',
            'notification_preferences' => 'array',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (User $user) => SecurityLog::record('user_created', $user, ['admin' => (bool) $user->is_admin]));

        static::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                // Neues Passwort: alle App-Anmeldungen beenden, die Geräte müssen sich neu anmelden
                $devices = $user->agentTokens()->count();
                $user->agentTokens()->delete();
                SecurityLog::record('password_changed', $user, ['abgemeldete_geraete' => $devices]);
            }

            $changes = collect(['email', 'is_admin', 'active', 'name'])->filter(fn ($field) => $user->wasChanged($field))
                ->mapWithKeys(fn ($field) => [$field => $user->{$field}])->all();
            if ($changes) {
                SecurityLog::record('user_changed', $user, $changes);
            }
        });
    }

    public function agentTokens(): HasMany
    {
        return $this->hasMany(AgentToken::class);
    }

    /** Ereignisse, zu denen Mitarbeiter benachrichtigt werden. In der Oberfläche immer, per E-Mail nach Wahl. */
    public const NOTIFICATION_EVENTS = [
        'new_ticket' => 'Neues Ticket in meinen Mandanten bzw. Teams',
        'assigned' => 'Mir wurde ein Ticket zugewiesen',
        'customer_reply' => 'Kunde hat auf mein Ticket geantwortet',
        'sla_warning' => 'Frist (SLA) eines Tickets läuft bald ab',
        'sla_breach' => 'Frist (SLA) eines Tickets ist überschritten',
        'team_assigned' => 'Ein Ticket wurde einem meiner Teams zugeordnet',
        'mention' => 'Ein Kollege hat mich in einer Notiz erwähnt',
        'snooze_due' => 'Wiedervorlage: ein zurückgestelltes Ticket ist wieder fällig',
    ];

    public function wantsMailFor(string $event): bool
    {
        return (bool) ($this->notification_preferences[$event] ?? true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->using(TenantMembership::class)->withPivot('role')->withTimestamps();
    }

    /** Teams, in denen der Mitarbeiter Mitglied ist (je Mandant) */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot(['tenant_id', 'receives_assignments'])->withTimestamps();
    }

    public function roleIn(Tenant|int $tenant): ?MembershipRole
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->memberships->firstWhere('tenant_id', $tenantId)?->role;
    }

    /** Admin oder Supervisor in mindestens einem Mandanten. */
    public function canManageTeams(): bool
    {
        return $this->is_admin || $this->memberships->contains(fn ($m) => $m->role === MembershipRole::Supervisor);
    }

    // Zwei-Faktor-Anmeldung (Authenticator-App), verschlüsselt gespeichert

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
