<?php

namespace App\Notifications\Channels;

use App\Models\AgentToken;
use App\Models\User;
use App\Notifications\TicketActivity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push-Nachricht an die Mitarbeiter-App über den Expo-Push-Dienst (keine eigenen Firebase-Schlüssel nötig).
 * Geräte, die Expo als nicht mehr registriert meldet, verlieren ihre Push-Adresse.
 */
class ExpoPushChannel
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function send(User $notifiable, TicketActivity $notification): void
    {
        $tokens = AgentToken::query()->where('user_id', $notifiable->getKey())->whereNotNull('push_token')->pluck('push_token')->unique()->values();
        if ($tokens->isEmpty()) {
            return;
        }

        $t = $notification->ticket;
        $messages = $tokens->map(fn (string $to) => [
            'to' => $to,
            'title' => $notification->title(),
            'body' => trim(($t['tenant'] ?? '').' · '.($t['from'] ?? '').(! empty($t['excerpt']) ? "\n".$t['excerpt'] : '')),
            'data' => ['ticket_id' => $t['id'] ?? null, 'reference' => $t['reference'] ?? null, 'event' => $notification->event],
            'sound' => 'default',
            'channelId' => 'tickets',
            'priority' => 'high',
        ])->all();

        try {
            $response = Http::timeout(10)->acceptJson()->post(self::ENDPOINT, $messages);
            foreach ((array) $response->json('data', []) as $i => $result) {
                if (($result['status'] ?? null) === 'error' && ($result['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    AgentToken::query()->where('push_token', $messages[$i]['to'] ?? '')->update(['push_token' => null]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Push-Nachricht fehlgeschlagen', ['user' => $notifiable->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
