{{-- Gut sichtbarer Abmelde-Knopf oben rechts neben dem Profilbild (zusätzlich zum Eintrag im Benutzermenü) --}}
<form action="{{ filament()->getLogoutUrl() }}" method="post" class="anliego-logout" style="display:inline-flex;margin-inline-end:.25rem">
    @csrf
    <x-filament::button
        color="gray"
        size="sm"
        :icon="\Filament\Support\Icons\Heroicon::ArrowLeftEndOnRectangle"
        labeled-from="sm"
        tag="button"
        type="submit"
        tooltip="Abmelden"
    >
        Abmelden
    </x-filament::button>
</form>
