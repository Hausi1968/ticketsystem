<?php

namespace App\Agent;

use App\Api\ErrorReportService;
use App\Enums\MessageType;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\TicketPresence;
use App\Models\User;
use App\Support\HtmlCleaner;
use App\Support\QuoteStripper;
use App\Support\Version;
use App\Tickets\BulkEditor;
use App\Tickets\Presence;

/** JSON für die Mitarbeiter-App */
class AgentPresenter
{
    public static function user(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'tenants' => $user->memberships()->with('tenant')->get()->map(fn ($m) => [
                'id' => $m->tenant_id,
                'name' => $m->tenant?->name,
                'short_code' => $m->tenant?->short_code,
                'role' => $m->role?->value,
            ])->values()->all(),
            'server' => Version::toArray(),
        ];
    }

    public static function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getKey(),
            'reference' => $ticket->reference(),
            'subject' => $ticket->subject,
            'tenant' => [
                'id' => $ticket->tenant_id,
                'name' => $ticket->tenant?->name,
                'short_code' => $ticket->tenant?->short_code,
                'color' => $ticket->tenant?->color,
            ],
            'status' => $ticket->status ? [
                'id' => $ticket->status->getKey(),
                'name' => $ticket->status->name,
                'color' => $ticket->status->color,
                'behaviour' => $ticket->status->behaviour?->value,
            ] : null,
            'category' => $ticket->category ? ['id' => $ticket->category->getKey(), 'name' => $ticket->category->name] : null,
            'priority' => ['value' => $ticket->priority?->value, 'label' => $ticket->priority?->getLabel()],
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->getKey(), 'name' => $ticket->assignee->name] : null,
            'team' => $ticket->team ? ['id' => $ticket->team->getKey(), 'name' => $ticket->team->name] : null,
            'contact' => $ticket->contact ? ['name' => $ticket->contact->name, 'email' => $ticket->contact->email] : null,
            'channel' => $ticket->channel,
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'snoozed_until' => $ticket->isSnoozed() ? $ticket->snoozed_until->toIso8601String() : null,
            'error_count' => (int) $ticket->error_count,
            // Kundenantwort, die dieser Mitarbeiter noch nicht gesehen hat (in der App „NEU“)
            'unread_reply' => $ticket->hasUnreadCustomerReply(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    public static function full(Ticket $ticket, User $user): array
    {
        $ticket->loadMissing(['tenant', 'status', 'category', 'assignee', 'team', 'contact', 'messages.attachments', 'messages.user', 'events.user', 'timeEntries']);
        $canWrite = BulkEditor::canWrite($user, $ticket);
        $role = $user->roleIn($ticket->tenant_id);

        // wer das Ticket gerade öffnet, hat die Antwort gesehen
        return ['unread_reply' => false] + self::summary($ticket) + [
            'fields' => $ticket->fields ?: (object) [],
            'participants' => $ticket->participants()->pluck('email')->all(),
            'time_minutes' => (int) $ticket->timeEntries->sum('minutes'),
            'merged_into' => $ticket->merged_into_id ? $ticket->mergedInto?->reference() : null,
            'can' => [
                'reply' => $canWrite && ! $ticket->merged_into_id && $ticket->channel !== ErrorReportService::CHANNEL,
                'note' => $user->is_admin || $role !== null,
                'edit' => $canWrite,
                'take' => $canWrite && $ticket->assignee_id !== $user->getKey() && ! $ticket->isClosed(),
            ],
            'others' => app(Presence::class)->others($ticket, $user)->map(fn (TicketPresence $p) => [
                'name' => $p->user->name,
                'writing' => $p->activity === TicketPresence::WRITING,
            ])->values()->all(),
            'last_message_id' => $ticket->messages->max('id'),
            'messages' => $ticket->messages->sortBy('id')->map(fn (TicketMessage $m) => self::message($m))->values()->all(),
            'events' => $ticket->events->where('type', '!=', 'created')->sortBy('id')->map(fn (TicketEvent $e) => [
                'type' => $e->type,
                'actor' => $e->user?->name,
                'data' => $e->data ?: (object) [],
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public static function message(TicketMessage $m): array
    {
        $body = $m->body_text !== null && trim($m->body_text) !== '' ? $m->body_text : HtmlCleaner::toText($m->body_html);
        $short = $m->type === MessageType::Customer ? QuoteStripper::strip($body) : $body;

        return [
            'id' => $m->getKey(),
            'type' => $m->type?->value,
            'author' => $m->user?->name ?? ($m->from_name ?: $m->from_email),
            'from_email' => $m->from_email,
            'to' => collect($m->to ?? [])->pluck('email')->filter()->values()->all(),
            'cc' => collect($m->cc ?? [])->pluck('email')->filter()->values()->all(),
            'body' => $short,
            'body_full' => $short !== $body ? $body : null,
            'delivery_status' => $m->delivery_status,
            'created_at' => $m->created_at?->toIso8601String(),
            'attachments' => $m->attachments->where('inline', false)->map(fn (Attachment $a) => [
                'id' => $a->getKey(),
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => (int) $a->size,
                'url' => url('/api/agent/v1/attachments/'.$a->getKey()),
            ])->values()->all(),
        ];
    }
}
