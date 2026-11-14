<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'anliego:create-admin {email} {name} {--password= : Ohne Angabe wird nachgefragt}';

    protected $description = 'Legt einen Administrator an oder macht einen vorhandenen Benutzer zum Administrator';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Passwort (mind. 12 Zeichen)');

        if (strlen((string) $password) < 12) {
            $this->error('Das Passwort muss mindestens 12 Zeichen haben.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->argument('name'), 'password' => Hash::make($password), 'is_admin' => true, 'active' => true],
        );

        $this->info("Administrator {$user->email} ist eingerichtet.");

        return self::SUCCESS;
    }
}
