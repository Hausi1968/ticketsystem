<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Bereinigt HTML aus eingehenden E-Mails: keine Skripte, keine Formulare, keine
 * Event-Handler und keine nachgeladenen externen Bilder (Tracking-Pixel).
 * Angezeigt wird das Ergebnis zusätzlich in einem abgeschotteten iframe.
 */
class HtmlCleaner
{
    /** Platzhalter-Host für eingebettete Bilder (cid:…); wird bei der Anzeige durch die Anhang-URL ersetzt. */
    public const CID_PREFIX = 'https://cid.anliego.invalid/';

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Der Sanitizer kennt kein cid:-Schema; eingebettete Bilder auf einen Platzhalter-Host umschreiben
        $html = preg_replace_callback(
            '/(src\s*=\s*["\']?)cid:([^"\'\s>]+)/i',
            fn ($m) => $m[1].self::CID_PREFIX.rawurlencode(trim($m[2], '<>')),
            $html,
        );

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowAttribute('class', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('width', ['img', 'table', 'td', 'th'])
            ->allowAttribute('height', ['img', 'table', 'td', 'th'])
            ->allowAttribute('colspan', ['td', 'th'])
            ->allowAttribute('rowspan', ['td', 'th'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->allowMediaHosts(['cid.anliego.invalid'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(2_000_000);

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    /** Nur-Text-Fassung als Ersatz, wenn eine E-Mail keinen Textteil hat. */
    public static function toText(?string $html): string
    {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', (string) $html);
        $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }
}
