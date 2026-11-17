<?php

namespace App\Mail\Outbound;

use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\HtmlCleaner;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML-Signaturen der Teams. Jedes Team (und damit jeder Mandant) kann eine eigene Signatur im
 * Stil des Auftraggebers pflegen, optional mit Logo. Das Logo wird beim Versand in die E-Mail
 * eingebettet (CID), damit es auch in Outlook & Co. ohne „Bilder nachladen“ erscheint.
 */
class TeamSignature
{
    /** Dateiname des eingebetteten Logos; im HTML als cid:… referenziert */
    public const LOGO_NAME = 'signatur-logo';

    /** Platzhalter für die Pflege der Signatur */
    public const PLACEHOLDERS = [
        '{{logo}}' => 'Logo des Teams (hochgeladenes Bild)',
        '{{name}}' => 'Name des antwortenden Mitarbeiters',
        '{{vorname}}' => 'Vorname des antwortenden Mitarbeiters',
        '{{team}}' => 'Name des Teams',
        '{{mandant}}' => 'Name des Mandanten',
    ];

    public const MAX_LOGO_WIDTH = 600;

    /**
     * Bereinigt das HTML einer Signatur: keine Skripte, Formulare, Event-Handler oder iframes.
     * Gestaltung (style, Tabellen-Layout, Farben) und Links bleiben erhalten, Bilder nur per https.
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowAttribute('class', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('valign', ['td', 'th', 'tr', 'table'])
            ->allowAttribute('bgcolor', ['table', 'td', 'th', 'tr'])
            ->allowAttribute('border', ['table', 'img'])
            ->allowAttribute('cellpadding', ['table'])
            ->allowAttribute('cellspacing', ['table'])
            ->allowAttribute('width', ['img', 'table', 'td', 'th'])
            ->allowAttribute('height', ['img', 'table', 'td', 'th', 'tr'])
            ->allowAttribute('colspan', ['td', 'th'])
            ->allowAttribute('rowspan', ['td', 'th'])
            ->allowAttribute('color', ['font'])
            ->allowAttribute('face', ['font'])
            ->allowAttribute('size', ['font'])
            ->allowElement('font', ['color', 'face', 'size', 'style'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->withMaxInputLength(200_000);

        $clean = trim((new HtmlSanitizer($config))->sanitize($html));

        return $clean === '' ? null : $clean;
    }

    public static function has(Team $team): bool
    {
        return trim((string) $team->signature_html) !== '' || (string) $team->signature_logo_path !== '';
    }

    /** Teams des Mandanten mit Signatur; das Team des Tickets steht vorn. */
    public static function availableFor(Ticket $ticket)
    {
        return Team::query()->withoutGlobalScopes()->where('tenant_id', $ticket->tenant_id)
            ->where(fn ($q) => $q->whereNotNull('signature_html')->orWhereNotNull('signature_logo_path'))
            ->orderByRaw('id = ? desc', [(int) $ticket->team_id])->orderBy('name')
            ->get()->filter(fn (Team $t) => self::has($t))->values();
    }

    /**
     * Fertige Signatur für eine Antwort. Das Logo zeigt auf den Platzhalter-Host für eingebettete
     * Bilder (wie bei eingehenden E-Mails); ReplySender macht daraus beim Versand cid:….
     *
     * @param  ?string  $logoSrc  abweichende Bildquelle, z. B. data:-URL für die Vorschau
     */
    public static function render(Team $team, ?User $agent = null, ?string $logoSrc = null, ?string $html = null, ?bool $withLogo = null, ?int $logoWidth = null): string
    {
        $html = self::clean($html ?? $team->signature_html) ?? '';
        $withLogo ??= (string) $team->signature_logo_path !== '';
        $tenantName = (string) ($team->tenant()->withoutGlobalScopes()->value('name') ?? '');

        $logo = '';
        if ($withLogo) {
            $width = max(16, min(self::MAX_LOGO_WIDTH, (int) ($logoWidth ?? $team->signature_logo_width ?: 180)));
            $src = $logoSrc ?? HtmlCleaner::CID_PREFIX.rawurlencode(self::LOGO_NAME);
            $logo = sprintf(
                '<img src="%s" alt="%s" width="%d" style="display:block;border:0;outline:none;text-decoration:none;width:%dpx;max-width:100%%;height:auto">',
                e($src), e($tenantName ?: $team->name), $width, $width,
            );
        }

        $name = (string) ($agent?->name ?? '');
        $values = [
            '{{name}}' => e($name),
            '{{vorname}}' => e(strtok($name, ' ') ?: $name),
            '{{team}}' => e($team->name),
            '{{mandant}}' => e($tenantName),
        ];

        if ($logo !== '' && ! str_contains($html, '{{logo}}')) {
            // Ohne Platzhalter steht das Logo unter dem Text
            $html .= ($html !== '' ? '<br>' : '').'{{logo}}';
        }
        $values['{{logo}}'] = $logo;

        return strtr($html, $values);
    }

    /** Nur-Text-Fassung für den Textteil der E-Mail und den Verlauf */
    public static function text(string $renderedHtml): string
    {
        $withoutImages = preg_replace('#<img[^>]*>#i', '', $renderedHtml);
        $text = HtmlCleaner::toText(preg_replace('#</td>#i', ' </td>', $withoutImages));

        return trim(preg_replace("/[ \t]*\n[ \t]*/", "\n", $text));
    }

    /** Inhalt des Logos als data:-URL (Vorschau in der Verwaltung) */
    public static function logoDataUrl(?string $path, ?string $contents = null, ?string $mime = null): ?string
    {
        if ($contents === null) {
            if (! $path || ! Storage::disk('local')->exists($path)) {
                return null;
            }
            $contents = Storage::disk('local')->get($path);
            $mime = Storage::disk('local')->mimeType($path);
        }

        return 'data:'.($mime ?: 'image/png').';base64,'.base64_encode($contents);
    }

    /** Vorschau als komplettes HTML-Dokument für ein abgeschottetes iframe */
    public static function previewDocument(string $renderedHtml): string
    {
        $csp = "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'";

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'.e($csp).'">'
            .'<base target="_blank"><style>body{font-family:Arial,sans-serif;font-size:14px;line-height:1.5;margin:12px;color:#0f172a}</style></head><body>'
            .$renderedHtml.'</body></html>';
    }
}
