<?php

namespace App\Models;

use App\Enums\StatusBehaviour;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Frei definierbarer Ticketstatus (für alle Mandanten gleich).
 * Status mit system_key setzt Anliego selbst: new (neues Ticket), open (übernommen/wieder geöffnet),
 * waiting (nach einer Antwort), solved (Vorschlag für "erledigt").
 */
class TicketStatus extends Model
{
    public const NEW = 'new';

    public const OPEN = 'open';

    public const WAITING = 'waiting';

    public const SOLVED = 'solved';

    protected $attributes = ['color' => 'gray', 'behaviour' => 'open', 'reply_creates_new_ticket' => false, 'sort' => 0, 'active' => true];

    protected $fillable = ['name', 'color', 'behaviour', 'reply_creates_new_ticket', 'system_key', 'sort', 'active'];

    protected function casts(): array
    {
        return [
            'behaviour' => StatusBehaviour::class,
            'reply_creates_new_ticket' => 'boolean',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TicketStatus $status) {
            // Die Status, die Anliego selbst setzt, dürfen ein Ticket nicht schließen bzw. müssen es schließen
            if (in_array($status->system_key, [self::NEW, self::OPEN], true)) {
                $status->behaviour = StatusBehaviour::Open;
            }
            if ($status->system_key === self::WAITING) {
                $status->behaviour = StatusBehaviour::Pending;
            }
            if ($status->system_key === self::SOLVED) {
                $status->behaviour = StatusBehaviour::Closed;
            }
            if ($status->system_key !== null) {
                $status->active = true;
            }
            if ($status->behaviour !== StatusBehaviour::Closed) {
                $status->reply_creates_new_ticket = false;
            }
        });

        static::deleting(function (TicketStatus $status) {
            if ($status->system_key !== null) {
                throw new \LogicException("Der Status „{$status->name}“ wird von Anliego benötigt und kann nur umbenannt werden.");
            }
            if ($status->tickets()->exists()) {
                throw new \LogicException("Der Status „{$status->name}“ ist noch Tickets zugeordnet. Bitte stattdessen deaktivieren.");
            }
        });

        static::saved(fn () => \Illuminate\Support\Once::flush());
    }

    public static function system(string $key): self
    {
        // once(): je Anfrage bzw. Job nur einmal laden (wird zwischen Tests automatisch geleert)
        return once(fn () => static::query()->where('system_key', $key)->firstOrFail());
    }

    /** @return list<int> */
    public static function idsWith(StatusBehaviour ...$behaviours): array
    {
        return static::query()->whereIn('behaviour', $behaviours)->pluck('id')->all();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'status_id');
    }

    public function closes(): bool
    {
        return $this->behaviour === StatusBehaviour::Closed;
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }
}
