<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\RagService;

class AskController extends Controller
{
    public function __invoke(Request $r, RagService $rag)
    {
        $data = $r->validate(['q'=>'required|string', 'mode'=>'nullable|in:vector,hybrid']);
        $tenantId = (string) optional($r->user())->tenant_id ?: 'PUBLIC';

        $mode = $data['mode'] ?? 'hybrid';
        $chunks = $mode === 'vector'
            ? $rag->vectorSearch($data['q'], $tenantId, 6)
            : $rag->hybridSearch($data['q'], $tenantId, 6, 100);

        // Relevance threshold to avoid misleading citations
        $minScore = 0.5; // 0.75 doesn't show results
        $relevant = collect($chunks)->filter(fn($c) => ($c['score'] ?? 0) >= $minScore)->values()->all();

        if (empty($relevant)) {
            return response()->json([
                'answer' => 'Za zanesljiv odgovor nimam dovolj konteksta (ali virov). Poskusi navesti zakon ali člen, ali dodajmo več virov v bazo (npr. ZPIZ-2, ZZRZI, ZIMI).',
                'citations' => []
            ]);
        }

        $answer = $rag->answer($data['q'], $relevant);
        $cites = collect($relevant)->take(4)->map(fn($c)=>[
            'title'=>$c['title'], 'citation'=>$c['citation'], 'url'=>$c['url']
        ])->values();

        return response()->json(['answer'=>$answer, 'citations'=>$cites]);
    }
}
