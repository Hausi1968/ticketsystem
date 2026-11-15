<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Profil mit Auswahl, welche Hinweise zusätzlich per E-Mail kommen. */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),

            Section::make('Benachrichtigungen per E-Mail')
                ->description('In Anliego (Glocke oben rechts) siehst du alle Hinweise. Hier wählst du, welche zusätzlich per E-Mail kommen.')
                ->schema(collect(User::NOTIFICATION_EVENTS)
                    ->map(fn (string $label, string $event) => Toggle::make("notification_preferences.{$event}")->label($label)->default(true))
                    ->values()->all()),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);
        $prefs = $this->getUser()->notification_preferences ?? [];

        foreach (array_keys(User::NOTIFICATION_EVENTS) as $event) {
            $data['notification_preferences'][$event] = (bool) ($prefs[$event] ?? true);
        }

        return $data;
    }
}
