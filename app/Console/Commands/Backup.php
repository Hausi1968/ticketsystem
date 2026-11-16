<?php

namespace App\Console\Commands;

use App\Backup\DatabaseBackup;
use Illuminate\Console\Command;

class Backup extends Command
{
    protected $signature = 'anliego:backup {--keep= : Anzahl Sicherungen, die bleiben}';

    protected $description = 'Sichert die Datenbank nach storage/app/private/backups und löscht alte Sicherungen';

    public function handle(DatabaseBackup $backup): int
    {
        @set_time_limit(600);
        $path = $backup->create();
        $deleted = $backup->prune((int) ($this->option('keep') ?: config('anliego.backup_keep', 14)));

        $this->line('Gesichert: '.basename($path).' ('.round(filesize($path) / 1024).' KB), '.$deleted.' alte gelöscht.');

        return self::SUCCESS;
    }
}
