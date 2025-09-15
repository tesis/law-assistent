<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RagService
{
    public function embed(string $text): array
    {
        $resp = Http::withToken(env('OPENAI_API_KEY'))->post(
            'https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => mb_substr($text, 0, 8000)
            ]
        )->json();

        return $resp['data'][0]['embedding'] ?? [];
    }

    public function upsertChunk(array $payload): void
    {
        $vec = $this->embed($payload['text']);
        DB::table('law_chunks')->insert([
            'id' => $payload['id'] ?? (string) Str::uuid(),
            'tenant_id' => $payload['tenant_id'] ?? 'PUBLIC',
            'source' => $payload['source'],
            'title' => $payload['title'],
            'citation' => $payload['citation'],
            'url' => $payload['url'],
            'law_id'    => $payload['law_id'],
            'text' => $payload['text'],
            'embedding_model' => 'text-embedding-3-small',
            'embedding' => sprintf('[%s]', implode(',', $vec)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function vectorSearch(string $q, string $tenantId, int $k = 6): array
    {
        $qVec = $this->embed($q);                            // [floats]
        $qVecStr = '['.implode(',', $qVec).']';              // "[..., ...]"

        $rows = \DB::select("
            SELECT id, source, title, citation, url, text, law_id,
                   1 - (embedding <=> ?::vector) AS score
            FROM law_chunks
            WHERE tenant_id IN ('PUBLIC', ?)
            ORDER BY embedding <=> ?::vector ASC
            LIMIT {$k}
        ", [$qVecStr, $tenantId, $qVecStr]);

        return array_map(fn($r)=>(array)$r, $rows);
    }

    public function hybridSearch(string $q, string $tenantId, int $k = 6, int $bm25N = 100): array
    {
        // 1) BM25 kandidati
        $bm25 = \DB::select("
          SELECT id, ts_rank(to_tsvector('simple', text), plainto_tsquery('simple', ?)) AS r
          FROM law_chunks
          WHERE tenant_id IN ('PUBLIC', ?)
            AND to_tsvector('simple', text) @@ plainto_tsquery('simple', ?)
          ORDER BY r DESC
          LIMIT {$bm25N}
        ", [$q, $tenantId, $q]);

        if (!$bm25) return $this->vectorSearch($q, $tenantId, $k);

        $ids = implode("','", array_map(fn($x)=>$x->id, $bm25));

        // 2) vektorski re-rank med kandidati
        $qVec = $this->embed($q);
        $qVecStr = '['.implode(',', $qVec).']';

        $rows = \DB::select("
          SELECT id, source, title, citation, url, text, law_id,
                 1 - (embedding <=> ?::vector) AS score
          FROM law_chunks
          WHERE id IN ('{$ids}')
          ORDER BY embedding <=> ?::vector ASC
          LIMIT {$k}
        ", [$qVecStr, $qVecStr]);

        return array_map(fn($r)=>(array)$r, $rows);
    }

    public function answer(string $q, array $chunks): string
    {
        // 1) Normaliziraj in filtriraj prazne kose
        $items = collect($chunks)->map(function ($c) {
            return [
                'source'   => $c['source']   ?? 'LOCAL',
                'title'    => (string)($c['title'] ?? ''),
                'citation' => (string)($c['citation'] ?? ''),
                'url'      => (string)($c['url'] ?? ''),
                'text'     => (string)($c['text'] ?? ''),
                'score'    => is_numeric($c['score'] ?? null) ? (float)$c['score'] : null,
            ];
        })->filter(fn ($c) => $c['text'] !== '')->values();

        if ($items->isEmpty()) {
            return 'Za zanesljiv odgovor nimam dovolj konteksta (ni virov).';
        }

        // 2) Uredi po score DESC (če ga imamo), sicer pusti vrstni red
        $items = $items->sortByDesc(function ($c) {
            return $c['score'] ?? -INF;
        })->values();

        // 3) Zgradi kontekst z omejitvijo znakov (token budget)
        $CHAR_CAP = (int) env('ASK_CONTEXT_CHAR_CAP', 45000);
        $contextParts = [];
        $size = 0;

        foreach ($items as $c) {
            // trimming kontrolnih znakov v url/title
            $title = $this->cleanInline($c['title']);
            $citation = $this->cleanInline($c['citation']);
            $url = $this->cleanInline($c['url']);

            $block = "[{$c['source']}] {$title} | {$citation} | {$url}\n{$c['text']}";
            $blen  = mb_strlen($block, 'UTF-8');

            if ($size + $blen > $CHAR_CAP) {
                // če je prostor še ok (~1k), odreži body in vseeno dodaj
                $remain = $CHAR_CAP - $size - mb_strlen("[{$c['source']}] {$title} | {$citation} | {$url}\n", 'UTF-8');
                if ($remain > 1000) {
                    $short = mb_substr($c['text'], 0, $remain, 'UTF-8');
                    $contextParts[] = "[{$c['source']}] {$title} | {$citation} | {$url}\n{$short}";
                }
                break;
            }
            $contextParts[] = $block;
            $size += $blen;
        }

        $context = implode("\n---\n", $contextParts);

        // 4) Navodila modelu (stroga + citiranje)
        $system = "Odgovarjaj IZKLJUČNO na podlagi spodnjih virov. "
                . "V vsakem odgovoru NAVEDI konkretne sklice v oklepajih (npr. 89. člen) in priloži povezavo (URL) iz vira. "
                . "Če v virih ni dovolj informacij, to jasno povej. Ne izmišljaj si številk ali vsebine.";

        $user = "VIRI:\n{$context}\n\n"
              . "VPRAŠANJE: {$q}\n\n"
              . "NAVODILA ZA CITIRANJE:\n"
              . "- Uporabi številke členov in priloži URL iz ustreznega vira.\n"
              . "- Če se odgovor nanaša na več členov, navedi ključne (npr. 89.–92. člen) in dodaj vsaj en URL.\n";

        $payload = [
            'model'       => env('ASK_MODEL', 'gpt-4o-mini'),
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => (float) env('ASK_TEMPERATURE', 0.1),
            'max_tokens'  => (int) env('ASK_MAX_TOKENS', 700),
        ];

        try {
            $resp = Http::timeout((int) env('ASK_HTTP_TIMEOUT', 30))
                ->withToken(env('OPENAI_API_KEY'))
                ->post('https://api.openai.com/v1/chat/completions', $payload)
                ->throw()
                ->json();

            return data_get($resp, 'choices.0.message.content', 'Ne morem zanesljivo odgovoriti.');
        } catch (\Throwable $e) {
            Log::error('RagService.answer LLM error', [
                'msg' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            // Fallback: vrni najbližje citate, da uporabnik vsaj vidi vire
            $fallback = $items->take(3)->map(function ($c) {
                $cit = $c['citation'] ?: '(brez člena)';
                $tit = $c['title'] ?: '';
                $url = $c['url'] ?: '';
                return "{$cit} — {$tit} {$url}";
            })->implode("\n");

            return "Ne morem zanesljivo odgovoriti (napaka pri generiranju). Najbližji viri:\n{$fallback}";
        }
    }

    private function cleanInline(string $s): string
    {
        // Odstrani kontrolne znake / prelome iz inline delov (naslov, url, citation)
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        // zbij dvojne presledke
        return trim(preg_replace('~\s+~u', ' ', $s));
    }

}
