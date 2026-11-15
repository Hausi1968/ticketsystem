<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Throwable;

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

        // Webhosting hat oft knappe Grenzen; das Aufbauen der Caches braucht etwas mehr
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $output = [];
        $migrated = true;
        foreach (['migrate --force', 'optimize:clear', 'optimize', 'filament:optimize'] as $command) {
            try {
                Artisan::call($command);
                $output[] = "> {$command}\n".trim(Artisan::output());
            } catch (Throwable $e) {
                report($e);
                $output[] = "> {$command}\nFEHLER: ".get_class($e).': '.$e->getMessage()
                    .' ('.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine().')';
                if ($command === 'migrate --force') {
                    $migrated = false;
                    break; // ohne passende Datenbank keine Caches aufbauen
                }
                // Caches sind nur Beschleunigung: lieber ohne als mit halbem Stand weiterlaufen
                try {
                    Artisan::call('optimize:clear');
                } catch (Throwable) {
                }
            }
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

        return response(implode("\n\n", $output)."\n", $migrated ? 200 : 500, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
