<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            'text' => $payload['text'],
            'embedding_model' => 'text-embedding-3-small',
            'embedding' => sprintf('[%s]', implode(',', $vec)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Čisti vektorski search
    public function vectorSearch(string $q, string $tenantId, int $k = 6): array
    {
        $qVec = $this->embed($q);
        $qSql = sprintf('[%s]', implode(',', $qVec));

        $rows = DB::select("
          SELECT id, source, title, citation, url, text,
                 1 - (embedding <=> {$qSql}) AS score
          FROM law_chunks
          WHERE tenant_id IN ('PUBLIC', ?)
          ORDER BY embedding <=> {$qSql} ASC
          LIMIT {$k}
        ", [$tenantId]);

        return array_map(fn($r)=>(array)$r, $rows);
    }

    // Hibrid: najprej BM25, nato vektor re-rank
    public function hybridSearch(string $q, string $tenantId, int $k = 6, int $bm25N = 100): array
    {
        $bm25 = DB::select("
          SELECT id, ts_rank(to_tsvector('simple', text), plainto_tsquery('simple', ?)) AS r
          FROM law_chunks
          WHERE tenant_id IN ('PUBLIC', ?)
            AND to_tsvector('simple', text) @@ plainto_tsquery('simple', ?)
          ORDER BY r DESC
          LIMIT {$bm25N}
        ", [$q, $tenantId, $q]);

        if (!$bm25) return $this->vectorSearch($q, $tenantId, $k);

        $ids = implode("','", array_map(fn($x)=>$x->id, $bm25));

        $qVec = $this->embed($q);
        $qSql = sprintf('[%s]', implode(',', $qVec));

        $rows = DB::select("
          SELECT id, source, title, citation, url, text,
                 1 - (embedding <=> {$qSql}) AS score
          FROM law_chunks
          WHERE id IN ('{$ids}')
          ORDER BY embedding <=> {$qSql} ASC
          LIMIT {$k}
        ");

        return array_map(fn($r)=>(array)$r, $rows);
    }

    public function answer(string $q, array $chunks): string
    {
        $context = collect($chunks)->map(fn($c)=>
            "[{$c['source']}] {$c['title']} | {$c['citation']} | {$c['url']}\n{$c['text']}"
        )->join("\n---\n");

        $messages = [
          ['role'=>'system','content'=>"Odgovarjaj IZKLJUČNO na podlagi spodnjih virov. NAVAJAJ člene/CELEX/opr. št. in URL. Če ni dovolj besedila, to jasno povej."],
          ['role'=>'user','content'=>"VIRI:\n{$context}\n\nVPRAŠANJE: {$q}"]
        ];

        $resp = Http::withToken(env('OPENAI_API_KEY'))->post(
            'https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'temperature' => 0.2
            ]
        )->json();

        return $resp['choices'][0]['message']['content'] ?? 'Ne morem zanesljivo odgovoriti.';
    }
}
