<?php

namespace App\Filament\Pages;

use App\Models\AgentToken;
use App\Security\SecurityLog;
use App\Support\Version;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Welche Version der Weboberfläche läuft und welche App-Versionen die Geräte benutzen */
class Versions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static ?string $title = 'Versionen';

    protected static ?string $slug = 'versionen';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.versions';

    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'web' => Version::build() + ['label' => Version::label(), 'number' => Version::number()],
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'latestApp' => self::latestAppVersion(),
            'devices' => $this->devices($user->is_admin),
            'showUser' => (bool) $user->is_admin,
        ];
    }

    /** Gerät abmelden: Administratoren jedes, Mitarbeiter nur die eigenen */
    public function revokeDevice(int $id): void
    {
        $user = auth()->user();
        $token = AgentToken::query()->with('user')
            ->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->getKey()))
            ->find($id);

        if (! $token) {
            return;
        }

        SecurityLog::record('device_revoked', $token->user, ['device' => $token->name]);
        $token->delete();
        Notification::make()->success()->title('Gerät abgemeldet')
            ->body('Die App auf diesem Gerät muss sich neu anmelden.')->send();
    }

    /** Höchste App-Version, die gerade benutzt wird – zum Vergleich mit dem eigenen Gerät */
    public static function latestAppVersion(): ?string
    {
        return AgentToken::query()->whereNotNull('app_version')->pluck('app_version')
            ->sort(fn ($a, $b) => version_compare($b, $a))->first();
    }

    private function devices(bool $all): Collection
    {
        return AgentToken::query()->with('user')
            ->when(! $all, fn ($query) => $query->where('user_id', auth()->id()))
            ->orderByDesc('last_used_at')->limit(100)->get();
    }
}
