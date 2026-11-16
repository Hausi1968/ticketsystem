// Erzeugt public/handbuch.pdf aus docs/handbuch.md (Aufruf: node scripts/handbuch-pdf.mjs).
// Braucht PHP mit installiertem vendor/ (Markdown über league/commonmark) und Playwright mit Chromium.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const { chromium } = createRequire(import.meta.url)('playwright');

const php = `require '${root}/vendor/autoload.php';
$env = new League\\CommonMark\\Environment\\Environment([]);
$env->addExtension(new League\\CommonMark\\Extension\\CommonMark\\CommonMarkCoreExtension());
$env->addExtension(new League\\CommonMark\\Extension\\GithubFlavoredMarkdownExtension());
$env->addExtension(new League\\CommonMark\\Extension\\HeadingPermalink\\HeadingPermalinkExtension());
$env->mergeConfig(['heading_permalink' => ['symbol' => '', 'insert' => 'none', 'id_prefix' => '', 'fragment_prefix' => '']]);
echo (new League\\CommonMark\\MarkdownConverter($env))->convert(file_get_contents('${root}/docs/handbuch.md'));`;
let body = execFileSync('php', ['-r', php], { encoding: 'utf8' });

// Übersichtsgrafik direkt einbetten, damit sie im PDF scharf (als Vektor) bleibt
body = body.replace(/<p><img src="img\/([^"]+\.svg)" alt="[^"]*" \/><\/p>/g, (_, f) =>
  `<figure class="grafik">${readFileSync(join(root, 'docs/img', f), 'utf8').replace(/<\?xml[^>]*>/, '')}</figure>`);

// Bildschirmfotos mit Bildunterschrift (alt-Text)
body = body.replace(/<p><img src="([^"]+\.png)" alt="([^"]*)" \/><\/p>/g,
  '<figure class="foto"><img src="$1" alt="$2"><figcaption>$2</figcaption></figure>');

// Vermerk (Admin) in Überschriften hervorheben
body = body.replace(/(<h[2-4][^>]*>)(.*?)\((Admin(?:, Supervisor)?)\)(<\/h[2-4]>)/g, '$1$2<span class="admin">$3</span>$4');

const html = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Anliego – Benutzerhandbuch</title>
<style>
  @page { size: A4; margin: 18mm 16mm 20mm; }
  body { font-family: Inter, 'Segoe UI', 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; line-height: 1.5; color: #1e293b; }
  h1 { font-size: 26pt; color: #4f46e5; margin: 0 0 4mm; }
  h1 + p { color: #64748b; }
  h2 { font-size: 16pt; color: #312e81; border-bottom: 2px solid #c7d2fe; padding-bottom: 1.5mm; margin-top: 9mm; break-after: avoid; }
  h2:not(:first-of-type) { break-before: page; }
  h3 { font-size: 12.5pt; color: #1e293b; margin-top: 6mm; break-after: avoid; }
  h4 { font-size: 11pt; margin-top: 5mm; break-after: avoid; }
  .admin { display: inline-block; margin-left: 2.5mm; padding: 0.4mm 2.5mm; border-radius: 3mm; background: #f59e0b; color: #fff; font-size: 0.62em; font-weight: 700; vertical-align: middle; }
  table { border-collapse: collapse; width: 100%; margin: 3mm 0; font-size: 9.5pt; break-inside: auto; }
  th, td { border: 1px solid #e2e8f0; padding: 1.6mm 2.2mm; text-align: left; vertical-align: top; }
  th { background: #eef2ff; }
  tr { break-inside: avoid; }
  code { background: #f1f5f9; padding: 0 1mm; border-radius: 1mm; font-size: 0.92em; }
  blockquote { margin: 4mm 0; padding: 2mm 4mm; border-left: 3px solid #4f46e5; background: #f8fafc; color: #475569; }
  hr { display: none; }
  figure.grafik { margin: 5mm 0; break-inside: avoid; }
  figure.grafik svg { width: 100%; height: auto; border: 1px solid #e2e8f0; border-radius: 2mm; }
  figure.foto { margin: 4mm 0; break-inside: avoid; }
  figure.foto img { display: block; width: 100%; height: auto; border: 1px solid #e2e8f0; border-radius: 2mm; }
  figure.foto figcaption { font-size: 8.5pt; color: #64748b; margin-top: 1.2mm; }
  p > img { display: block; width: 100%; height: auto; margin: 4mm 0 1mm; border: 1px solid #e2e8f0; border-radius: 2mm; break-inside: avoid; }
  ul, ol { padding-left: 6mm; }
  li { margin: 0.6mm 0; }
</style></head><body>${body}</body></html>`;

const tmp = join(root, 'docs', '.handbuch-druck.html');
writeFileSync(tmp, html);
const browser = await chromium.launch();
try {
  const page = await browser.newPage();
  await page.goto('file://' + tmp, { waitUntil: 'load' });
  await page.pdf({
    path: join(root, 'public', 'handbuch.pdf'),
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-size:8pt;color:#94a3b8;padding:0 16mm;display:flex;justify-content:space-between;font-family:sans-serif"><span>Anliego – Benutzerhandbuch</span><span>Seite <span class="pageNumber"></span> von <span class="totalPages"></span></span></div>',
    margin: { top: '18mm', bottom: '20mm', left: '16mm', right: '16mm' },
    tagged: true,
    outline: true,
  });
} finally {
  await browser.close();
  unlinkSync(tmp);
}
console.log('public/handbuch.pdf erstellt');
