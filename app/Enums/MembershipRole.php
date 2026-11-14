<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MembershipRole: string implements HasLabel
{
    case Supervisor = 'supervisor';
    case Agent = 'agent';
    case LightAgent = 'light_agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agent',
            self::LightAgent => 'Light-Agent (nur lesen und Notizen)',
        };
    }
}
