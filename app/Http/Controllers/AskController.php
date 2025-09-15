<?php
namespace App\Http\Controllers;

use App\Support\TextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\RagService;

class AskController extends Controller
{
    public function __invoke(Request $r, RagService $rag)
    {
        $data = $r->validate([
            'q'    => 'required|string',
            'mode' => 'nullable|in:vector,hybrid',
        ]);

        $tenantId = (string) optional($r->user())->tenant_id ?: 'PUBLIC';
        $mode     = $data['mode'] ?? 'hybrid';

        $chunks = $mode === 'vector'
            ? $rag->vectorSearch($data['q'], $tenantId, 6)
            : $rag->hybridSearch($data['q'], $tenantId, 6, 100);

        // Filter by relevance
        $minScore = 0.5;
        $relevant = collect($chunks)
            ->filter(fn ($c) => ($c['score'] ?? 0) >= $minScore)
            ->values()
            ->all();

        if (empty($relevant)) {
            return response()->json([
                'answer'    => 'Za zanesljiv odgovor nimam dovolj konteksta (ali virov). Poskusi navesti zakon ali člen, ali dodajmo več virov v bazo (npr. ZPIZ-2, ZZRZI, ZIMI).',
                'citations' => [],
            ]);
        }

        // Ensure each chunk has text & law_id (hydrate from DB if missing)
        $relevant = $this->hydrateChunkTextAndLaw($relevant);

        // Build LLM answer on full chunks (with text)
        $answer = $rag->answer($data['q'], $relevant);

        // Return only the top 4, but now with TEXT so the UI can show the
        // accordion (take(4)->)
        $cites = collect($relevant)->map(function ($c) {
            return [
                'title'    => $c['title']    ?? '',
                'citation' => $c['citation'] ?? '',
                'url'      => $c['url']      ?? '',
                'law_id'   => $c['law_id']   ?? $this->extractLawId($c['url'] ?? null),
                'text' => TextSanitizer::toPlain($c['text'] ?? '')
            ];
        })->values();

        return response()->json([
            'answer'    => $answer,
            'citations' => $cites,
        ]);
    }

    /** Fill in missing `text` (and `law_id`) for search results. */
    private function hydrateChunkTextAndLaw(array $chunks): array
    {
        $col = collect($chunks);

        // Batch 1: hydrate by id
        $idsNeeding = $col->filter(fn($c) => empty($c['text']) && !empty($c['id']))
                          ->pluck('id')->unique()->values();
        $byId = $idsNeeding->isNotEmpty()
            ? DB::table('law_chunks')->select('id','text','law_id')->whereIn('id', $idsNeeding)->get()
                ->keyBy('id')
            : collect();

        // Prepare helper for (law_id, citation) batch lookup
        $pairs = $col->filter(function ($c) {
                    return empty($c['text']) && !empty($c['citation']) && (
                        !empty($c['law_id']) || $this->extractLawId($c['url'] ?? null)
                    );
                })
                ->map(function ($c) {
                    $law = $c['law_id'] ?? $this->extractLawId($c['url'] ?? null);
                    return ['law_id' => $law, 'citation' => $c['citation']];
                })
                ->unique(function ($p) { return $p['law_id'].'|'.$p['citation']; })
                ->values();

        $byPair = collect();
        if ($pairs->isNotEmpty()) {
            // Build OR query for pairs
            $q = DB::table('law_chunks')->select('law_id','citation','text');
            $pairs->each(function ($p, $i) use ($q) {
                $q->orWhere(function ($qq) use ($p) {
                    $qq->where('law_id', $p['law_id'])->where('citation', $p['citation']);
                });
            });
            $byPair = $q->get()->keyBy(fn ($r) => $r->law_id.'|'.$r->citation);
        }

        return $col->map(function ($c) use ($byId, $byPair) {
            // by id
            if (empty($c['text']) && !empty($c['id']) && isset($byId[$c['id']])) {
                $c['text']   = (string) $byId[$c['id']]->text;
                $c['law_id'] = $c['law_id'] ?? (string) $byId[$c['id']]->law_id;
            }

            // by (law_id,citation)
            if (empty($c['text'])) {
                $law = $c['law_id'] ?? $this->extractLawId($c['url'] ?? null);
                if ($law) {
                    $key = $law.'|'.($c['citation'] ?? '');
                    if (isset($byPair[$key])) {
                        $c['text']   = (string) $byPair[$key]->text;
                        $c['law_id'] = $law;
                    }
                }
            }

            // final fallback
            $c['text']   = (string) ($c['text']   ?? '');
            $c['law_id'] = (string) ($c['law_id'] ?? '');

            return $c;
        })->all();
    }

    private function extractLawId(?string $url): ?string
    {
        if (!$url) return null;
        $parts = parse_url($url);
        if (empty($parts['query'])) return null;
        parse_str($parts['query'], $q);
        $id = $q['id'] ?? $q['mopedId'] ?? null;
        return (is_string($id) && preg_match('/^[A-Z]{4}\d{4,}$/', $id)) ? $id : null;
    }
}
