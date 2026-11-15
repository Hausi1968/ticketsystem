<?php

namespace App\Sla;

use Carbon\CarbonImmutable;

/**
 * Gesetzliche Feiertage in Deutschland, bundesweit und je Bundesland.
 * Feiertage, die nur in einzelnen Gemeinden gelten (z. B. Mariä Himmelfahrt in Teilen Bayerns,
 * Fronleichnam in Teilen Sachsens und Thüringens), trägt man in der Richtlinie zusätzlich ein.
 */
class GermanHolidays
{
    public const REGIONS = [
        'BW' => 'Baden-Württemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
        'DE' => 'nur bundesweite Feiertage',
    ];

    /** @return array<string, string> 'Y-m-d' => Name */
    public static function for(int $year, string $region): array
    {
        $easter = self::easterSunday($year);
        $e = fn (int $days) => $easter->addDays($days)->format('Y-m-d');
        $d = fn (int $month, int $day) => sprintf('%04d-%02d-%02d', $year, $month, $day);
        $in = fn (string ...$regions) => in_array($region, $regions, true);

        $days = [
            $d(1, 1) => 'Neujahr',
            $e(-2) => 'Karfreitag',
            $e(1) => 'Ostermontag',
            $d(5, 1) => 'Tag der Arbeit',
            $e(39) => 'Christi Himmelfahrt',
            $e(50) => 'Pfingstmontag',
            $d(10, 3) => 'Tag der Deutschen Einheit',
            $d(12, 25) => '1. Weihnachtstag',
            $d(12, 26) => '2. Weihnachtstag',
        ];

        if ($in('BW', 'BY', 'ST')) {
            $days[$d(1, 6)] = 'Heilige Drei Könige';
        }
        if ($in('BE') || ($in('MV') && $year >= 2023)) {
            $days[$d(3, 8)] = 'Internationaler Frauentag';
        }
        if ($in('BB')) {
            $days[$e(0)] = 'Ostersonntag';
            $days[$e(49)] = 'Pfingstsonntag';
        }
        if ($in('BW', 'BY', 'HE', 'NW', 'RP', 'SL')) {
            $days[$e(60)] = 'Fronleichnam';
        }
        if ($in('SL')) {
            $days[$d(8, 15)] = 'Mariä Himmelfahrt';
        }
        if ($in('TH') && $year >= 2019) {
            $days[$d(9, 20)] = 'Weltkindertag';
        }
        if ($in('BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH')) {
            $days[$d(10, 31)] = 'Reformationstag';
        }
        if ($in('BW', 'BY', 'NW', 'RP', 'SL')) {
            $days[$d(11, 1)] = 'Allerheiligen';
        }
        if ($in('SN')) {
            // Mittwoch vor dem 23. November
            $days[CarbonImmutable::create($year, 11, 23)->previous(CarbonImmutable::WEDNESDAY)->format('Y-m-d')] = 'Buß- und Bettag';
        }

        ksort($days);

        return $days;
    }

    /** Ostersonntag nach der Gaußschen Osterformel (ohne die PHP-Erweiterung "calendar") */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
