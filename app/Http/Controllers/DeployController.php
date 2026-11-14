<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Für Hosting ohne SSH: Nach dem Upload ruft GitHub Actions diese Adresse mit dem geheimen
 * Token auf, um Datenbank-Migrationen auszuführen und Caches neu aufzubauen.
 * Ohne gesetztes ANLIEGO_DEPLOY_TOKEN ist die Route nicht erreichbar.
 */
class DeployController
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('anliego.deploy_token');
        $given = (string) $request->header('X-Deploy-Token', '');

        abort_if($token === '' || ! hash_equals($token, $given), 404);

        $output = [];
        foreach (['migrate --force', 'optimize:clear', 'optimize', 'filament:optimize'] as $command) {
            Artisan::call($command);
            $output[] = "> {$command}\n".trim(Artisan::output());
        }

        // Ersten Administrator anlegen, solange es noch keinen Benutzer gibt (Hosting ohne SSH)
        $email = (string) config('anliego.initial_admin.email');
        $password = (string) config('anliego.initial_admin.password');
        if ($email !== '' && strlen($password) >= 12 && User::query()->doesntExist()) {
            User::create([
                'name' => (string) config('anliego.initial_admin.name', 'Administrator'),
                'email' => $email,
                'password' => $password,
                'is_admin' => true,
            ]);
            $output[] = "Erster Administrator {$email} angelegt. Bitte ANLIEGO_INITIAL_ADMIN_PASSWORD jetzt aus der .env entfernen.";
        }

        return response(implode("\n\n", $output)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
