<?php
// php artisan test --testsuite=Unit --filter=ArticleExtractorTest

namespace Tests\Unit\Services;

use App\Services\ArticleExtractor;
use Tests\TestCase;

class ArticleExtractorTest extends TestCase
{
    private function nw(string $s): string
    {
        // normalize whitespace for stable assertions
        $s = preg_replace('~\s+~u', ' ', trim($s));
        return (string) $s;
    }

    public function test_paragraph_structure_extracts_articles_with_subtitle_and_body()
    {
        $html = <<<HTML
        <body><div class="mainText">
            <p class="center bold uppercase naslov">ZAKON</p>
            <p class="center bold uppercase naslov">O DELOVNIH RAZMERJIH (ZDR-1)</p>

            <p class="center uppercase poglavje">I. SPLOŠNE DOLOČBE</p>

            <p class="center bold clen">1.&nbsp;člen</p>
            <p class="center bold clen">(namen zakona)</p>
            <p class="zamik textJustify odstavek">(1)&nbsp;Ta zakon ureja delovna razmerja...</p>
            <p class="zamik textJustify stevilcna_tocka">1.&nbsp;&nbsp; Direktivo Sveta 91/533/EGS ...</p>

            <p class="center bold clen">2.&nbsp;člen</p>
            <p class="center bold clen">(urejanje delovnih razmerij)</p>
            <p class="zamik textJustify odstavek">(1)&nbsp;Če ni s posebnim zakonom drugače določeno...</p>
        </div></body>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        // We expect two articles (1, 2)
        $this->assertCount(2, $arts);

        // Article 1
        $this->assertSame('1', $arts[0]['number']);
        $this->assertSame('1. člen — (namen zakona)', $this->nw($arts[0]['heading']));
        $this->assertStringStartsWith('1. člen — (namen zakona)', $arts[0]['text']);
        $this->assertStringContainsString('Ta zakon ureja delovna razmerja', $arts[0]['text']);
        $this->assertStringContainsString('Direktivo Sveta 91/533/EGS', $arts[0]['text']);

        // Article 2
        $this->assertSame('2', $arts[1]['number']);
        $this->assertSame('2. člen — (urejanje delovnih razmerij)', $this->nw($arts[1]['heading']));
        $this->assertStringStartsWith('2. člen — (urejanje delovnih razmerij)', $this->nw($arts[1]['text']));
        $this->assertStringContainsString('Če ni s posebnim zakonom drugače določeno', $arts[1]['text']);
    }

    public function test_stops_body_at_next_article_heading()
    {
        $html = <<<HTML
        <div class="mainText">
            <p class="center bold clen">10. člen</p>
            <p class="zamik odstavek">(1) Besedilo člena 10.</p>
            <p class="zamik odstavek">(2) Nadaljevanje člena 10.</p>

            <p class="center bold clen">11. člen</p>
            <p class="zamik odstavek">(1) Začetek člena 11.</p>
        </div>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(2, $arts);
        $this->assertSame('10', $arts[0]['number']);
        $this->assertStringNotContainsString('Začetek člena 11', $arts[0]['text'], 'Article 10 must stop before Article 11.');
    }

    public function test_anchors_fallback_extracts_when_no_p_clen_present()
    {
        $html = <<<HTML
        <div class="mainText">
            <a id="clen-5"></a>
            <p>(uvedba ...)</p>
            <p>Besedilo člena 5.</p>

            <a id="article-6a"></a>
            <p>(posebna določba)</p>
            <p>Besedilo člena 6a.</p>
        </div>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(2, $arts);
        $this->assertSame('5', $arts[0]['number']);
        $this->assertSame('5. člen', $arts[0]['heading']);
        $this->assertStringContainsString('Besedilo člena 5.', $arts[0]['text']);

        $this->assertSame('6a', $arts[1]['number']);
        $this->assertSame('6a. člen', $arts[1]['heading']);
        $this->assertStringContainsString('Besedilo člena 6a.', $arts[1]['text']);
    }

    public function test_block_structure_fallback_extracts_articles()
    {
        $html = <<<HTML
        <div class="clen">
            <div class="stevilka">20. člen</div>
            <div class="besedilo"><p>Vsebina člena 20.</p></div>
        </div>
        <section class="article">
            <header>21. člen</header>
            <div class="text"><p>Vsebina člena 21.</p></div>
        </section>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(2, $arts);
        $this->assertSame('20', $arts[0]['number']);
        $this->assertSame('20. člen', $arts[0]['heading']);
        $this->assertStringContainsString('Vsebina člena 20.', $arts[0]['text']);

        $this->assertSame('21', $arts[1]['number']);
        $this->assertSame('21. člen', $arts[1]['heading']);
        $this->assertStringContainsString('Vsebina člena 21.', $arts[1]['text']);
    }

    public function test_plain_text_fallback_from_text()
    {
        $text = "UVOD ... 89. člen Besedilo osemdeset devetega člena. 89.a člen Dopolnilo člena 89.a. 367a. člen Posebna določba.";

        $ext = new ArticleExtractor();
        $arts = $ext->fromText($text);

        $this->assertCount(3, $arts);
        $this->assertSame('89', $arts[0]['number']);
        $this->assertSame('89. člen', $arts[0]['heading']);
        $this->assertStringContainsString('Besedilo osemdeset devetega člena', $arts[0]['text']);

        $this->assertSame('89.a', $arts[1]['number']);
        $this->assertSame('89.a. člen', $arts[1]['heading']);

        $this->assertSame('367a', $arts[2]['number']);
        $this->assertSame('367a. člen', $arts[2]['heading']);
    }

    public function test_dedupe_and_sort_keeps_unique_and_sorts_naturally()
    {
        // Duplicate Article 2 content appears twice; should be deduped.
        $html = <<<HTML
        <div class="mainText">
            <p class="center bold clen">2. člen</p>
            <p class="zamik odstavek">(1) Prvi odstavek člena 2.</p>

            <p class="center bold clen">2. člen</p>
            <p class="zamik odstavek">(1) Prvi odstavek člena 2.</p>

            <p class="center bold clen">2a. člen</p>
            <p class="zamik odstavek">Vsebina 2a.</p>

            <p class="center bold clen">10. člen</p>
            <p class="zamik odstavek">Vsebina 10.</p>
        </div>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        // Should dedupe the two identical "2. člen" entries, resulting in 3 unique articles
        $this->assertCount(3, $arts);

        // Natural ordering: 2, 2a, 10
        $this->assertSame([
            '2', '2a', '10'
        ], array_map(fn($a) => $a['number'], $arts));
    }

    public function test_subtitle_is_optional_and_not_required()
    {
        $html = <<<HTML
        <div class="mainText">
            <p class="center bold clen">3. člen</p>
            <p class="zamik odstavek">Telo člena brez podnaslova.</p>
        </div>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(1, $arts);
        $this->assertSame('3', $arts[0]['number']);
        $this->assertSame('3. člen', $this->nw($arts[0]['heading']));
        $this->assertStringContainsString('Telo člena brez podnaslova', $arts[0]['text']);
    }

    public function test_subtitle_detection_does_not_consume_next_article_heading()
    {
        // Ensure that the next p.clen with a valid "X. člen" is not treated as subtitle
        $html = <<<HTML
        <div class="mainText">
            <p class="center bold clen">4. člen</p>
            <p class="zamik odstavek">Telo 4.</p>
            <p class="center bold clen">5. člen</p> <!-- must not be consumed as subtitle -->
            <p class="zamik odstavek">Telo 5.</p>
        </div>
        HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(2, $arts);
        $this->assertSame('4', $arts[0]['number']);
        $this->assertSame('4. člen', $arts[0]['heading']);

        $this->assertSame('5', $arts[1]['number']);
        $this->assertSame('5. člen', $arts[1]['heading']);
    }

    public function test_handles_letters_in_numbers_like_367a_and_89_a_variants()
    {
        $html = <<<HTML
    <div class="mainText">
        <p class="center bold clen">367a. člen</p>
        <p class="zamik odstavek">Telo 367a.</p>

        <p class="center bold clen">89.a člen</p>
        <p class="zamik odstavek">Telo 89.a.</p>
    </div>
    HTML;

        $ext = new ArticleExtractor();
        $arts = $ext->fromHtml($html);

        $this->assertCount(2, $arts);

        // Natural sort puts 89.a before 367a
        $this->assertSame([
            '89.a', '367a'
        ], array_map(fn($a) => $a['number'], $arts));

        // Headings can be "89.a člen" or "89.a. člen" depending on source;
        // accept an optional dot before "člen".
        $this->assertMatchesRegularExpression('~^89\.a\.?\s+člen$~u', $arts[0]['heading']);
        $this->assertMatchesRegularExpression('~^367a\.?\s+člen$~u', $arts[1]['heading']);

        // Sanity-check bodies
        $this->assertStringContainsString('Telo 89.a.', $arts[0]['text']);
        $this->assertStringContainsString('Telo 367a.', $arts[1]['text']);
    }


}

