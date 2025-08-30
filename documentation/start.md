
super — tukaj dobiš **konkreten MVP s Postgres + pgvector**, brez Qdrant-a. Vključujem:

* SQL/migracije (vključno z `CREATE EXTENSION vector`)
* Laravel migracije + model
* Service za embeddings + k-NN iskanje
* Controller + ruta
* (opcijsko) hibrid BM25 + vektor
* Seed/ingest primer

---

# 0) Priprava Postgresa

V `docker-compose.yml` (če ga imaš) dodaj Postgres 15+:

```yaml
services:
  db:
    image: pgvector/pgvector:pg15   # ali pgvector/pgvector:pg16 za PG16
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
      POSTGRES_DB: appdb
    ports:
      - "5432:5432"
    volumes:
      - dbdata:/var/lib/postgresql/data
volumes:
  dbdata:
```

V `.env` (Laravel):

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=appdb
DB_USERNAME=app
DB_PASSWORD=app
```

---

# 1) Laravel migracije

## 1a) Razširitev pgvector

Ustvari migracijo `2025_01_01_000000_enable_pgvector_extension.php`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
    }
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector;');
    }
};
```

## 1b) Tabela `law_chunks` (chunki zakonov/sodb)

`2025_01_01_000100_create_law_chunks_table.php`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('law_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');                  // "PUBLIC" ali npr. "tenant_123"
            $table->string('source');                     // PISRS / EUR-LEX / SODNA
            $table->string('title');                      // npr. ZDR-1
            $table->string('citation');                   // npr. "člen 89"
            $table->text('url');                          // URL na vir
            $table->longText('text');                     // besedilo odseka
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->timestamps();
        });

        // raw kolona za vektor (1536 = dim OpenAI text-embedding-3-small)
        \DB::statement("ALTER TABLE law_chunks ADD COLUMN embedding vector(1536);");

        // Full-text index (BM25/hibrid – simple slovenski tokenizator je ok za MVP)
        \DB::statement("CREATE INDEX law_chunks_fts_idx ON law_chunks USING GIN (to_tsvector('simple', text));");

        // K-NN index (IVFFLAT) – ustvari ga ŠELE po prvem večjem uvozu
        // \DB::statement("CREATE INDEX law_chunks_vec_idx ON law_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);");
    }

    public function down(): void
    {
        Schema::dropIfExists('law_chunks');
    }
};
```

> Opomba: IVFFLAT indeks ustvari, ko imaš že nekaj tisoč vnosov (drugače ga lahko kasneje dodaš z Artisan migracijo).

---

# 2) Model

`app/Models/LawChunk.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LawChunk extends Model
{
    protected $table = 'law_chunks';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','tenant_id','source','title','citation','url','text','embedding_model'
    ];

    protected static function booted()
    {
        static::creating(function ($m) {
            if (!$m->id) $m->id = (string) Str::uuid();
        });
    }
}
```

---

# 3) Embeddings & RAG service

`app/Services/RagService.php`

```php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

    /** Vstavi chunk z embeddingom (PUBLIC ali tenant-specific) */
    public function upsertChunk(array $payload): void
    {
        // $payload: tenant_id, source, title, citation, url, text
        $vec = $this->embed($payload['text']);
        DB::table('law_chunks')->insert([
            'id' => $payload['id'] ?? \Str::uuid(),
            'tenant_id' => $payload['tenant_id'] ?? 'PUBLIC',
            'source' => $payload['source'],
            'title' => $payload['title'],
            'citation' => $payload['citation'],
            'url' => $payload['url'],
            'text' => $payload['text'],
            'embedding_model' => 'text-embedding-3-small',
            'embedding' => sprintf('[%s]', implode(',', $vec)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Čisti vektorski search (cosine) */
    public function vectorSearch(string $q, string $tenantId, int $k = 6): array
    {
        $qVec = $this->embed($q);
        $qSql = sprintf('[%s]', implode(',', $qVec));

        // tenant filter: PUBLIC ali njihov tenant
        $rows = DB::select("
            SELECT id, source, title, citation, url, text,
                   1 - (embedding <=> {$qSql}) AS score
            FROM law_chunks
            WHERE tenant_id IN ('PUBLIC', ?)
            ORDER BY embedding <=> {$qSql} ASC
            LIMIT {$k}
        ", [$tenantId]);

        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Hibrid (BM25 → topN → re-rank z vektorji) */
    public function hybridSearch(string $q, string $tenantId, int $k = 6, int $bm25N = 100): array
    {
        // 1) BM25 kandidati
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

        // 2) Vektorski re-rank med kandidati
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

        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Generiraj odgovor iz konteksta */
    public function answer(string $q, array $chunks): string
    {
        $context = collect($chunks)->map(fn($c) =>
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
```

---

# 4) Controller + ruta

`app/Http/Controllers/AskController.php`

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\RagService;

class AskController extends Controller
{
    public function __invoke(Request $r, RagService $rag)
    {
        $data = $r->validate(['q'=>'required|string', 'mode'=>'nullable|in:vector,hybrid']);
        $tenantId = (string) optional($r->user())->tenant_id ?: 'PUBLIC';

        // izberi način iskanja
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
```

`routes/api.php`

```php
use App\Http\Controllers\AskController;
Route::middleware('auth:sanctum')->post('/ask', AskController::class);
```

---

# 5) Seed / ingest (enostaven primer)

`app/Console/Commands/SeedLawChunks.php`

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RagService;

class SeedLawChunks extends Command
{
    protected $signature = 'law:seed';
    protected $description = 'Seed demo law chunks with embeddings';

    public function handle(RagService $rag)
    {
        $demo = [
            [
                'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZDR-1',
                'citation'=>'člen 89',
                'url'=>'https://www.pisrs.si/Pis.web/pregledPredpisa?id=ZAKO3850',
                'text'=>'Razlogi za redno odpoved pogodbe o zaposlitvi so ...'
            ],
            [
                'tenant_id'=>'PUBLIC','source'=>'OZ','title'=>'Obligacijski zakonik',
                'citation'=>'člen 299',
                'url'=>'https://www.pisrs.si/Pis.web/pregledPredpisa?id=ZAKO1264',
                'text'=>'Dolžnik, ki zamuja z izpolnitvijo denarne obveznosti, dolguje zamudne obresti ...'
            ],
        ];

        foreach ($demo as $d) {
            $rag->upsertChunk($d);
            $this->info("Inserted: {$d['title']} {$d['citation']}");
        }

        $this->info('Done.');
    }
}
```

Registriraj v `app/Console/Kernel.php`:

```php
protected $commands = [
    \App\Console\Commands\SeedLawChunks::class,
];
```

Zaženi:

```
php artisan migrate
php artisan law:seed
```

> Ko imaš pravi PISRS/EUR-Lex fetch, namesto ročnega `SeedLawChunks` implementiraš **Scheduler job** (npr. `php artisan schedule:work`), ki periodično pridobi akte, jih razreže na člene in pokliče `$rag->upsertChunk($payload)`.

---

# 6) Ustvari IVFFLAT indeks po prvem polnjenju

Ko imaš v tabeli nekaj tisoč vnosov:

```php
DB::statement("CREATE INDEX law_chunks_vec_idx ON law_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);");
```

*(lahko kot nova migracija)*

---

# 7) Frontend (hitro)

Na strani (Vue) kličeš `/api/ask` z `q` in (opcijsko) `mode=hybrid`:

```js
const res = await fetch('/api/ask', {
  method:'POST',
  headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},
  body: JSON.stringify({ q: 'Kateri člen ureja odpoved pogodbe o zaposlitvi?', mode: 'hybrid' })
})
const data = await res.json()
// data.answer, data.citations[]
```

---

# 8) Zakaj je to praktično

* En stack (Postgres), **brez dodatnih storitev**.
* Imaš **BM25** (klasično FTS) + **vektorje** → zelo solidna relevanca pri pravu.
* Ko zrasteš, lahko:

  * vklopiš **re-rank** s kakim čisto-k-nn reševalcem,
  * ali kasneje migriraš na namensko vektorsko bazo (Qdrant/Weaviate).

---

Če želiš, ti pripravim še:

* **Scheduler job** za redni uvoz PISRS/EUR-Lex,
* **upload PDF → chunking** (tenant dokumenti) primer,
* **Stripe** integracijo za SaaS (plani, kvote).
