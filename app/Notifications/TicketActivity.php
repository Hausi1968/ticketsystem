<?php

namespace App\Notifications;

use App\Models\AgentToken;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis an einen Mitarbeiter: neues Ticket, Zuweisung, Kundenantwort oder SLA-Frist.
 * Enthält nur fertige Texte (keine Modelle), damit die Warteschlange ohne Mandantenkontext auskommt.
 */
class TicketActivity extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{reference: string, subject: string, tenant: string, from: string, excerpt: ?string, url: string, actor: ?string, metric?: string, due?: string}  $ticket
     */
    public function __construct(public string $event, public array $ticket) {}

    public function via(User $notifiable): array
    {
        $channels = $notifiable->wantsMailFor($this->event) ? ['database', 'mail'] : ['database'];

        // Push in die Mitarbeiter-App, sobald ein Gerät angemeldet ist und Push erlaubt hat
        if (AgentToken::query()->where('user_id', $notifiable->getKey())->whereNotNull('push_token')->exists()) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function title(): string
    {
        $t = $this->ticket;

        return match ($this->event) {
            'new_ticket' => "Neues Ticket {$t['reference']}: {$t['subject']}",
            'assigned' => ($t['actor'] ? "{$t['actor']} hat dir" : 'Dir wurde')." {$t['reference']} zugewiesen: {$t['subject']}",
            'customer_reply' => "Neue Antwort von {$t['from']} zu {$t['reference']}",
            'sla_warning' => "Frist läuft bald ab: {$t['metric']} zu {$t['reference']} bis {$t['due']}",
            'sla_breach' => "Frist überschritten: {$t['metric']} zu {$t['reference']} (fällig {$t['due']})",
            default => "{$t['reference']}: {$t['subject']}",
        };
    }

    public function toMail(User $notifiable): MailMessage
    {
        $t = $this->ticket;
        $mail = (new MailMessage)
            ->subject('['.$t['reference'].'] '.$this->title())
            ->level($this->event === 'sla_breach' ? 'error' : 'info')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->title())
            ->line("Mandant: {$t['tenant']} · Von: {$t['from']}");

        if (str_starts_with($this->event, 'sla_')) {
            $mail->line("Betreff: {$t['subject']}");
        } elseif ($t['excerpt']) {
            $mail->line('„'.$t['excerpt'].'“');
        }

        return $mail
            ->action('Ticket öffnen', $t['url'])
            ->salutation('Anliego')
            ->line('Bitte antworte nicht auf diese E-Mail, sondern im Ticket. Welche Hinweise du per E-Mail bekommst, stellst du in deinem Profil ein.');
    }

    public function toDatabase(User $notifiable): array
    {
        $t = $this->ticket;

        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body(trim($t['tenant'].' · '.$t['from'].($t['excerpt'] ? "\n".$t['excerpt'] : '')))
            ->icon(match ($this->event) {
                'assigned' => 'heroicon-o-user-plus',
                'customer_reply' => 'heroicon-o-chat-bubble-left-ellipsis',
                'sla_warning' => 'heroicon-o-clock',
                'sla_breach' => 'heroicon-o-exclamation-triangle',
                default => 'heroicon-o-inbox-arrow-down',
            })
            ->actions([Action::make('open')->label('Öffnen')->url($t['url'])->markAsRead()]);

        match ($this->event) {
            'sla_warning' => $notification->iconColor('warning'),
            'sla_breach' => $notification->iconColor('danger'),
            default => null,
        };

        return $notification->getDatabaseMessage();
    }
}
