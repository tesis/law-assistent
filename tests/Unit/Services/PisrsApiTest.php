<?php


namespace Tests\Unit\Services;

use App\Services\PisrsApi;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PisrsApiTest extends TestCase
{
    private string $base = 'https://example.test';
    private string $apiKey = 'TESTKEY';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_getZbirka_returns_json_and_sends_headers()
    {
        $mopedId = '123-ABC';
        $url = "{$this->base}/api/rezultat/zbirka/id/{$mopedId}";

        Http::fake([
            $url => Http::response(['data' => ['ok' => true]], 200, ['Content-Type' => 'application/json']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $out = $api->getZbirka($mopedId);

        $this->assertSame(['data' => ['ok' => true]], $out);

        Http::assertSent(function ($req) use ($url) {
            return $req->url() === $url
                && $req->hasHeader('X-API-Key', 'TESTKEY')
                && $req->hasHeader('Accept', 'application/json');
        });
    }

    public function test_getZbirka_throws_on_non_2xx()
    {
        $mopedId = 'nope';
        $url = "{$this->base}/api/rezultat/zbirka/id/{$mopedId}";

        Http::fake([
            $url => Http::response('Not found', 404),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);

        $this->expectException(RequestException::class);
        $api->getZbirka($mopedId);
    }

    public function test_listAllNpbCandidates_merges_sources_and_dedupes()
    {
        $api = new PisrsApi($this->base, $this->apiKey);

        $zbirka = [
            'data' => [
                'besedilo' => [
                    'npbVerzije' => [
                        ['id' => 10, 'naziv' => 'NPB 2'],
                        ['id' => 11, 'naziv' => 'Osnovni'],
                    ],
                ],
                'datoteke' => [
                    ['npbVerzija' => ['id' => 11, 'naziv' => 'Osnovni']],
                    // duplicate, keep unique
                    ['npbVerzija' => ['id' => 12, 'naziv' => 'NPB 1']],
                ],
            ],
        ];

        $out = $api->listAllNpbCandidates($zbirka);

        usort($out, fn($a, $b) => $a['id'] <=> $b['id']);

        $this->assertSame([
            ['id' => 10, 'naziv' => 'NPB 2'],
            ['id' => 11, 'naziv' => 'Osnovni'],
            ['id' => 12, 'naziv' => 'NPB 1'],
        ], $out);
    }

    public function test_pickLatestNpbId_prefers_highest_npb_number_then_id_tie_break()
    {
        $api = new PisrsApi($this->base, $this->apiKey);

        $zbirka = [
            'besedilo' => [
                'npbVerzije' => [
                    ['id' => 5, 'naziv' => 'NPB 3'],
                    ['id' => 99, 'naziv' => 'NPB 2'],
                    ['id' => 1000, 'naziv' => 'Osnovni'],
                ],
            ],
        ];

        $chosen = $api->pickLatestNpbId($zbirka);
        $this->assertSame(5, $chosen);

        $zbirka2 = [
            'besedilo' => [
                'npbVerzije' => [
                    ['id' => 1, 'naziv' => 'Osnovni'],
                    ['id' => 2, 'naziv' => 'Osnovni'],
                ],
            ],
        ];

        $chosen2 = $api->pickLatestNpbId($zbirka2);
        $this->assertSame(2, $chosen2);
    }

    public function test_getNpbHtmlViaExtapi_returns_html_body_when_ok_and_html()
    {
        $npbId = 42;
        $url = "{$this->base}/extapi/besedilo/{$npbId}";

        Http::fake([
            $url => Http::response('<html>OK</html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $html = $api->getNpbHtmlViaExtapi($npbId);

        $this->assertSame('<html>OK</html>', $html);

        Http::assertSent(function ($req) use ($url) {
            return $req->url() === $url
                && $req->hasHeader('X-API-Key', 'TESTKEY')
                && $req->hasHeader('Accept', 'text/html');
        });
    }

    public function test_getNpbHtmlViaExtapi_returns_null_when_not_html_or_not_ok()
    {
        $npbId = 43;
        $url = "{$this->base}/extapi/besedilo/{$npbId}";

        Http::fake([
            $url => Http::response('{"json":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $this->assertNull($api->getNpbHtmlViaExtapi($npbId));

        Http::fake([
            $url => Http::response('Server error', 500, ['Content-Type' => 'text/html']),
        ]);

        $this->assertNull($api->getNpbHtmlViaExtapi($npbId));
    }

    public function test_getNpbDetails_fetches_json_and_throws_on_error()
    {
        $npbId = 55;
        $url = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";

        Http::fake([
            $url => Http::response(['data' => ['links' => []]], 200, ['Content-Type' => 'application/json']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $det = $api->getNpbDetails($npbId);

        $this->assertSame(['data' => ['links' => []]], $det);

        Http::assertSent(function ($req) use ($url) {
            return $req->url() === $url
                && $req->hasHeader('Accept', 'application/json')
                && $req->hasHeader('X-API-Key', 'TESTKEY');
        });

        Http::fake([
            $url => Http::response('Nope', 404),
        ]);

        $this->expectException(RequestException::class);
        $api->getNpbDetails($npbId);
    }

    public function test_getNpbHtmlViaDetails_uses_details_link_and_returns_body()
    {
        $npbId = 77;
        $detailsUrl = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";
        $htmlPath = '/files/npb-77.html';
        $htmlUrlAbs = "{$this->base}{$htmlPath}";

        Http::fake([
            $detailsUrl => Http::response([
                'data' => [
                    'links' => [
                        ['href' => $htmlPath],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json']),
            $htmlUrlAbs => Http::response('<html>DETAIL</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $html = $api->getNpbHtmlViaDetails($npbId);

        $this->assertSame('<html>DETAIL</html>', $html);
    }

    public function test_getNpbHtmlViaDetails_returns_null_when_no_html_link()
    {
        $npbId = 78;
        $detailsUrl = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";

        Http::fake([
            $detailsUrl => Http::response([
                'data' => [
                    'links' => [
                        ['href' => '/files/npb-78.pdf'],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $this->assertNull($api->getNpbHtmlViaDetails($npbId));
    }

    public function test_getNpbPdfViaDetails_uses_details_pdf_link_and_returns_bytes()
    {
        $npbId = 79;
        $detailsUrl = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";
        $pdfUrlAbs = "{$this->base}/files/npb-79.pdf";

        Http::fake([
            $detailsUrl => Http::response([
                'data' => [
                    'links' => [
                        ['url' => '/files/npb-79.pdf'],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json']),
            $pdfUrlAbs => Http::response('%PDF-1.7 ...', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $pdf = $api->getNpbPdfViaDetails($npbId);

        $this->assertSame('%PDF-1.7 ...', $pdf);
    }

    public function test_getNpbHtmlViaDetails_uses_direct_html_field_when_present()
    {
        $npbId = 80;
        $detailsUrl = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";
        $htmlUrlAbs = "{$this->base}/direct/npb-80.html";

        Http::fake([
            $detailsUrl => Http::response([
                'html' => '/direct/npb-80.html',
            ], 200, ['Content-Type' => 'application/json']),
            $htmlUrlAbs => Http::response('<html>DIRECT</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $html = $api->getNpbHtmlViaDetails($npbId);

        $this->assertSame('<html>DIRECT</html>', $html);
    }

    public function test_getNpbPdfViaDetails_returns_null_when_no_pdf_link()
    {
        $npbId = 81;
        $detailsUrl = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";

        Http::fake([
            $detailsUrl => Http::response(['data' => ['links' => []]], 200, ['Content-Type' => 'application/json']),
        ]);

        $api = new PisrsApi($this->base, $this->apiKey);
        $this->assertNull($api->getNpbPdfViaDetails($npbId));
    }
}

