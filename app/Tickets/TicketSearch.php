<?php

namespace App\Tickets;

use Illuminate\Database\Eloquent\Builder;

/**
 * Volltextsuche für Tickets: Betreff, Text aller Nachrichten (auch Notizen)
 * und Absender. Jedes Wort muss irgendwo vorkommen, die Reihenfolge ist egal.
 */
class TicketSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $words = array_slice(array_filter(preg_split('/\s+/u', trim($search)) ?: [], fn ($w) => $w !== ''), 0, 8);

        foreach ($words as $word) {
            $like = '%'.$word.'%';

            $query->where(fn (Builder $q) => $q
                ->where('subject', 'like', $like)
                ->orWhereHas('messages', fn (Builder $m) => $m
                    ->where('body_text', 'like', $like)
                    ->orWhere(fn (Builder $h) => $h->whereNull('body_text')->where('body_html', 'like', $like)))
                ->orWhereHas('contact', fn (Builder $c) => $c
                    ->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)));
        }

        return $query;
    }
}
