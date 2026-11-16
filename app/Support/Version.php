<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Version der Weboberfläche. Die Nummer steht in config/anliego.php und wird bei jeder Änderung erhöht,
 * die Mitarbeiter betrifft. Stand und Commit schreibt der Deploy-Workflow nach bootstrap/build.json.
 */
class Version
{
    public static function number(): string
    {
        return (string) config('anliego.version');
    }

    /** @return array{commit: ?string, date: ?Carbon} */
    public static function build(): array
    {
        static $build;
        if ($build === null) {
            $file = base_path('bootstrap/build.json');
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $build = [
                'commit' => is_array($data) && is_string($data['commit'] ?? null) ? substr($data['commit'], 0, 7) : null,
                'date' => is_array($data) && is_string($data['date'] ?? null) ? Carbon::parse($data['date'])->timezone('Europe/Berlin') : null,
            ];
        }

        return $build;
    }

    /** z. B. „1.1.0 · Stand 16.11.2026 15:10 (819254e)“ */
    public static function label(): string
    {
        $build = self::build();
        $parts = [self::number()];
        if ($build['date']) {
            $parts[] = 'Stand '.$build['date']->format('d.m.Y H:i').($build['commit'] ? " ({$build['commit']})" : '');
        }

        return implode(' · ', $parts);
    }

    /** Für die API der Mitarbeiter-App */
    public static function toArray(): array
    {
        $build = self::build();

        return [
            'version' => self::number(),
            'label' => self::label(),
            'commit' => $build['commit'],
            'built_at' => $build['date']?->toIso8601String(),
        ];
    }
}
