<?php

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlaPolicy extends EditRecord
{
    protected static string $resource = SlaPolicyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SlaPolicyResource::toForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SlaPolicyResource::toStorage($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
