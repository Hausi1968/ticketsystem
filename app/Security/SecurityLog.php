<?php

namespace App\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Schreibt das Sicherheitsprotokoll. Ein Fehler hier darf nie eine Anmeldung oder Änderung verhindern. */
class SecurityLog
{
    public static function record(string $type, ?User $user = null, array $data = [], ?string $email = null, ?User $actor = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $actor ??= auth()->user();

            SecurityEvent::query()->create([
                'type' => $type,
                'user_id' => $user?->getKey(),
                'actor_id' => $actor instanceof User && $actor->exists ? $actor->getKey() : null,
                'email' => $email !== null ? Str::limit($email, 250, '') : $user?->email,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'data' => $data ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Sicherheitsprotokoll konnte nicht geschrieben werden', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
