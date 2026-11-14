<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $attributes = ['status' => 'new', 'priority' => 'normal', 'channel' => 'email'];

    protected $fillable = [
        'tenant_id', 'subject', 'status', 'priority', 'channel', 'mailbox_id', 'team_id', 'contact_id',
        'assignee_id', 'follow_up_of_id', 'last_customer_message_at', 'last_agent_message_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'number' => 'integer',
            'last_customer_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'solved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Läuft nach dem creating-Hook von BelongsToTenant, der den Mandanten prüft
        static::creating(function (Ticket $ticket) {
            $ticket->number ??= TenantCounter::nextTicketNumber((int) $ticket->tenant_id);
        });
    }

    // ---------------------------------------------------------------- Beziehungen

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

    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'follow_up_of_id');
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

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::unresolved());
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

        if ($user && $this->status === TicketStatus::New) {
            $this->status = TicketStatus::Open;
        }

        $this->save();
        $this->logEvent('assigned', $actor, ['from' => $previous, 'to' => $user?->getKey()]);
    }

    public function changeStatus(TicketStatus $status, ?User $actor = null): void
    {
        if ($this->status === $status) {
            return;
        }

        $previous = $this->status;
        $this->status = $status;

        $this->solved_at = $status === TicketStatus::Solved ? now() : ($status === TicketStatus::Closed ? $this->solved_at : null);
        $this->closed_at = $status === TicketStatus::Closed ? now() : null;

        $this->save();
        $this->logEvent('status', $actor, ['from' => $previous?->value, 'to' => $status->value]);
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
