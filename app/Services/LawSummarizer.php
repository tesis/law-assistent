<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class LawSummarizer
{
    public function __construct(private RagService $rag) {}

    public function getOrBuild(string $lawId, ?string $focus=null, int $charCap=60000): array
    {
        $focusKey = $focus ? mb_strtolower(trim($focus), 'UTF-8') : null;

        // fingerprint: count + max(updated_at) za invalidacijo
        $agg = DB::table('law_chunks')
            ->selectRaw('COUNT(*) as c, MAX(updated_at) as m')
            ->where('law_id', $lawId)->first();
        $finger = sprintf('%s:%s', $agg->c ?? 0, (string)($agg->m ?? '0'));

        // poskusi prebrati cache
        $row = DB::table('law_summaries')->where('law_id',$lawId)
              ->when($focusKey, fn($q)=>$q->where('focus',$focusKey), fn($q)=>$q->whereNull('focus'))
              ->first();

        if ($row && $row->fingerprint === $finger) {
            return [
                'law_id'   => $lawId,
                'focus'    => $focusKey,
                'summary'  => (string)$row->summary,
                'cached'   => true,
                'updated_at' => $row->updated_at,
            ];
        }

        // 1) poskusi z mehkim fokusom
        $chunks = $this->buildContext($lawId, $charCap, $focusKey);
        // 2) če ni nič, poskusi brez fokusa
        if (empty($chunks) && $focusKey) {
            $chunks = $this->buildContext($lawId, $charCap, null);
        }

        if (!$chunks) {
            $summary = "Za izbrani kriterij ni bilo dovolj konteksta za povzetek.";
        } else {
            $prompt  = $this->buildPrompt($lawId, $focusKey);
            $summary = $this->rag->answer($prompt, $chunks);
        }

        DB::table('law_summaries')->updateOrInsert(
            ['law_id'=>$lawId, 'focus'=>$focusKey],
            ['summary'=>$summary, 'fingerprint'=>$finger, 'updated_at'=>now(), 'created_at'=>now()]
        );

        return [
            'law_id'   => $lawId,
            'focus'    => $focusKey,
            'summary'  => $summary,
            'cached'   => false,
            'updated_at' => now(),
        ];
    }

    /** Zgradi kontekst (urejeno po številkah členov). Če je podan focus, uporabi mehke tokene (OR). */
    private function buildContext(string $lawId, int $charCap, ?string $focusKey): array
    {
        $orderSql = "regexp_replace(citation, '^(\\d{1,4})(?:\\.[a-z])?.*$', '\\1')::int ASC";
        $q = DB::table('law_chunks')
            ->select('citation','text','title','url')
            ->where('law_id', $lawId)
            ->orderByRaw($orderSql);

        if ($focusKey) {
            $tokens = $this->focusTokens($focusKey);
            if (!empty($tokens)) {
                $q->where(function($qb) use ($tokens) {
                    foreach ($tokens as $t) {
                        $like = '%'.$t.'%';
                        $qb->orWhere('citation','ILIKE',$like)
                           ->orWhere('title','ILIKE',$like)
                           ->orWhere('text','ILIKE',$like);
                    }
                });
            } else {
                // če iz fokusa ni tokenov, raje ne filtriraj
            }
        }

        $chunks = [];
        $size = 0;
        foreach ($q->cursor() as $r) {
            $piece = "### {$r->citation}\n{$r->text}\n";
            $len = mb_strlen($piece, 'UTF-8');
            if ($size + $len > $charCap) break;
            $chunks[] = [
                'source'   => 'PISRS',
                'citation' => $r->citation,
                'title'    => $r->title,
                'text'     => $r->text,
                'url'      => $r->url ?: "https://www.pisrs.si/Pis.web/pregledPredpisa?id={$lawId}",
                'score'    => 1.0,
            ];
            $size += $len;
        }
        return $chunks;
    }

    /** Iz fokusa naredi mehke ključne besede (brez veznikov/pik), >=4 znaki, največ 6 tokenov. */
    private function focusTokens(string $focus): array
    {
        $s = mb_strtolower($focus, 'UTF-8');
        $s = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $s); // odstrani ločila
        $parts = preg_split('~\s+~u', trim($s), -1, PREG_SPLIT_NO_EMPTY);

        // slovenski stopwords (mini)
        $stop = ['kateri','kaj','kako','ali','je','so','se','za','o','v','na','po','od','do','in','ter','pri','z','če','ko','da','gre','glede','koga','katere','katerega','katera','kateremi','katerem','katerih','katerimi','pogodbe','o','zaposlitvi'];
        $out = [];
        foreach ($parts as $w) {
            if (mb_strlen($w,'UTF-8') < 4) continue;
            if (in_array($w, $stop, true)) continue;
            $out[] = $w;
        }
        // tipične pravne besede naj ostanejo
        $out = array_values(array_unique($out));
        return array_slice($out, 0, 6);
    }

    private function buildPrompt(string $lawId, ?string $focus): string
    {
        $focusLine = $focus ? "Fokus: {$focus}\n" : "";
        return trim("
Pripravi jedrnat, strukturiran povzetek zakona {$lawId} v slovenščini.

- Razdeli v tematske razdelke (npr. Področje uporabe; Ključne definicije; Pravice in obveznosti; Postopki; Odpoved pogodbe o zaposlitvi; Sankcije/Nadzor; Prehodne in končne določbe).
- Kjer je smiselno, navedi številke členov v oklepajih, npr. (89. člen).
- Nevtralno, brez pravnega nasveta. Dolžina: 6–12 alinej.
{$focusLine}
Uporabi izključno priložene odlomke kot vir informacij.
");
    }
}
