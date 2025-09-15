<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\LawSummarizer;

class LawSummaryController extends Controller
{
    public function show(Request $r, LawSummarizer $sum, string $mopedId)
    {
        $focus   = $r->query('focus');   // neobvezno (npr. "odpoved")
        $refresh = (bool) $r->boolean('refresh', false);

        if ($refresh) {
            // enostavno: zbriši cache in rebuild
            DB::table('law_summaries')->where('law_id',$mopedId)
              ->when($focus, fn($q)=>$q->where('focus', mb_strtolower($focus,'UTF-8')),
                           fn($q)=>$q->whereNull('focus'))
              ->delete();
        }

        $out = $sum->getOrBuild($mopedId, $focus);
        return response()->json($out);
    }
}

