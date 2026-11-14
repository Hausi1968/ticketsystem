<?php

namespace App\Support;

/**
 * Schneidet zitierte Vorgängernachrichten aus dem Text einer Antwort ab, damit der
 * Ticketverlauf lesbar bleibt. Der vollständige Text bleibt gespeichert.
 */
class QuoteStripper
{
    private const MARKERS = [
        '/^\s*Am .{4,120}(schrieb|geschrieben).*:\s*$/iu',
        '/^\s*On .{4,120}wrote:\s*$/iu',
        '/^\s*Le .{4,120}a écrit\s*:\s*$/iu',
        '/^\s*-{2,}\s*(Original Message|Ursprüngliche Nachricht|Originalnachricht)\s*-{2,}\s*$/iu',
        '/^\s*_{10,}\s*$/u',
        '/^\s*(Von|From):\s.+$/iu', // Outlook-Kopfblock, nur wenn danach Gesendet/Sent folgt (siehe unten)
    ];

    public static function strip(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $lines = explode("\n", $text);

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }

            foreach (self::MARKERS as $index => $pattern) {
                if (! preg_match($pattern, $line)) {
                    continue;
                }

                // "Von:" allein ist zu unsicher – nur zusammen mit einer Datumszeile direkt danach
                if ($index === 5 && ! preg_match('/^\s*(Gesendet|Sent|Datum|Date):/iu', $lines[$i + 1] ?? '')) {
                    continue;
                }

                return rtrim(implode("\n", array_slice($lines, 0, $i)));
            }
        }

        // Zitatblock mit ">" am Ende der Nachricht
        $result = $lines;
        while ($result && (str_starts_with(ltrim(end($result)), '>') || trim(end($result)) === '')) {
            array_pop($result);
        }

        return rtrim(implode("\n", $result ?: $lines));
    }
}
