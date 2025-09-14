<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class PisrsHtml
{
    /** Prenesi javno HTML stran za predpis in vrni [ 'title' => ..., 'articles' => [ ['number'=>.., 'text'=>..], ... ] ] */
    public function fetchActAndArticles(string $mopedId): array
    {
        $url = "https://www.pisrs.si/Pis.web/pregledPredpisa?id={$mopedId}";
        $resp = Http::withHeaders([
            'User-Agent' => 'ai-law-assistant/1.0 (+https://example.local)'
        ])->get($url);

        $resp->throw();

        $html = $resp->body();

        // Naslov (preprosto heuristično)
        $title = $this->extractTitle($html) ?: $mopedId;

        // Poskusi 2 strategiji za člene
        $articles = $this->extractArticlesStrategyA($html);
        if (empty($articles)) {
            $articles = $this->extractArticlesStrategyB($html);
        }

        // Normaliziraj
        $out = [];
        foreach ($articles as $art) {
            $num  = trim($art['number'] ?? '');
            $text = trim($art['text'] ?? '');
            if ($num === '' || $text === '') continue;
            $out[] = ['number' => $num, 'text' => $text];
        }

        return [
            'id'       => $mopedId,
            'title'    => $title,
            'url'      => $url,
            'articles' => $out,
        ];
    }

    /** Hevristika za <title> ali H1 */
    protected function extractTitle(string $html): ?string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) && trim($m[1]) !== '') {
            return html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m) && trim($m[1]) !== '') {
            return html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
        // Za tršo rešitev lahko dodaš Symfony DomCrawler, a za MVP naj bo brez dodatne odvisnosti.
    }

    /**
     * Strategija A:
     * - veliko PISRS strani ima sidra/ID-je na členih npr. id="clen-89" ali id="article-89"
     * - členski blok je pogosto <h3>/<h4> za naslov člena + sosednji <p> ali <div> z vsebino
     */
    protected function extractArticlesStrategyA(string $html): array
    {
        $arts = [];

        // naslov člena (npr. "89. člen") v <h3> ali <h4>, potem pa nekaj odstavkov do naslednjega naslova
        $pattern = '~<(h3|h4)[^>]*>\s*(\d+)\.\s*člen\b.*?</\1>(.*?)((?=<h3|<h4)|$)~isu';
        if (preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $num  = $match[2];
                $body = trim($this->cleanHtml($match[3]));
                if ($body !== '') {
                    $arts[] = ['number' => $num, 'text' => $body];
                }
            }
        }
        return $arts;
    }

    /**
     * Strategija B:
     * - nekatere strani imajo strukturo: <a id="clen-89">...</a> potem besedilo v več <p>
     * - ujamemo sidra in besedilo do naslednjega sidra
     */
    protected function extractArticlesStrategyB(string $html): array
    {
        $arts = [];
        $pattern = '~<a\s+id="(?:clen|article)-(\d+)"[^>]*></a>(.*?)(?=<a\s+id="(?:clen|article)-\d+"|$)~is';
        if (preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $num  = $match[1];
                $body = trim($this->cleanHtml($match[2]));
                if ($body !== '') {
                    $arts[] = ['number' => $num, 'text' => $body];
                }
            }
        }
        return $arts;
    }

    protected function cleanHtml(string $frag): string
    {
        // odstrani skripte, stile in prekomerne presledke
        $frag = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $frag);
        $frag = strip_tags($frag, '<p><br><li>');
        $frag = html_entity_decode($frag, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $frag = preg_replace('~\s+~u', ' ', $frag);
        // ohrani osnovne prelome odstavkov
        $frag = preg_replace('~\s*(</p>|<br\s*/?>)\s*~i', "\n", $frag);
        return trim($frag);
    }
}
