<?php

namespace App\Filament\Resources\CannedResponses\Pages;

use App\Filament\Resources\CannedResponses\CannedResponseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCannedResponses extends ManageRecords
{
    protected static string $resource = CannedResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Textbaustein anlegen')];
    }
}
