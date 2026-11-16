<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Anmeldung eines Mitarbeiters in der App (ein Eintrag je Gerät). Gespeichert wird nur der Hash. */
class AgentToken extends Model
{
    public const PREFIX = 'agt_';

    protected $fillable = ['user_id', 'name', 'push_token', 'last_used_at', 'app_version', 'app_platform', 'app_update'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(48);
        $token = new self(['user_id' => $user->getKey(), 'name' => Str::limit($name, 100, '')]);
        $token->token_hash = hash('sha256', $plain);
        $token->last_used_at = now();
        $token->save();

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return static::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
    }
}
