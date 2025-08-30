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

        if (empty($chunks)) {
            return response()->json([
                'answer' => 'Za zanesljiv odgovor nimam dovolj konteksta. Poskusi navesti zakon ali člen.',
                'citations' => []
            ]);
        }

        $answer = $rag->answer($data['q'], $chunks);
        $cites = collect($chunks)->take(4)->map(fn($c)=>[
            'title'=>$c['title'], 'citation'=>$c['citation'], 'url'=>$c['url']
        ])->values();

        return response()->json(['answer'=>$answer, 'citations'=>$cites]);
    }
}
