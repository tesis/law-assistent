<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Services\PisrsApi;
use App\Services\ArticleExtractor;
use App\Services\PdfText;
use App\Services\RagService;

class PisrsIngestApi extends Command
{
    protected $signature = 'pisrs:ingest-zbirka {mopedId} {--tenant=PUBLIC} {--prefer=auto}';
    protected $description = 'Zbirka → NPB → (extapi HTML ali details HTML/PDF) → členi → upsert (with headings)';

    public function handle(PisrsApi $api, RagService $rag, ArticleExtractor $extractor, ?PdfText $pdf = null)
    {
        $mopedId = trim($this->argument('mopedId'));
        $tenant  = (string) $this->option('tenant');
        $prefer  = (string) $this->option('prefer'); // auto|html|pdf

        // 1) ZBIRKA
        try {
            $zb = $api->getZbirka($mopedId);
        } catch (\Throwable $e) {
            $this->error("Napaka pri klicu zbirke za {$mopedId}: ".$e->getMessage());
            return self::FAILURE;
        }

        // robust read of title with/without data wrapper
        $title = data_get($zb, 'data.evidencniPodatki.naslov')
              ?? data_get($zb, 'evidencniPodatki.naslov')
              ?? data_get($zb, 'data.naslov')
              ?? $mopedId;

        $url   = "https://www.pisrs.si/Pis.web/pregledPredpisa?id={$mopedId}";

        // 2) IZBERI NPB (zadnji)
        $npbId = $api->pickLatestNpbId($zb);
        if (!$npbId) {
            $this->warn('V zbirki nisem našel NPB verzij.');
            return self::SUCCESS;
        }
        $this->info("NPB izbran: {$npbId}");

        // 3) PRIDOBI VSEBINO (HTML/PDF)
        $articles = [];

        // 3A) extapi/besedilo → HTML (prefer)
        if ($prefer !== 'pdf') {
            $html = $api->getNpbHtmlViaExtapi($npbId)
                 ?? $api->getNpbHtmlViaDetails($npbId); // fallback: details→HTML
            if (!empty($html)) {
                Storage::disk('local')->put("pisrs/{$mopedId}_npb{$npbId}.html", $html);
                $this->line("Saved HTML to storage/app/pisrs/{$mopedId}_npb{$npbId}.html");
                $articles = $extractor->fromHtml($html);
            }
        }

        // 3B) PDF fallback
        if (empty($articles)) {
            $pdfBytes = $api->getNpbPdfViaDetails($npbId);
            if (!empty($pdfBytes)) {
                Storage::disk('local')->put("pisrs/{$mopedId}_npb{$npbId}.pdf", $pdfBytes);
                $this->line("Saved PDF to storage/app/pisrs/{$mopedId}_npb{$npbId}.pdf");

                if (!$pdf) $pdf = app(PdfText::class);
                $text = $pdf->extract($pdfBytes);
                $articles = $extractor->fromText($text);
            }
        }

        if (empty($articles)) {
            $this->warn('Nisem uspel izluščiti členov (HTML/PDF). Preglej selectors/regex ali poglej shranjen HTML/PDF.');
            return self::SUCCESS;
        }

        // 4) UPSERT členov — include heading in citation/title so retrieval sees keywords
        $saved = 0;
        foreach ($articles as $a) {
            $no   = (string) ($a['number'] ?? '');
            $text = trim((string) ($a['text'] ?? ''));
            $head = (string) ($a['heading'] ?? '');

            if ($no === '' || $text === '') continue;

            // strip the "99. člen" prefix from heading to get a short, keyword-rich title
            $shortHead = preg_replace('~^\s*\d{1,4}(?:\.[a-z]|[a-z])?\s*\.?\s*člen\s*[:\-–]?\s*~iu', '', $head);
            $citation  = $shortHead ? "{$no}. člen — {$shortHead}" : "{$no}. člen";
            $titleForChunk = $shortHead ? "{$title} — {$no}. člen — {$shortHead}" : "{$title} — {$no}. člen";

            $rag->upsertChunk([
                'tenant_id' => $tenant,
                'source'    => 'PISRS',
                'title'     => $titleForChunk,
                'citation'  => $citation,
                'url'       => $url,
                'text'      => $text,     // includes heading + body
                'law_id'    => $mopedId,
            ]);

            $this->line("OK: {$title} — člen {$no}");
            $saved++;
            usleep(80_000); // be polite to upstream
        }

        $this->info("✅ Končano. Vnesenih členov: {$saved}.");
        return self::SUCCESS;
    }
}
