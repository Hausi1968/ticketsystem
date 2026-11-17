<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Mail\Outbound\TeamSignature;
use App\Models\Team;
use App\Models\Tenant;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('tenant_id')->label('Mandant')
                ->options(fn () => Tenant::query()->accessible()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->disabledOn('edit'),
            TextInput::make('name')->label('Name')->required()->maxLength(255)
                ->placeholder('z. B. Auftragsbearbeitung'),
            Select::make('assignment_mode')->label('Zuweisung neuer Tickets')->required()->default(Team::MANUAL)
                ->options(Team::ASSIGNMENT_MODES)
                ->helperText('Automatisch: Neue Tickets dieses Teams ohne Bearbeiter gehen an die Mitglieder mit „Bekommt Zuweisungen“. Regeln mit eigener Zuweisung haben Vorrang.'),
            Toggle::make('notify_on_assignment')->label('Team benachrichtigen, wenn ihm ein Ticket zugeordnet wird')
                ->helperText('Alle Mitglieder bekommen einen Hinweis (Glocke, App und je nach Profil E-Mail), wenn jemand ein Ticket von Hand diesem Team zuordnet und es dabei niemandem zugewiesen wird.')
                ->columnSpanFull(),
            Section::make('E-Mail-Signatur')
                ->description('Beim Antworten kann jeder Mitarbeiter mit „Signatur hinzufügen“ diese Signatur unter seine Nachricht setzen – ganz im Stil des Auftraggebers, mit Logo.')
                ->collapsible()->columnSpanFull()->columns(2)
                ->components([
                    Textarea::make('signature_html')->label('Signatur (HTML)')->rows(12)->columnSpanFull()
                        ->live(onBlur: true)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 13px;', 'spellcheck' => 'false'])
                        ->placeholder("<p>Viele Grüße<br><strong>{{name}}</strong></p>\n<p style=\"color:#1e3a8a\">Muster GmbH · Auftragsbearbeitung<br>Tel. 0123 456789 · <a href=\"https://www.example.de\">www.example.de</a></p>\n{{logo}}")
                        ->helperText(new HtmlString('HTML-Code einfügen, z. B. aus Outlook oder von der Agentur. Skripte, Formulare und eingebettete Fremdinhalte werden beim Speichern entfernt; Bilder nur per https oder als Logo unten. Platzhalter: '
                            .collect(TeamSignature::PLACEHOLDERS)->map(fn ($label, $key) => '<code>'.e($key).'</code> '.e($label))->join(', ').'. '
                            .'Ohne <code>{{logo}}</code> steht das Logo unter dem Text.')),
                    FileUpload::make('signature_logo_path')->label('Logo')
                        ->disk('local')->visibility('private')
                        ->directory(fn (Get $get) => 'tenants/'.((int) $get('tenant_id')).'/signaturen')
                        ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/gif'])->maxSize(500)
                        ->live()
                        ->helperText('PNG, JPG oder GIF bis 500 KB. Wird in die E-Mail eingebettet und erscheint so auch ohne „Bilder nachladen“. SVG zeigen viele Mailprogramme nicht an.'),
                    TextInput::make('signature_logo_width')->label('Logo-Breite (Pixel)')->numeric()->integer()
                        ->minValue(16)->maxValue(TeamSignature::MAX_LOGO_WIDTH)->default(180)->required()->live(onBlur: true)
                        ->helperText('Die Höhe passt sich an. Für die meisten Signaturen sind 120–220 px gut.'),
                    View::make('teams.signature-preview')->columnSpanFull()
                        ->viewData(fn (Get $get, ?Team $record) => self::preview($get, $record)),
                ]),
        ]);
    }

    /** Live-Vorschau der Signatur mit Beispielnamen des angemeldeten Mitarbeiters */
    private static function preview(Get $get, ?Team $record): array
    {
        $logo = $get('signature_logo_path');
        $logo = is_array($logo) ? (array_values($logo)[0] ?? null) : $logo;
        $logoSrc = match (true) {
            $logo instanceof TemporaryUploadedFile => TeamSignature::logoDataUrl(null, $logo->get(), $logo->getMimeType()),
            is_string($logo) && $logo !== '' => TeamSignature::logoDataUrl($logo),
            default => null,
        };

        $team = $record ?? new Team;
        $team->name = (string) ($get('name') ?: 'Team');
        if (! $record) {
            $team->tenant_id = $get('tenant_id') ?: null;
        }

        $html = (string) $get('signature_html');
        if (trim($html) === '' && $logoSrc === null) {
            return ['srcdoc' => null];
        }

        return ['srcdoc' => TeamSignature::previewDocument(TeamSignature::render(
            $team, auth()->user(), $logoSrc, $html, $logoSrc !== null, (int) $get('signature_logo_width') ?: 180,
        ))];
    }
}
