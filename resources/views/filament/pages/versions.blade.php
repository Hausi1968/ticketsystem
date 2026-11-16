@php
    $muted = 'color:rgb(100 116 139)';
    $th = 'text-align:left;padding:.5rem 1rem .5rem 0;font-weight:500;'.$muted;
    $td = 'padding:.5rem 1rem .5rem 0;border-top:1px solid rgba(148,163,184,.25);vertical-align:top';
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Weboberfläche" icon="heroicon-o-computer-desktop">
        <table style="font-size:.875rem;border-collapse:collapse">
            <tr><td style="{{ $muted }};padding:.25rem 2rem .25rem 0">Version</td><td style="font-weight:600;font-size:1rem">{{ $web['number'] }}</td></tr>
            <tr><td style="{{ $muted }};padding:.25rem 2rem .25rem 0">Stand (Deploy)</td>
                <td>{{ $web['date'] ? $web['date']->format('d.m.Y H:i').' Uhr' : 'unbekannt (nicht über GitHub hochgeladen)' }}@if ($web['commit']) · Commit <code>{{ $web['commit'] }}</code>@endif</td></tr>
            <tr><td style="{{ $muted }};padding:.25rem 2rem .25rem 0">Technik</td><td>PHP {{ $php }} · Laravel {{ $laravel }}</td></tr>
        </table>
    </x-filament::section>

    <x-filament::section heading="Mitarbeiter-App" icon="heroicon-o-device-phone-mobile"
        :description="($showUser ? 'Alle angemeldeten Geräte.' : 'Deine angemeldeten Geräte.').' Die Version meldet die App bei jeder Verbindung.'">
        @if ($devices->isEmpty())
            <p style="font-size:.875rem;{{ $muted }}">Noch kein Gerät mit der App angemeldet.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                    <thead>
                        <tr>
                            @if ($showUser)<th style="{{ $th }}">Mitarbeiter</th>@endif
                            <th style="{{ $th }}">Gerät</th>
                            <th style="{{ $th }}">App-Version</th>
                            <th style="{{ $th }}">Update-Stand</th>
                            <th style="{{ $th }}">Zuletzt aktiv</th>
                            <th style="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($devices as $device)
                            <tr>
                                @if ($showUser)<td style="{{ $td }}">{{ $device->user?->name }}</td>@endif
                                <td style="{{ $td }}">{{ $device->name }}@if ($device->app_platform) <span style="{{ $muted }}">({{ $device->app_platform }})</span>@endif</td>
                                <td style="{{ $td }}">
                                    {{ $device->app_version ?? '–' }}
                                    @if ($device->app_version && $latestApp && version_compare($device->app_version, $latestApp, '<'))
                                        <span style="margin-left:.35rem;border-radius:.375rem;padding:.05rem .4rem;font-size:.75rem;background:rgb(254 243 199);color:rgb(146 64 14)">veraltet</span>
                                    @endif
                                </td>
                                <td style="{{ $td }}">{{ $device->app_update ?? '–' }}</td>
                                <td style="{{ $td }}">{{ $device->last_used_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–' }}</td>
                                <td style="{{ $td }};text-align:right">
                                    <button type="button" wire:click="revokeDevice({{ $device->id }})"
                                            wire:confirm="Gerät „{{ $device->name }}“ abmelden? Die App muss sich danach neu anmelden."
                                            style="color:rgb(185 28 28);font-weight:600;border:1px solid rgb(252 165 165);border-radius:.5rem;padding:.15rem .6rem;background:transparent;cursor:pointer">Abmelden</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p style="font-size:.8rem;margin-top:.75rem;{{ $muted }}">
            Geräte, die {{ config('anliego.agent_token_days') }} Tage nicht benutzt wurden, werden automatisch abgemeldet.
            Wer sein Passwort ändert, meldet damit alle seine Geräte ab.
        </p>
    </x-filament::section>
</x-filament-panels::page>
