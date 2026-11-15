<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis an einen Mitarbeiter: neues Ticket, Zuweisung oder Kundenantwort.
 * Enthält nur fertige Texte (keine Modelle), damit die Warteschlange ohne Mandantenkontext auskommt.
 */
class TicketActivity extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{reference: string, subject: string, tenant: string, from: string, excerpt: ?string, url: string, actor: ?string}  $ticket
     */
    public function __construct(public string $event, public array $ticket) {}

    public function via(User $notifiable): array
    {
        return $notifiable->wantsMailFor($this->event) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        $t = $this->ticket;

        return match ($this->event) {
            'new_ticket' => "Neues Ticket {$t['reference']}: {$t['subject']}",
            'assigned' => ($t['actor'] ? "{$t['actor']} hat dir" : 'Dir wurde')." {$t['reference']} zugewiesen: {$t['subject']}",
            'customer_reply' => "Neue Antwort von {$t['from']} zu {$t['reference']}",
            default => "{$t['reference']}: {$t['subject']}",
        };
    }

    public function toMail(User $notifiable): MailMessage
    {
        $t = $this->ticket;
        $mail = (new MailMessage)
            ->subject('['.$t['reference'].'] '.$this->title())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->title())
            ->line("Mandant: {$t['tenant']} · Von: {$t['from']}");

        if ($t['excerpt']) {
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

        return FilamentNotification::make()
            ->title($this->title())
            ->body(trim($t['tenant'].' · '.$t['from'].($t['excerpt'] ? "\n".$t['excerpt'] : '')))
            ->icon(match ($this->event) {
                'assigned' => 'heroicon-o-user-plus',
                'customer_reply' => 'heroicon-o-chat-bubble-left-ellipsis',
                default => 'heroicon-o-inbox-arrow-down',
            })
            ->actions([Action::make('open')->label('Öffnen')->url($t['url'])->markAsRead()])
            ->getDatabaseMessage();
    }
}
