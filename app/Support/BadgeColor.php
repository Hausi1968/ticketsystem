<?php

namespace App\Support;

/** Farbauswahl für Status und Kategorien (Filament-Farbnamen, passen zu hellem und dunklem Design). */
class BadgeColor
{
    public const OPTIONS = [
        'gray' => 'Grau',
        'info' => 'Blau',
        'primary' => 'Indigo',
        'success' => 'Grün',
        'warning' => 'Gelb/Orange',
        'danger' => 'Rot',
    ];

    public static function resolve(?string $color): string
    {
        return array_key_exists((string) $color, self::OPTIONS) ? $color : 'gray';
    }
}
