<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MessageType: string implements HasLabel
{
    case Customer = 'customer';
    case Reply = 'reply';
    case Note = 'note';
    case Auto = 'auto'; // automatische E-Mail, z. B. Eingangsbestätigung

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Nachricht',
            self::Reply => 'Antwort',
            self::Note => 'Interne Notiz',
            self::Auto => 'Eingangsbestätigung',
        };
    }
}
