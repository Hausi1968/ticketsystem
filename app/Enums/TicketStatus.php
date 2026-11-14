<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TicketStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Open = 'open';
    case PendingCustomer = 'pending_customer';
    case PendingThird = 'pending_third';
    case Solved = 'solved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Neu',
            self::Open => 'Offen',
            self::PendingCustomer => 'Wartet auf Kunde',
            self::PendingThird => 'Wartet auf Dritte',
            self::Solved => 'Gelöst',
            self::Closed => 'Geschlossen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Open => 'warning',
            self::PendingCustomer, self::PendingThird => 'gray',
            self::Solved => 'success',
            self::Closed => 'gray',
        };
    }

    /** Status, in denen das Ticket noch bearbeitet werden muss oder wartet. */
    public static function unresolved(): array
    {
        return [self::New, self::Open, self::PendingCustomer, self::PendingThird];
    }

    public static function pending(): array
    {
        return [self::PendingCustomer, self::PendingThird];
    }
}
