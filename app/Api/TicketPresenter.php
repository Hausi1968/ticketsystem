<?php

namespace App\Api;

use App\Enums\MessageType;
use App\Models\Ticket;
use App\Models\TicketMessage;

/** Was die Anwendung über ein Ticket erfährt. Interne Notizen und technische Angaben bleiben intern. */
class TicketPresenter
{
    public static function summary(Ticket $ticket): array
    {
        return [
            'number' => $ticket->number,
            'reference' => $ticket->reference(),
            'subject' => $ticket->subject,
            'status' => [
                'name' => $ticket->status->name,
                'state' => $ticket->status->behaviour->value, // open | pending | closed
            ],
            'category' => $ticket->category?->name,
            'priority' => $ticket->priority->value,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    public static function full(Ticket $ticket): array
    {
        $messages = $ticket->messages()
            ->with('attachments', 'user')
            ->whereIn('type', [MessageType::Customer, MessageType::Reply])
            ->get()
            ->map(fn (TicketMessage $m) => [
                'type' => $m->type === MessageType::Reply ? 'reply' : 'customer',
                'author' => $m->type === MessageType::Reply ? ($m->user?->name ?? 'Support') : ($m->from_name ?? $m->from_email),
                'body' => $m->body_text,
                'created_at' => $m->created_at?->toIso8601String(),
                'attachments' => $m->attachments->where('inline', false)->map(fn ($a) => [
                    'filename' => $a->filename,
                    'size' => $a->size,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return self::summary($ticket) + ['messages' => $messages];
    }
}
