<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use BelongsToTenant;

    public const PREFIX = 'ank_';

    protected $fillable = ['tenant_id', 'name', 'mailbox_id', 'team_id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * Legt einen Schlüssel an und gibt den Klartext einmalig zurück.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(int $tenantId, string $name, ?int $mailboxId = null, ?int $teamId = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = new self(['tenant_id' => $tenantId, 'name' => $name, 'mailbox_id' => $mailboxId, 'team_id' => $teamId]);
        $key->prefix = substr($plain, 0, 12);
        $key->key_hash = hash('sha256', $plain);
        $key->save();

        return [$key, $plain];
    }

    /** Sucht einen gültigen Schlüssel ohne Mandantenfilter (die Anfrage hat noch keinen Mandanten). */
    public static function findByPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return static::query()->withoutGlobalScopes()
            ->where('key_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
