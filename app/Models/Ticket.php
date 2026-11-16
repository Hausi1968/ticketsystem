<?php

namespace App\Models;

use App\Enums\StatusBehaviour;
use App\Enums\TicketPriority;
use App\Sla\SlaClock;
use App\Support\TicketNumber;
use App\Tenancy\BelongsToTenant;
use App\Tickets\TicketNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $attributes = ['priority' => 'normal', 'channel' => 'email'];

    protected $fillable = [
        'tenant_id', 'subject', 'status_id', 'category_id', 'priority', 'channel', 'mailbox_id', 'team_id', 'contact_id',
        'assignee_id', 'follow_up_of_id', 'merged_into_id', 'context', 'api_key_id', 'last_customer_message_at', 'last_agent_message_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'number' => 'integer',
            'context' => 'array',
            'last_customer_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
            'waiting_since' => 'datetime',
            'reminded_at' => 'datetime',
            'sla' => 'array',
            'sla_due_at' => 'datetime',
            'sla_next_check_at' => 'datetime',
            'sla_breached' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Läuft nach dem creating-Hook von BelongsToTenant, der den Mandanten prüft
        static::creating(function (Ticket $ticket) {
            $ticket->number ??= TicketNumber::next();
            $ticket->status_id ??= TicketStatus::system(TicketStatus::NEW)->getKey();
            app(SlaClock::class)->creating($ticket);
        });

        // Fristen (SLA) bei jeder relevanten Änderung nachführen
        static::updating(fn (Ticket $ticket) => app(SlaClock::class)->updating($ticket));
    }

    // ---------------------------------------------------------------- Beziehungen

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'follow_up_of_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'merged_into_id');
    }

    /** Ticket, in dem dieses aufgegangen ist (über mehrere Zusammenführungen), sonst das Ticket selbst */
    public function mergeTarget(): Ticket
    {
        $ticket = $this;
        for ($i = 0; $i < 10 && $ticket->merged_into_id; $i++) {
            $next = Ticket::query()->find($ticket->merged_into_id);
            if (! $next) {
                break;
            }
            $ticket = $next;
        }

        return $ticket;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'ticket_participants')->withPivot('tenant_id')->withTimestamps();
    }

    // ---------------------------------------------------------------- Abfragen

    /** Tickets, die nicht durch ihren Status geschlossen sind */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status_id', TicketStatus::idsWith(StatusBehaviour::Open, StatusBehaviour::Pending));
    }

    public function scopeWithBehaviour(Builder $query, StatusBehaviour ...$behaviours): Builder
    {
        return $query->whereIn('status_id', TicketStatus::idsWith(...$behaviours));
    }

    public function isClosed(): bool
    {
        return $this->status?->closes() ?? false;
    }

    // ---------------------------------------------------------------- Anzeige

    /** z. B. "ABC-1001" */
    public function reference(): string
    {
        return ($this->tenant?->short_code ?? 'T'.$this->tenant_id).'-'.$this->number;
    }

    /** Kennung im Betreff ausgehender E-Mails, z. B. "[#ABC-1001]" */
    public function subjectToken(): string
    {
        return '[#'.$this->reference().']';
    }

    // ---------------------------------------------------------------- Aktionen

    public function addParticipant(Contact $contact): void
    {
        if ($contact->getKey() === $this->contact_id || $contact->tenant_id !== $this->tenant_id) {
            return;
        }

        $this->participants()->syncWithoutDetaching([$contact->getKey() => ['tenant_id' => $this->tenant_id]]);
    }

    /** Darf diese E-Mail-Adresse per Betreff-Kennung auf das Ticket antworten? */
    public function acceptsRepliesFrom(string $email): bool
    {
        $email = mb_strtolower($email);

        return $this->contact?->email === $email
            || $this->participants()->where('email', $email)->exists();
    }

    public function assignTo(?User $user, ?User $actor = null): void
    {
        if ($user && ! $user->is_admin && $user->roleIn($this->tenant_id) === null) {
            throw new AuthorizationException("{$user->name} betreut diesen Mandanten nicht.");
        }

        if ($this->assignee_id === $user?->getKey()) {
            return;
        }

        $previous = $this->assignee_id;
        $this->assignee_id = $user?->getKey();

        if ($user && $this->status?->system_key === TicketStatus::NEW) {
            $this->status()->associate(TicketStatus::system(TicketStatus::OPEN));
        }

        $this->save();
        $this->logEvent('assigned', $actor, ['from' => $previous, 'to' => $user?->getKey()]);

        if ($user) {
            app(TicketNotifier::class)->assigned($this, $user, $actor);
        }
    }

    /** @param  array<string, mixed>  $note  zusätzliche Angaben fürs Protokoll, z. B. ['auto' => 7] */
    public function changeStatus(TicketStatus|string $status, ?User $actor = null, array $note = []): void
    {
        $status = is_string($status) ? TicketStatus::system($status) : $status;

        if ($this->status_id === $status->getKey()) {
            return;
        }

        $previous = $this->status;
        $this->status()->associate($status);
        $this->closed_at = $status->closes() ? ($previous?->closes() ? $this->closed_at : now()) : null;
        // Für Erinnerung und automatisches Lösen: seit wann wartet das Ticket auf den Kunden?
        $this->waiting_since = $status->system_key === TicketStatus::WAITING ? now() : null;
        $this->reminded_at = null;

        $this->save();
        $this->logEvent('status', $actor, ['from' => $previous?->name, 'to' => $status->name] + $note);
    }

    public function changeCategory(?TicketCategory $category, ?User $actor = null): void
    {
        if ($category && ! $category->appliesTo((int) $this->tenant_id)) {
            throw new AuthorizationException('Diese Kategorie gehört zu einem anderen Mandanten.');
        }

        if ($this->category_id === $category?->getKey()) {
            return;
        }

        $previous = $this->category;
        $this->category()->associate($category);
        $this->save();
        $this->logEvent('category', $actor, ['from' => $previous?->name, 'to' => $category?->name]);
    }

    public function changePriority(TicketPriority $priority, ?User $actor = null): void
    {
        if ($this->priority === $priority) {
            return;
        }

        $previous = $this->priority;
        $this->priority = $priority;
        $this->save();
        $this->logEvent('priority', $actor, ['from' => $previous?->value, 'to' => $priority->value]);
    }

    public function logEvent(string $type, ?User $actor = null, array $data = []): TicketEvent
    {
        return $this->events()->create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $actor?->getKey(),
            'type' => $type,
            'data' => $data ?: null,
        ]);
    }
}
