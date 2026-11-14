<?php

namespace App\Filament\Resources\TicketStatuses\Pages;

use App\Filament\Resources\TicketStatuses\TicketStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketStatuses extends ManageRecords
{
    protected static string $resource = TicketStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Status anlegen')];
    }
}
