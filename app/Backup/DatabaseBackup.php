<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Sicherung der Datenbank als gepackte SQL-Datei, ohne mysqldump (auf Webhosting meist nicht
 * aufrufbar). Liegt in storage/app/private/backups, nur für Administratoren abrufbar.
 * Wiederherstellen: Datei entpacken und z. B. in phpMyAdmin importieren.
 */
class DatabaseBackup
{
    public const DIR = 'backups';

    public function directory(): string
    {
        return storage_path('app/private/'.self::DIR);
    }

    /** Erstellt eine Sicherung und liefert den Dateipfad */
    public function create(): string
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Ordner {$dir} kann nicht angelegt werden.");
        }

        $path = $dir.'/anliego-'.now()->format('Y-m-d-His').'.sql.gz';
        $tmp = $path.'.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Datei {$tmp} kann nicht geschrieben werden.");
        }

        try {
            $this->dump(fn (string $sql) => gzwrite($gz, $sql));
        } finally {
            gzclose($gz);
        }

        rename($tmp, $path);
        @chmod($path, 0600);

        return $path;
    }

    /** Alte Sicherungen löschen, die neuesten $keep bleiben */
    public function prune(int $keep): int
    {
        $files = $this->list();
        $deleted = 0;
        foreach (array_slice($files, max(1, $keep)) as $file) {
            @unlink($file['path']) && $deleted++;
        }

        return $deleted;
    }

    /** @return list<array{name: string, path: string, size: int, time: int}> neueste zuerst */
    public function list(): array
    {
        $files = glob($this->directory().'/anliego-*.sql.gz') ?: [];
        $list = array_map(fn (string $p) => ['name' => basename($p), 'path' => $p, 'size' => (int) filesize($p), 'time' => (int) filemtime($p)], $files);
        usort($list, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    public function path(string $name): ?string
    {
        if (! preg_match('/^anliego-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $name)) {
            return null;
        }
        $path = $this->directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    /** @param callable(string): mixed $write */
    private function dump(callable $write): void
    {
        $db = DB::connection();
        $driver = $db->getDriverName();
        $pdo = $db->getPdo();

        $write('-- Anliego Datensicherung '.now()->toIso8601String().' ('.$driver.")\n");
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        }

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $q = $driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`';

            if ($driver === 'sqlite') {
                $create = (string) $db->scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
            } else {
                $row = (array) $db->selectOne("SHOW CREATE TABLE {$q}");
                $create = (string) ($row['Create Table'] ?? array_values($row)[1] ?? '');
            }
            if ($create === '') {
                continue;
            }

            $write("DROP TABLE IF EXISTS {$q};\n{$create};\n");

            $batch = [];
            foreach ($db->table($table)->cursor() as $row) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), (array) $row);
                $batch[] = '('.implode(',', $values).')';
                if (count($batch) >= 200) {
                    $write("INSERT INTO {$q} VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write("INSERT INTO {$q} VALUES\n".implode(",\n", $batch).";\n");
            }
            $write("\n");
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        }
    }
}
