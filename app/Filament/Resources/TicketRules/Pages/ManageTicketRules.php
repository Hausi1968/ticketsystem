<?php

namespace App\Filament\Resources\TicketRules\Pages;

use App\Filament\Resources\TicketRules\TicketRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketRules extends ManageRecords
{
    protected static string $resource = TicketRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Regel anlegen')->modalWidth('4xl')];
    }
}
