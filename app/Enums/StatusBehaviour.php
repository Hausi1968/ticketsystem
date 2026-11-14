<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Was ein (frei benannter) Status mit dem Ticket macht. */
enum StatusBehaviour: string implements HasLabel
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Ticket bleibt offen (in Bearbeitung)',
            self::Pending => 'Ticket wartet (z. B. auf Kunde oder Lieferant)',
            self::Closed => 'Schließt das Ticket',
        };
    }
}
