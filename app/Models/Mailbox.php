<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailbox extends Model
{
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_folder' => 'INBOX',
        'smtp_port' => 465, 'smtp_encryption' => 'ssl',
        'poll_interval_minutes' => 5, 'active' => true, 'send_acknowledgement' => false, 'usage' => 'both',
    ];

    public const USAGES = [
        'both' => 'Abrufen und versenden',
        'fetch' => 'Nur abrufen (z. B. Weiterleitungs-Adresse, Antworten gehen über ein anderes Postfach)',
        'send' => 'Nur versenden (Absender der Antworten)',
    ];

    protected $fillable = [
        'tenant_id', 'team_id', 'default_category_id', 'name', 'email', 'usage', 'from_name', 'sent_folder', 'sent_copy_bcc',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_folder', 'processed_folder',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'poll_interval_minutes', 'active', 'send_acknowledgement', 'acknowledgement_text', 'blocked_senders', 'ignore_newsletters',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'active' => 'boolean',
            'send_acknowledgement' => 'boolean',
            'ignore_newsletters' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** Gesperrte Absender: Einträge wie „name@firma.de“, „@firma.de“, „*@firma.de“ oder „firma.de“ */
    public function blockedSenderList(): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $this->blocked_senders) ?: [])
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->map(fn ($e) => str_starts_with($e, '*@') ? substr($e, 1) : $e)
            ->filter()->unique()->values()->all();
    }

    public function blocksSender(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }
        $domain = substr($email, strrpos($email, '@') + 1);

        foreach ($this->blockedSenderList() as $entry) {
            if (str_contains($entry, '@') && ! str_starts_with($entry, '@')) {
                if ($entry === $email) {
                    return true;
                }

                continue;
            }
            $blocked = ltrim($entry, '@');
            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /** Absender zur Sperrliste hinzufügen (ohne Doppelte) */
    public function blockSender(string $entry): void
    {
        $list = $this->blockedSenderList();
        $entry = mb_strtolower(trim($entry));
        if (! in_array($entry, $list, true)) {
            $list[] = $entry;
        }
        $this->forceFill(['blocked_senders' => implode("\n", $list)])->save();
    }

    protected static function booted(): void
    {
        static::saving(fn (Mailbox $m) => $m->email = mb_strtolower(trim($m->email)));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'default_category_id');
    }

    public function canFetch(): bool
    {
        return $this->usage !== 'send';
    }

    public function canSend(): bool
    {
        return $this->usage !== 'fetch';
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Ist das Abrufintervall abgelaufen? (30 s Toleranz, weil der Cronjob nicht sekundengenau läuft) */
    public function isDue(): bool
    {
        return $this->active && $this->canFetch() && ($this->last_polled_at === null
            || $this->last_polled_at->lte(now()->subMinutes($this->poll_interval_minutes)->addSeconds(30)));
    }

    public function fromAddress(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->from_name ?: $this->tenant?->name ?: $this->email;
    }
}
