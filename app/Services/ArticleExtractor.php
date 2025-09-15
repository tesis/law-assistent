<?php
namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class ArticleExtractor
{
    /**
     * Extract articles from an HTML document.
     * Returns: [['number' => '89', 'heading' => '89. člen — …', 'text' => "89. člen — …\n..."], ...]
     */
    public function fromHtml(string $html): array
    {
        $c = new Crawler($html);
        $articles = [];

        // --- Strategy PISRS: paragraph-based structure with classes (naslov, clen, odstavek, stevilcna_tocka, ...) ---
        $paras = $c->filter('p');
        $count = $paras->count();

        if ($count > 0) {
            // Index all <p> nodes so we can random-access neighbors
            $ps = [];
            for ($i = 0; $i < $count; $i++) {
                $ps[$i] = $paras->eq($i);
            }

            $i = 0;
            while ($i < $count) {
                $p = $ps[$i];
                $classes = (string)($p->attr('class') ?? '');
                $textClean = $this->clean($p->text(''));

                // article boundary: <p class="... clen ..."> and heading pattern like "1. člen" or "367a. člen"
                if (str_contains($classes, 'clen') && $this->isArticleHeading($textClean, $num)) {
                    // Heading: number line, optionally followed by a subtitle line (often also class "clen")
                    $heading = $textClean; // e.g. "1. člen"
                    $j = $i + 1;

                    // Optional subtitle: usually a parenthetical on the next <p class="clen"> line, e.g. "(namen zakona)"
                    if ($j < $count) {
                        $next = $ps[$j];
                        $nextClasses = (string)($next->attr('class') ?? '');
                        $nextText = $this->clean($next->text(''));

                        $isSubtitleCandidate =
                            str_contains($nextClasses, 'clen')
                            && !$this->isArticleHeading($nextText, $tmpNum) // don't consume if it's another numbered člen
                            && preg_match('~^\(.*\)$~u', $nextText);       // looks like "(...)" subtitle

                        if ($isSubtitleCandidate) {
                            $heading .= ' — ' . $nextText;
                            $j++; // consume subtitle
                        }
                    }

                    // Collect body until next numbered p.clen
                    $buf = [];
                    $safeguardLen = 0;
                    for (; $j < $count; $j++) {
                        $node = $ps[$j];
                        $nodeClasses = (string)($node->attr('class') ?? '');
                        $nodeText = $this->clean($node->text(''));

                        // Stop at the next article boundary (another p.clen that matches "X. člen")
                        if (str_contains($nodeClasses, 'clen') && $this->isArticleHeading($nodeText, $dummy)) {
                            break;
                        }

                        // Otherwise, include this paragraph (odstavek, stevilcna_tocka, etc.)
                        $buf[] = $node->outerHtml();
                        $safeguardLen += strlen(end($buf));
                        if ($safeguardLen > 120000) { // hard stop to avoid pathological cases
                            break;
                        }
                    }

                    $bodyText = $this->htmlToText(implode("\n", $buf));
                    $fullText = trim($heading . "\n" . $bodyText);

                    if ($fullText !== '') {
                        $articles[] = ['number' => $num, 'heading' => $heading, 'text' => $fullText];
                    }

                    // continue scanning from where we left off
                    $i = $j;
                    continue;
                }

                $i++;
            }
        }

        // --- Fallbacks (if structured pass found nothing) ---
        if (empty($articles)) {
            // Old Strategy A: headings (in case some pages still use h1–h4)
            $c->filter('h1,h2,h3,h4')->each(function (Crawler $h) use (&$articles) {
                $heading = $this->clean($h->text(''));
                if (!$this->isArticleHeading($heading, $num)) return;
                $body = $this->collectUntilNextHeading($h);
                $text = trim($heading . "\n" . $body);
                if ($text !== '') {
                    $articles[] = ['number' => $num, 'heading' => $heading, 'text' => $text];
                }
            });
        }

        // anchors fallback
        if (empty($articles)) {
            $c->filter('a[id^="clen-"], a[id^="article-"]')->each(function (Crawler $a) use (&$articles) {
                $id = (string)($a->attr('id') ?? '');
                if (!preg_match('~(?:clen|article)-(\d{1,4}[a-z]?)~i', $id, $m)) return;
                $num = $m[1];
                $body = $this->collectUntilNextAnchorOrHeading($a);
                $heading = "{$num}. člen";
                $text = trim($heading . "\n" . $body);
                if ($text !== '') {
                    $articles[] = ['number' => $num, 'heading' => $heading, 'text' => $text];
                }
            });
        }

        // structured blocks fallback
        if (empty($articles)) {
            $c->filter('div.clen, article.clen, div.article, section.article')->each(function (Crawler $blk) use (&$articles) {
                $head = $blk->filter('.stevilka, .naslov, header, h3, h4, strong');
                $body = $blk->filter('.besedilo, .vsebina, .content, .text, p, div');
                if (!$head->count() || !$body->count()) return;
                $ht = $this->clean($head->text(''));
                if (!$this->isArticleHeading($ht, $num)) return;
                $parts = [];
                $body->each(function (Crawler $n) use (&$parts) { $parts[] = $n->outerHtml(); });
                $bodyText = $this->htmlToText(implode("\n", $parts));
                $text = trim($ht . "\n" . $bodyText);
                if ($text !== '') {
                    $articles[] = ['number' => $num, 'heading' => $ht, 'text' => $text];
                }
            });
        }

        // text fallback
        if (empty($articles)) {
            $plain = $this->htmlToText($html);
            $articles = $this->fromText($plain);
            foreach ($articles as &$a) {
                if (empty($a['heading'])) {
                    $a['heading'] = "{$a['number']}. člen";
                    $a['text'] = "{$a['heading']}\n{$a['text']}";
                }
            }
            unset($a);
        }

        return $this->dedupeAndSort($articles);
    }

    /**
     * Extract articles from plain text (e.g., PDF→text).
     * Returns [['number'=>'89','heading'=>'89. člen','text'=>'89. člen\n...'], ...]
     */
    public function fromText(string $text): array
    {
        // normalize whitespace
        $t = preg_replace('~\s+~u', ' ', trim($text));

        // supports: "89. člen", "89 . člen", "89.a člen", "367a. člen"
        $split = '/(?=\b\d{1,4}(?:\.[a-z]|[a-z])?\s*\.?\s*člen\b)/iu';

        $parts = preg_split($split, $t, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^(\d{1,4}(?:\.[a-z]|[a-z])?)\s*\.?\s*člen\b(.*)$/iu', $p, $m)) {
                $num = $m[1];
                $body = trim($m[2]);
                $heading = "{$num}. člen";
                $textOut = trim($heading . "\n" . $body);
                if ($textOut !== '') {
                    $out[] = ['number' => $num, 'heading' => $heading, 'text' => $textOut];
                }
            }
        }
        return $this->dedupeAndSort($out);
    }

    /* ---------------- helpers ---------------- */

    private function isArticleHeading(string $heading, ?string &$num): bool
    {
        // Accepts "1. člen", "1 . član", "1.a člen", "367a. člen"
        if (preg_match('~^(\d{1,4}(?:\.[a-z]|[a-z])?)\s*\.?\s*člen\b~iu', $heading, $m)) {
            $num = $m[1];
            return true;
        }
        return false;
    }

    private function collectUntilNextHeading(Crawler $h): string
    {
        $buf = [];
        for ($node = $h->nextAll()->first(); $node->count(); $node = $node->nextAll()->first()) {
            $nm = strtolower($node->nodeName());
            if (in_array($nm, ['h1','h2','h3','h4'], true)) break;
            $buf[] = $node->outerHtml();
            if (strlen(implode(' ', $buf)) > 60000) break;
        }
        return $this->htmlToText(implode("\n", $buf));
    }

    private function collectUntilNextAnchorOrHeading(Crawler $a): string
    {
        $buf = [];
        for ($node = $a->nextAll()->first(); $node->count(); $node = $node->nextAll()->first()) {
            $sid = (string)($node->attr('id') ?? '');
            if (preg_match('~(?:clen|article)-\d+[a-z]?~i', $sid)) break;
            $nm = strtolower($node->nodeName());
            if (in_array($nm, ['h1','h2','h3','h4'], true)) break;
            $buf[] = $node->outerHtml();
            if (strlen(implode(' ', $buf)) > 60000) break;
        }
        return $this->htmlToText(implode("\n", $buf));
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('~\s+~u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function htmlToText(string $html): string
    {
        $h = (string) $html;
        $h = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $h);
        $h = strip_tags($h, '<p><br><li>');
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = preg_replace('~\x{00A0}~u', ' ', $h);                 // NBSP → space
        $h = preg_replace('~\s+~u', ' ', $h);
        $h = preg_replace('~\s*(</p>|<br\s*/?>)\s*~i', "\n", $h);
        return trim($h);
    }

    private function dedupeAndSort(array $articles): array
    {
        $seen = [];
        $uniq = [];
        foreach ($articles as $a) {
            $num = (string)($a['number'] ?? '');
            $txt = (string)($a['text'] ?? '');
            $head = (string)($a['heading'] ?? "{$num}. člen");
            if ($num === '' || $txt === '') continue;
            $key = $num.'|'.substr(sha1($txt), 0, 12);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $uniq[] = ['number' => $num, 'heading' => $head, 'text' => $txt];
        }
        // Natural sort keeps 1, 2, 2a, 3, 10...
        usort($uniq, fn($a,$b)=> strnatcmp((string)$a['number'], (string)$b['number']));
        return $uniq;
    }
}
