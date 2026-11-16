<?php

use App\Backup\DatabaseBackup;
use App\Filament\Pages\Backups;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->backup = app(DatabaseBackup::class);
    foreach ($this->backup->list() as $file) {
        unlink($file['path']);
    }
});

afterEach(function () {
    foreach ($this->backup->list() as $file) {
        unlink($file['path']);
    }
});

it('sichert die Datenbank als gepackte SQL-Datei und behält nur die neuesten', function () {
    Tenant::factory()->create(['name' => "O'Brien & Söhne"]);

    $this->artisan('anliego:backup')->assertSuccessful();
    $files = $this->backup->list();
    expect($files)->toHaveCount(1);

    $sql = gzdecode(file_get_contents($files[0]['path']));
    expect($sql)->toContain('CREATE TABLE')->toMatch('/INSERT INTO [`"]tenants[`"]/')->toContain('Brien & Söhne');

    // Ergebnis lässt sich wieder einspielen
    if (DB::getDriverName() === 'sqlite') {
        $db = new PDO('sqlite::memory:');
        $db->exec($sql);
        expect($db->query("SELECT name FROM tenants WHERE name LIKE 'O%'")->fetchColumn())->toBe("O'Brien & Söhne");
    }

    foreach (['2026-01-01-000000', '2026-01-02-000000', '2026-01-03-000000'] as $stamp) {
        copy($files[0]['path'], $this->backup->directory()."/anliego-{$stamp}.sql.gz");
    }
    expect($this->backup->prune(2))->toBe(2)
        ->and(array_column($this->backup->list(), 'name'))->toBe([$files[0]['name'], 'anliego-2026-01-03-000000.sql.gz']);
});

it('zeigt die Sicherungen nur Administratoren und liefert keine fremden Dateien aus', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/datensicherung')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Livewire::test(Backups::class)->callTableAction('now')->assertNotified();
    expect($this->backup->list())->toHaveCount(1)
        ->and($this->backup->path('../../.env'))->toBeNull();
});
