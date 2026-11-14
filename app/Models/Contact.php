<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'email', 'name', 'external_user_id'];

    protected static function booted(): void
    {
        static::saving(fn (Contact $c) => $c->email = mb_strtolower(trim($c->email)));
    }

    /** Sucht oder legt einen Kontakt im angegebenen Mandanten an; ein fehlender Name wird ergänzt. */
    public static function findOrCreateFor(int $tenantId, string $email, ?string $name = null): self
    {
        $contact = static::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'email' => mb_strtolower(trim($email))],
            ['name' => $name ?: null],
        );

        if ($name && ! $contact->name) {
            $contact->update(['name' => $name]);
        }

        return $contact;
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function label(): string
    {
        return $this->name ? "{$this->name} <{$this->email}>" : $this->email;
    }
}
