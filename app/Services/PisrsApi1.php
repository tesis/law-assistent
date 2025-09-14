<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class PisrsApi1
{
    private string $base;
    private string $apiKey;

    private array $endpoints;

    // put these in .env:
    // PISRS_BASE=https://pisrs.si
    // PISRS_API_KEY=xxxxx
    public function __construct(?string $base = null, ?string $apiKey = null)
    {
        $this->base   = rtrim($base ?? env('PISRS_BASE', 'https://pisrs.si'), '/');
        $this->apiKey = ($apiKey ?? env('PISRS_API_KEY', ''));
        $this->endpoints = [
            'list' => '/extapi/predpis/register-predpisov/seznam',
            'listItem' => '/extapi/predpis/register-predpisov/',
            'pregled' => '/pregledPredpisa/',
            'cleni' => '/api/rezultat/zbirka/id/'
        ];
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                // match curl behavior closely
                'Accept' => '*/*',
            ])
            ->acceptJson()
            ->timeout(30);
    }

    /** GET /api/rezultat/czbirka/id/{mopedId}/cleni */
    public function getArticles(string $mopedId): array
    {
        $url = "{$this->base}/api/rezultat/zbirka/id/{$mopedId}";
        logger()->debug('PISRS getArticles URL', ['url' => $url, 'base' => $this->base]);

        $res = $this->client()->get($url);

        // Helpful diagnostics
        logger()->debug('PISRS getArticles resp', [
            'status'  => $res->status(),
            'ctype'   => $res->header('Content-Type'),
            'preview' => mb_substr($res->body() ?? '', 0, 300),
        ]);

        if (in_array($res->status(), [401,403,498], true)) {
            throw new \RuntimeException("PISRS: invalid or missing X-API-Key (status {$res->status()}).");
        }

        if ($res->status() === 404) {
            // wrong mopedId or not available
            return [];
        }

        $res->throw();

        // Some servers send application/javascript; still JSON body.
        $json = json_decode($res->body(), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // fallback if it truly isn’t JSON
        throw new \RuntimeException('PISRS: response was not JSON; got: ' . ($res->header('Content-Type') ?? 'unknown'));
    }
}
