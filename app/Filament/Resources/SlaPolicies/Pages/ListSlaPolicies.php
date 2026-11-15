<?php

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlaPolicies extends ListRecords
{
    protected static string $resource = SlaPolicyResource::class;

    public function getSubheading(): ?string
    {
        return 'Fristen für Erstreaktion, Folgereaktion und Lösung je Priorität. Je Ticket gilt die erste passende aktive Richtlinie (Reihenfolge per Ziehen ändern).';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
