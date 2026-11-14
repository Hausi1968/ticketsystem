<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MessageType: string implements HasLabel
{
    case Customer = 'customer';
    case Reply = 'reply';
    case Note = 'note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Nachricht',
            self::Reply => 'Antwort',
            self::Note => 'Interne Notiz',
        };
    }
}
