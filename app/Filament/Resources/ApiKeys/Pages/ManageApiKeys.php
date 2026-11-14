<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    /** Klartext des gerade erzeugten Schlüssels – nur in dieser Sitzung der Seite sichtbar */
    public ?string $newKey = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')->label('Schlüssel erzeugen')->icon(Heroicon::OutlinedKey)
                ->modalHeading('API-Schlüssel erzeugen')
                ->schema([
                    Select::make('tenant_id')->label('Mandant')->options(ApiKeyResource::tenantOptions())->required()->live(),
                    TextInput::make('name')->label('Bezeichnung')->required()->maxLength(100)
                        ->placeholder('z. B. Mitgliederverwaltung Produktion'),
                    Select::make('mailbox_id')->label('Antworten gehen über Postfach')
                        ->options(fn (Get $get) => ApiKeyResource::mailboxOptions($get('tenant_id')))
                        ->placeholder('erstes aktives Postfach des Mandanten'),
                    Select::make('team_id')->label('Neue Tickets ins Team')
                        ->options(fn (Get $get) => ApiKeyResource::teamOptions($get('tenant_id'))),
                ])
                ->action(function (array $data) {
                    [, $plain] = ApiKey::issue((int) $data['tenant_id'], $data['name'], $data['mailbox_id'] ?? null, $data['team_id'] ?? null);
                    $this->newKey = $plain;
                    $this->replaceMountedAction('showKey');
                }),
        ];
    }

    public function showKeyAction(): Action
    {
        return Action::make('showKey')
            ->modalHeading('Neuer API-Schlüssel')
            ->modalDescription('Jetzt kopieren und in der Anwendung hinterlegen. Der Schlüssel wird nur dieses eine Mal angezeigt.')
            ->schema([
                TextInput::make('key')->label('Schlüssel')->default(fn () => $this->newKey)->readOnly()->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fertig')
            ->after(fn () => $this->newKey = null);
    }
}
