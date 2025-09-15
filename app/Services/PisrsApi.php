<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class PisrsApi
{
    private string $base;
    private string $apiKey;

    // .env:
    // PISRS_BASE=https://pisrs.si
    // PISRS_API_KEY=XXXXXXXX
    public function __construct(?string $base = null, ?string $apiKey = null)
    {
        $this->base   = rtrim($base ?? env('PISRS_BASE', 'https://pisrs.si'), '/');
        $this->apiKey = (string) ($apiKey ?? env('PISRS_API_KEY', ''));
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept'    => '*/*',
            ])->timeout(45);
    }

    /** Zbirka (variacije, datoteke, NPB seznami) */
    public function getZbirka(string $mopedId): array
    {
        $url = "{$this->base}/api/rezultat/zbirka/id/{$mopedId}";
        $res = $this->client()->acceptJson()->get($url);
        // $this->logPreview('GET zbirka', $url, $res->status(), $res->header('Content-Type'), $res->body());
        $res->throw();
        return $res->json(); // downstream code should use data_get(..., 'data.*')
    }

    public function listAllNpbCandidates(array $zbirka): array
    {
        $out = [];

        // 1) besedilo.npbVerzije under data or root
        foreach ((array) (data_get($zbirka, 'data.besedilo.npbVerzije')
                       ?? data_get($zbirka, 'besedilo.npbVerzije')
                       ?? []) as $v) {
            $id = isset($v['id']) ? (int)$v['id'] : null;
            if ($id) $out[$id] = ['id' => $id, 'naziv' => $v['naziv'] ?? null];
        }

        // 2) datoteke[].npbVerzija under data or root
        foreach ((array) (data_get($zbirka, 'data.datoteke')
                       ?? data_get($zbirka, 'datoteke')
                       ?? []) as $grp) {
            $v = $grp['npbVerzija'] ?? null;
            if (is_array($v) && !empty($v['id'])) {
                $id = (int) $v['id'];
                $out[$id] = $out[$id] ?? ['id' => $id, 'naziv' => $v['naziv'] ?? null];
            }
        }

        return array_values($out);
    }

    /** Izberi zadnjega NPB: prednost ima največja številka v "NPB X"; fallback: najvišji ID. */
    public function pickLatestNpbId(array $zbirka): ?int
    {
        $cands = $this->listAllNpbCandidates($zbirka);
        if (empty($cands)) {
            logger()->warning('[PISRS] pickLatestNpbId: ni najdenih NPB kandidatov – poglej strukturo payload-a.');
            return null;
        }

        // Scoriraj kandidate: NPB 13 > NPB 12 > ... > Osnovni (0) > ostalo
        $scored = [];
        foreach ($cands as $c) {
            $name  = strtolower((string)($c['naziv'] ?? ''));
            $id    = (int) $c['id'];
            $score = -1;
            $npbNo = null;

            if (preg_match('~npb\s+(\d+)~i', (string)$c['naziv'], $m)) {
                $npbNo = (int) $m[1];
                $score = 1000 + $npbNo;   // glavno merilo
            } elseif (str_contains($name, 'osnovni')) {
                $score = 500;             // baseline
            } else {
                $score = 400;             // drugi nazivi
            }

            // kot tie-breaker uporabi večji ID
            $scored[] = ['id'=>$id, 'score'=>$score, 'idTie'=>$id, 'naziv'=>$c['naziv'] ?? null];
        }

        usort($scored, fn($a,$b) =>
            ($b['score'] <=> $a['score']) ?: ($b['idTie'] <=> $a['idTie'])
        );

        $chosen = $scored[0]['id'] ?? null;

        /*logger()->debug('[PISRS] pickLatestNpbId candidates', [
            'ranked' => array_slice($scored, 0, 5),
            'chosen' => $chosen,
        ]);*/

        return $chosen;
    }



    /** Primarno: extapi/besedilo/{npbId} → HTML */
    public function getNpbHtmlViaExtapi(int $npbId): ?string
    {
        $url = "{$this->base}/extapi/besedilo/{$npbId}";
        $res = $this->client()->withHeaders(['Accept' => 'text/html'])->get($url);
        $this->logPreview('GET besedilo (extapi)', $url, $res->status(), $res->header('Content-Type'), $res->body());

        if ($res->ok() && str_contains((string)$res->header('Content-Type'), 'text/html')) {
            return $res->body();
        }
        return null;
    }

    /** Fallback: details → poišči HTML link in vrni HTML body */
    public function getNpbHtmlViaDetails(int $npbId): ?string
    {
        $det = $this->getNpbDetails($npbId);
        $htmlUrl = $this->firstLinkByType($det, 'html');
        if (!$htmlUrl) return null;

        $res = $this->client()->get($htmlUrl);
        $this->logPreview('GET details HTML', $htmlUrl, $res->status(), $res->header('Content-Type'), $res->body());
        return $res->ok() ? $res->body() : null;
    }

    /** Fallback: details → poišči PDF link in vrni PDF kot bytes (string) */
    public function getNpbPdfViaDetails(int $npbId): ?string
    {
        $det = $this->getNpbDetails($npbId);
        $pdfUrl = $this->firstLinkByType($det, 'pdf');
        if (!$pdfUrl) return null;

        $res = $this->client()->get($pdfUrl);
        // $this->logPreview('GET details PDF', $pdfUrl, $res->status(), $res->header('Content-Type'), $res->body());
        return $res->ok() ? $res->body() : null;
    }

    /** details JSON za NPB */
    public function getNpbDetails(int $npbId): array
    {
        $url = "{$this->base}/api/rezultat/neuradno-precisceno-besedilo/{$npbId}/details";
        $res = $this->client()->acceptJson()->get($url);
        $this->logPreview('GET NPB details', $url, $res->status(), $res->header('Content-Type'), $res->body());
        $res->throw();
        return $res->json();
    }

    private function firstLinkByType(array $det, string $type): ?string
    {
        // allow both root and data.* layouts
        $links = data_get($det, 'data.links') ?? data_get($det, 'links') ?? [];
        foreach ((array)$links as $lnk) {
            $href = $lnk['href'] ?? $lnk['url'] ?? null;
            if (!$href) continue;
            $isHtml = str_contains($href, 'html') || preg_match('~\.html?$~i', $href);
            $isPdf  = str_contains($href, 'pdf')  || preg_match('~\.pdf$~i',  $href);
            if ($type === 'html' && $isHtml) return $this->abs($href);
            if ($type === 'pdf'  && $isPdf)  return $this->abs($href);
        }
        // some payloads expose direct fields too
        $cand = data_get($det, "data.$type") ?? data_get($det, $type);
        return $cand ? $this->abs((string)$cand) : null;
    }


    private function abs(string $href): string
    {
        if (preg_match('~^https?://~i', $href)) return $href;
        return $this->base . '/' . ltrim($href, '/');
    }

    private function logPreview(string $label, string $url, int $status, ?string $ctype, ?string $body): void
    {
        logger()->debug("[PISRS] {$label}", [
            'url'     => $url,
            'status'  => $status,
            'ctype'   => $ctype,
            'preview' => mb_substr($body ?? '', 0, 300),
        ]);
    }
}
