<?php

namespace App\Tickets;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;

/**
 * Volltextsuche für Tickets: Betreff, Text aller Nachrichten (auch Notizen),
 * Absender und die Werte der eigenen Felder (z. B. Bestellnummer).
 * Jedes Wort muss irgendwo vorkommen, die Reihenfolge ist egal.
 */
class TicketSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $fieldKeys = self::searchableFieldKeys();
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
                    ->orWhere('name', 'like', $like))
                ->when($fieldKeys !== [], fn (Builder $q) => self::whereFieldsLike($q, $fieldKeys, $word)));
        }

        return $query;
    }

    /**
     * Kennungen der aktiven eigenen Felder, deren Werte durchsucht werden (alle Mandanten;
     * Ja/Nein-Felder nicht, dort steht nur true/false).
     *
     * @return list<string>
     */
    private static function searchableFieldKeys(): array
    {
        return CustomField::query()->where('active', true)->where('type', '!=', 'checkbox')
            ->distinct()->orderBy('key')->pluck('key')
            ->filter(fn ($key) => is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key))
            ->values()->all();
    }

    /** Wert eines eigenen Felds enthält das Wort (ohne Rücksicht auf Groß-/Kleinschreibung) */
    private static function whereFieldsLike(Builder $query, array $keys, string $word): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $like = '%'.mb_strtolower($word).'%';

        foreach ($keys as $key) {
            $query->orWhereRaw('lower('.$grammar->wrap('fields->'.$key).') like ?', [$like]);
        }
    }
}
