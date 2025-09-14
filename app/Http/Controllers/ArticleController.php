<?php


namespace App\Http\Controllers;

use App\Support\TextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArticleController extends Controller
{
    /** JSON: /api/laws/{mopedId}/articles?q=odpoved */
    public function index(Request $request, string $mopedId)
    {
        $hasLawId = Schema::hasColumn('law_chunks', 'law_id');

        $q = trim((string) $request->query('q', ''));

        // Order členi numerically by the number at the start of citation (e.g. "89. člen — …")
        $orderSql = "regexp_replace(citation, '^(\\d{1,4})(?:\\.[a-z])?.*$', '\\1')::int ASC";

        $rows = DB::table('law_chunks')
            ->select('id', 'citation', 'title', 'text', 'url');
        if ($hasLawId) {
            $rows = $rows->where('law_id', $mopedId);
        } else {
           // fallback: match the act by URL query param
            $rows->where('url', 'LIKE', "%id={$mopedId}%");
        }
        $rows = $rows->when($q !== '', function ($qb) use ($q) {
                $like = '%'.$q.'%';
                $qb->where(function ($q2) use ($like) {
                    // Search across citation + title + text for easy recall
                    $q2->where('citation', 'ILIKE', $like)
                        ->orWhere('title', 'ILIKE', $like)
                        ->orWhere('text', 'ILIKE', $like);
                });
            })
            ->orderByRaw($orderSql)
            ->limit(1000) // safeguard; adjust as needed
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'citation' => $r->citation,
                    // e.g. "89. člen — Razlogi za redno odpoved …"
                    'title' => $r->title,
                    // e.g. "ZDR-1 — 89. člen — …"
                    'url' => $r->url,
                    // PISRS page for the act
                    'text_plain' => TextSanitizer::toPlain($r->text),
                    'text_html' => $r->text,
                    'text' => TextSanitizer::toSafeHtml($r->text ?? '')
                    // heading + body (from our ingest)
                ];
            })
            ->values();

        return response()->json([
            'law_id' => $mopedId,
            'count' => $rows->count(),
            'items' => $rows,
        ]);
    }

    /** Web page: /laws/{mopedId}?q=odpoved */
    public function page(Request $request, string $mopedId)
    {
        $q = trim((string) $request->query('q', ''));

        $orderSql = "regexp_replace(citation, '^(\\d{1,4})(?:\\.[a-z])?.*$', '\\1')::int ASC";

        $rows = DB::table('law_chunks')
            ->select('id', 'citation', 'title', 'text', 'url')
            ->where('law_id', $mopedId)
            ->when($q !== '', function ($qb) use ($q) {
                $like = '%'.$q.'%';
                $qb->where(function ($q2) use ($like) {
                    $q2->where('citation', 'ILIKE', $like)
                        ->orWhere('title', 'ILIKE', $like)
                        ->orWhere('text', 'ILIKE', $like);
                });
            })
            ->orderByRaw($orderSql)
            ->limit(500)
            ->get();

        // Derive act title (first row’s title prefix before " — <člen>")
        $actTitle = $rows->first()?->title ?? $mopedId;
        $actTitle = preg_replace('/\s+—\s+\d{1,4}.*$/u', '', $actTitle) ?: $actTitle;

        $rows = $rows->map(function ($r) {
            $r->text_html = TextSanitizer::toSafeHtml($r->text ?? '');
                return $r;
        });
        return view('law.articles', [
            'mopedId' => $mopedId,
            'q' => $q,
            'actTitle' => $actTitle,
            'rows' => $rows,
        ]);
    }
}

