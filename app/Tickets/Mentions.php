<?php

namespace App\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Erwähnungen in internen Notizen: „@Anna Beispiel“ oder „@Anna“ (wenn der Vorname eindeutig ist).
 * Erwähnt werden kann nur, wer das Ticket sehen darf: aktive Mitarbeiter des Mandanten und Administratoren.
 */
class Mentions
{
    /** @return Collection<int, User> */
    public static function candidates(Ticket $ticket): Collection
    {
        return User::query()->where('active', true)
            ->where(fn ($q) => $q->where('is_admin', true)
                ->orWhereIn('id', DB::table('tenant_user')->where('tenant_id', $ticket->tenant_id)->select('user_id')))
            ->orderBy('name')->get();
    }

    /**
     * @param  array<int, int|string>  $explicitIds  zusätzlich ausgewählte Mitarbeiter (Auswahlfeld in der Weboberfläche)
     * @return Collection<int, User>
     */
    public static function resolve(Ticket $ticket, string $body, array $explicitIds = []): Collection
    {
        $candidates = self::candidates($ticket);
        $explicit = array_map('intval', $explicitIds);
        $firstNames = $candidates->countBy(fn (User $u) => self::firstName($u));

        return $candidates->filter(function (User $user) use ($body, $explicit, $firstNames) {
            if (in_array($user->getKey(), $explicit, true)) {
                return true;
            }
            if (! str_contains($body, '@')) {
                return false;
            }

            $names = [trim($user->name)];
            $first = self::firstName($user);
            if ($first !== '' && $firstNames[$first] === 1) {
                $names[] = $first;
            }

            foreach (array_filter($names) as $name) {
                // Kein Buchstabe direkt davor (keine E-Mail-Adresse) und keiner direkt danach
                if (preg_match('/(?<![\p{L}\p{N}._-])@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $body)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private static function firstName(User $user): string
    {
        return mb_strtolower((string) strtok(trim($user->name), ' '));
    }
}
