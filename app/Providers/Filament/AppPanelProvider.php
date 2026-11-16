<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Dashboard;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->multiFactorAuthentication(
                [AppAuthentication::make()->brandName('Anliego')->recoverable()],
                isRequired: fn () => (bool) config('anliego.require_mfa'),
            )
            ->brandName('Anliego')
            ->brandLogo(asset('images/anliego-logo.svg'))
            ->darkModeBrandLogo(asset('images/anliego-logo-dark.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/anliego-icon.svg'))
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->pages([Dashboard::class])
            ->navigationGroups(['Verwaltung'])
            // Benutzerhandbuch (PDF, erzeugt mit scripts/handbuch-pdf.mjs aus docs/handbuch.md)
            ->navigationItems([
                NavigationItem::make('Handbuch')
                    ->url(fn () => self::handbookUrl(), shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->sort(90),
            ])
            ->userMenuItems([
                Action::make('handbuch')->label('Handbuch')
                    ->url(fn () => self::handbookUrl(), shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedBookOpen),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /** Adresse des Handbuchs; der Zeitstempel sorgt dafür, dass nach einem Update nicht die alte Fassung aus dem Cache kommt */
    public static function handbookUrl(): string
    {
        $file = public_path('handbuch.pdf');

        return asset('handbuch.pdf').(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
