Seveda! Spodaj je **čisto praktičen “step-by-step”** za tvoj že obstoječi **Laravel 12** projekt, da v 1–2 urah postaviš **MVP AI pravnega asistenta** na **PostgreSQL + pgvector** (brez Qdrant-a).

---

# 0) Priprava okolja

1. **Namesti/zaženi PostgreSQL 15+** (lokalno ali v Dockerju).
   Če uporabljaš Docker:

   ```yaml
   # docker-compose.yml (izsek)
   services:
     db:
       image: postgres:15
       environment:
         POSTGRES_USER: app
         POSTGRES_PASSWORD: app
         POSTGRES_DB: appdb
       ports: ["5432:5432"]
       volumes: [dbdata:/var/lib/postgresql/data]
   volumes:
     dbdata:
   ```

   Zaženi: `docker compose up -d db`

2. **Nastavi .env (Laravel)**
   Odpri `.env` in nastavi:

   ```
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=appdb
   DB_USERNAME=app
   DB_PASSWORD=app

   OPENAI_API_KEY=sk-...  # tvoj ključ
   ```

   (Če imaš drugačne DB podatke, uporabi svoje.)

3. **Dodaj Laravel HTTP client (če še ni)**

   ```bash
   composer require guzzlehttp/guzzle
   ```

---

# 1) Vključi pgvector razširitev

Ustvari migracijo:

```bash
php artisan make:migration enable_pgvector_extension
```

Odpri `database/migrations/xxxx_xx_xx_xxxxxx_enable_pgvector_extension.php` in vnesi:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
    }
    public function down(): void {
        DB::statement('DROP EXTENSION IF EXISTS vector;');
    }
};
```

---

# 2) Tabela za “chunke” zakonodaje

```bash
php artisan make:migration create_law_chunks_table
```

`database/migrations/xxxx_xx_xx_xxxxxx_create_law_chunks_table.php`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('law_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');     // "PUBLIC" ali npr. tenant_x
            $table->string('source');        // PISRS / EUR-LEX / SODNA
            $table->string('title');         // npr. ZDR-1
            $table->string('citation');      // npr. "člen 89"
            $table->text('url');             // povezava na vir
            $table->longText('text');        // besedilo odseka
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->timestamps();
        });

        // dodaj vektorsko kolono (1536 = dim OpenAI text-embedding-3-small)
        DB::statement("ALTER TABLE law_chunks ADD COLUMN embedding vector(1536);");

        // full-text index (za hibridno iskanje)
        DB::statement("CREATE INDEX law_chunks_fts_idx ON law_chunks USING GIN (to_tsvector('simple', text));");
    }

    public function down(): void
    {
        Schema::dropIfExists('law_chunks');
    }
};
```

Zaženi migracije:

```bash
php artisan migrate
```

> **Opomba (pozneje):** Ko boš imela nekaj tisoč vnosov, ustvari tudi k-NN indeks:
>
> ```sql
> CREATE INDEX law_chunks_vec_idx ON law_chunks
> USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
> ```

---

# 3) Model

```bash
php artisan make:model LawChunk
```

`app/Models/LawChunk.php`:

```php
<?php
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

    protected static function booted() {
        static::creating(function ($m) {
            if (!$m->id) $m->id = (string) Str::uuid();
        });
    }
}
```

---

# 4) RAG servis (embeddings, iskanje, odgovor)

```bash
php artisan make:service RagService
# če ukaz ne obstaja, samo ročno ustvari datoteko
```

`app/Services/RagService.php`:

```php
<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RagService
{
    // vector must have at least 1 dimension
    // Napaka pomeni, da v bazo zapisuješ prazen vektor ([]). To se zgodi, ko klic na OpenAI ni vrnil embeddinga (napačen/neenoten API ključ, blokiran request, napačen model ipd.). Postgresov tip vector(1536) pričakuje 1536 števil, ne [].Dodaj robustno preverjanje in embed and upsertChunk
    public function embed(string $text): array
    {
        $resp = Http::withToken(env('OPENAI_API_KEY'))->post(
            'https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => mb_substr($text, 0, 8000)
            ]
        );
    
        if (!$resp->ok()) {
            // Zapiši napako v log, vrni prazen array
            \Log::error('Embedding API error', ['status'=>$resp->status(), 'body'=>$resp->json()]);
            return [];
        }
    
        $data = $resp->json();
        $vec = $data['data'][0]['embedding'] ?? [];
    
        // Varnostni check: mora biti 1536 dimenzij
        if (!is_array($vec) || count($vec) < 1) {
            \Log::error('Empty embedding returned', ['data'=>$data]);
            return [];
        }
    
        return $vec;
    }
    
    public function upsertChunk(array $payload): void
    {
        $vec = $this->embed($payload['text']);
    
        if (empty($vec)) {
            // Ne zapisuj praznega vektorja -> raje NULL in opozori
            \Log::warning('Skipping embedding vector, writing NULL', ['title'=>$payload['title'] ?? null, 'citation'=>$payload['citation'] ?? null]);
    
            \DB::table('law_chunks')->insert([
                'id' => $payload['id'] ?? (string) \Str::uuid(),
                'tenant_id' => $payload['tenant_id'] ?? 'PUBLIC',
                'source' => $payload['source'],
                'title' => $payload['title'],
                'citation' => $payload['citation'],
                'url' => $payload['url'],
                'text' => $payload['text'],
                'embedding_model' => 'text-embedding-3-small',
                'embedding' => null, // <— KLJUČNO
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            return;
        }
    
        \DB::table('law_chunks')->insert([
            'id' => $payload['id'] ?? (string) \Str::uuid(),
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
```

---

# 5) Seed (2 demo člena)

```bash
php artisan make:command LawSeed
```

`app/Console/Commands/LawSeed.php`:

```php
<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RagService;

class LawSeed extends Command
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
    \App\Console\Commands\LawSeed::class,
];
```

Zaženi:

```bash
php artisan migrate
php artisan law:seed
```

---

# 6) Endpoint za vprašanja

```bash
php artisan make:controller AskController
```

`app/Http/Controllers/AskController.php`:

```php
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
```

**Ruta** `routes/api.php`:

```php
use App\Http\Controllers\AskController;

// v praksi dodaj auth:sanctum middleware
Route::post('/ask', AskController::class);
```

---

# 7) Hitri test

Poženi dev strežnik:

```bash
php artisan serve
```

Pošlji poizvedbo (npr. z HTTP clientom ali curl):

```bash
curl -X POST http://127.0.0.1:8000/api/ask \
  -H "Content-Type: application/json" \
  -d '{"q":"Kateri člen ureja odpoved pogodbe o zaposlitvi?", "mode":"hybrid"}'
```

Moral bi dobiti **odgovor + citate** (na podlagi naših 2 seed chunkov).

---

# 8) (Opcijsko) Ustvari k-NN indeks po polnjenju

Ko boš imela večje število vnosov:

```php
php artisan tinker
>>> DB::statement("CREATE INDEX law_chunks_vec_idx ON law_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);");
```

---

# 9) Kaj je naslednje?

* **Uvoz pravih virov**: v `app/Console/Commands` naredi “ingest job”, ki:

  1. pokliče PISRS/EUR-Lex (njihov API),
  2. razdeli akte na **člene/odstavke**,
  3. kliče `$rag->upsertChunk(...)` za vsak odsek.
* **Auth + kvote**: zaščiti `/api/ask` z `auth:sanctum`, vodi `queries` in omejitve po planu.
* **Frontend**: preprost Vue zaslon (input + prikaz odgovora in citatov).
* **Guardrails**: če ni dovolj konteksta, vrni prijazen “ne vem” in predlagaj zakon/ključne besede.
* **Disclaimer** v UI: *orodje ne nadomešča pravnega svetovanja*.

---

Če želiš, ti zdaj pripravim še **Artisan ukaz za “ingest PISRS/EUR-Lex”** (okostje z TODO), ali pa **mini Vue stran** (input → odgovor + citati), da boš lahko takoj klikala. Katero raje?


--
// app/Console/Commands/LawRepairEmbeddings.php
public function handle(\App\Services\RagService $rag) {
  $rows = \DB::table('law_chunks')->whereNull('embedding')->limit(1000)->get();
  foreach ($rows as $row) {
    $vec = $rag->embed($row->text);
    if (!empty($vec)) {
      \DB::table('law_chunks')->where('id',$row->id)->update([
        'embedding' => sprintf('[%s]', implode(',', $vec)),
        'updated_at' => now(),
      ]);
      $this->info("Fixed: {$row->title} {$row->citation}");
    }
  }
}

--

### Test
curl -X POST http://localhost:8000/api/ask \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"q":"Kateri člen ureja odpoved pogodbe o zaposlitvi?", "mode":"hybrid"}'


Route::match(['get','post'], '/ask', AskController::class);

http://localhost:8000/api/ask?q=Kateri+člen+ureja+odpoved+pogodbe+o+zaposlitvi%3F&mode=hybrid


public function vectorSearch(string $q, string $tenantId, int $k = 6): array
{
    $qVec = $this->embed($q);                            // [floats]
    $qVecStr = '['.implode(',', $qVec).']';              // "[..., ...]"

    $rows = \DB::select("
        SELECT id, source, title, citation, url, text,
               1 - (embedding <=> ?::vector) AS score
        FROM law_chunks
        WHERE tenant_id IN ('PUBLIC', ?)
        ORDER BY embedding <=> ?::vector ASC
        LIMIT {$k}
    ", [$qVecStr, $tenantId, $qVecStr]);

    return array_map(fn($r)=>(array)$r, $rows);
}

### Hitri fix (uporabi vezane parametre + ::vector)
(v SELECT-u vektor mora biti podan kot literal (ali vezan parameter) tipa vector. Trenutno v poizvedbi stoji embedding <=> [ ... ] brez narekovajev, zato PostgreSQL vidi [ kot napako.)

Posodobi metodi vectorSearch() in hybridSearch() v App\Services\RagService

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
      SELECT id, source, title, citation, url, text,
             1 - (embedding <=> ?::vector) AS score
      FROM law_chunks
      WHERE id IN ('{$ids}')
      ORDER BY embedding <=> ?::vector ASC
      LIMIT {$k}
    ", [$qVecStr, $qVecStr]);

    return array_map(fn($r)=>(array)$r, $rows);
}

### Add frontend

Odlično! 🎉 To pomeni, da tvoj **MVP RAG** deluje: najde člen in vrne odgovor + citate. Zdaj si na točki, ko lahko začneš “zategovati vijake” in dodajati zmogljivosti.

## Naslednji hitri koraki (priporočeno)

1. **IVFFLAT indeks** (pospeši iskanje pri več podatkih):

```sql
CREATE INDEX law_chunks_vec_idx ON law_chunks
USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
```

2. **Več vsebine**: dodaj še ključne člene (ZPP, OZ, DZ, SPZ, ZIZ …) – za vsak člen pokliči `upsertChunk()`.

3. **Hibridna relevanca**: v `hybridSearch` pusti BM25 → re-rank z vektorji (že imaš); deluje super na pravnih besedilih.

4. **Guardrails**:

* Če `chunks` prazni → vrni “potrebujem več konteksta” + predlagaj zakone/ključne besede.
* `temperature: 0.2`, navodilo “odgovarjaj izključno na podlagi virov”.

5. **Frontend** (mini stran za test):
   V `resources/views/ask.blade.php`:

```php
<!doctype html><html><head><meta charset="utf-8"><title>AI Pravni Asistent</title></head>
<body style="max-width:720px;margin:2rem auto;font-family:sans-serif">
  <h1>AI pravni asistent</h1>
  <form id="f">
    <input id="q" style="width:100%;padding:.6rem" placeholder="Vnesi vprašanje..." />
    <button style="margin-top:.5rem">Vprašaj</button>
  </form>
  <pre id="out" style="white-space:pre-wrap;margin-top:1rem"></pre>
<script>
document.getElementById('f').addEventListener('submit', async e=>{
  e.preventDefault();
  const q = document.getElementById('q').value;
  const r = await fetch('/api/ask',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({q,mode:'hybrid'})});
  document.getElementById('out').textContent = JSON.stringify(await r.json(), null, 2);
});
</script>
</body></html>
```

`routes/web.php`:

```php
Route::get('/ask-demo', fn() => view('ask'));
```

Odpri: `http://localhost:8000/ask-demo`

6. **Lepši citati v odgovoru** (namesto surovega JSON): v frontend-u prikaži seznam linkov pod odgovorom.

7. **Seed/repair opravila**:

* “Seeder” za množico členov.
* “Repair” ukaz, ki zapolni `embedding IS NULL`, če je bil prej quota 429.

8. **Multi-tenant priprava** (za SaaS): dodaj `users`, `tenants`, API-ključe in kvote (Stripe kasneje).

9. **Varnost/GDPR**: disclaimer v UI + ne logiraj PII v vprašanjih.

Če želiš, ti lahko pripravim:

* kratek **ingest ukaz** za 5–10 najpogostejših zakonov (ZPP, OZ, ZDR-1, DZ, SPZ, ZIZ),
* ali **preprost Vue komponento**, ki lepo renderira odgovor + klikalne citate.


```
// v tvojem seederju/ukazu dodaj seederje dokler ne fetchamo pravih podatkov:
$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZPIZ-2',
  'citation'=>'člen 63', // primer – uporabi pravi člen za definicijo invalidnosti
  'url'=>'https://www.pisrs.si/…', // prava PISRS stran ZPIZ-2
  'text'=>'(Besedilo člena, ki definira invalidnost …)'
]);

$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZZRZI',
  'citation'=>'člen 4',
  'url'=>'https://www.pisrs.si/…',
  'text'=>'(Ključne določbe o zaposlitveni rehabilitaciji invalidov …)'
]);

$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZIMI',
  'citation'=>'člen 3',
  'url'=>'https://www.pisrs.si/…',
  'text'=>'(Temeljne definicije in načela izenačevanja možnosti invalidov …)'
]);

```
## Posodobi kodo

Super opazka — odgovor je “pravilen” glede na tvoj trenutni indeks, ne pa glede na vse pravo. Zakaj?

## Od kod tak odgovor?

* Trenutno imaš v bazi **samo 2 seed chunka** (ZDR-1/89 in OZ/299).
* Ko vprašaš *“Kateri člen ureja invalidnost?”*, hibridno iskanje najde **ista dva odseka**, ker je korpus miniaturn (BM25 in vektorji nimajo drugih kandidatov).
* LLM dobi ta kontekst in (zaradi guardraila “odgovarjaj samo na podlagi virov”) **pošteno pove, da v navedenih virih invalidnosti ni**, hkrati pa mi še vedno vrnemo “citations” (ker jih generiramo iz vrnjenih chunkov). Zato vidiš ZDR-1/89 in OZ/299 pri odgovoru “nimam virov”.

## Kako to popraviti (3 hitri koraki)

### 1) Dodaj relevantne zakone o invalidnosti

Za taka vprašanja potrebuješ vsaj te vire v “PUBLIC” indeksu:

* **ZPIZ-2** — Zakon o pokojninskem in invalidskem zavarovanju (definicije invalidnosti, postopki, pravice).
* **ZZRZI** — Zakon o zaposlitveni rehabilitaciji in zaposlovanju invalidov.
* **ZIMI** — Zakon o izenačevanju možnosti invalidov.
* (Po potrebi) **ZDR-1** (invalidi in varstvo pri delu), **ZPIZ pravilniki**, ipd.

Minimalni seed primer (dokler nimaš fetcherja):

```php
// v tvojem seederju/ukazu:
$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZPIZ-2',
  'citation'=>'člen 63', // primer – uporabi pravi člen za definicijo invalidnosti
  'url'=>'https://www.pisrs.si/…', // prava PISRS stran ZPIZ-2
  'text'=>'(Besedilo člena, ki definira invalidnost …)'
]);

$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZZRZI',
  'citation'=>'člen 4',
  'url'=>'https://www.pisrs.si/…',
  'text'=>'(Ključne določbe o zaposlitveni rehabilitaciji invalidov …)'
]);

$rag->upsertChunk([
  'tenant_id'=>'PUBLIC','source'=>'PISRS','title'=>'ZIMI',
  'citation'=>'člen 3',
  'url'=>'https://www.pisrs.si/…',
  'text'=>'(Temeljne definicije in načela izenačevanja možnosti invalidov …)'
]);
```

*(URL-je in točne člene zamenjaj s pravimi — ko boš imela PISRS fetch, bo to avtomatika.)*

### 2) Ne vračaj “lažnih” citatov, če kontekst ni relevanten

Dodaj **prag relevantnosti**. Če noben zadetek ne doseže praga, vrni prazen seznam citatov in prijazen fallback.

V `RagService::vectorSearch()` in `hybridSearch()` že vračamo `score`. Uporabi ga v kontrolerju:

```php
// AskController
$minScore = 0.75; // prilagodi po občutku/meritvah
$relevant = collect($chunks)->filter(fn($c) => ($c['score'] ?? 0) >= $minScore)->values()->all();

if (empty($relevant)) {
    return response()->json([
        'answer' => 'V trenutnih virih nimam dovolj informacij za “invalidnost”. Dodajmo ZPIZ-2, ZZRZI ali ZIMI, ali prosim natančneje opiši kontekst.',
        'citations' => []
    ]);
}

$answer = $rag->answer($data['q'], $relevant);
$cites = collect($relevant)->take(4)->map(fn($c)=>[
  'title'=>$c['title'],'citation'=>$c['citation'],'url'=>$c['url']
]);
return response()->json(['answer'=>$answer,'citations'=>$cites]);
```

### 3) Naj hibridno iskanje res zahteva “tekstovni” zadetek

Da se ne bodo iste 2 seed vrstici vračali pri vsakem vprašanju, naj **BM25 del** izbere vsaj nekaj smiselnih kandidatov — če jih ni, naredi “early return”:

```php
// RagService::hybridSearch()
$bm25 = DB::select("
  SELECT id, ts_rank(to_tsvector('simple', text), plainto_tsquery('simple', ?)) AS r
  FROM law_chunks
  WHERE tenant_id IN ('PUBLIC', ?)
    AND to_tsvector('simple', text) @@ plainto_tsquery('simple', ?)
  ORDER BY r DESC
  LIMIT {$bm25N}
", [$q, $tenantId, $q]);

if (!$bm25) {
  // brez BM25 kandidatov → vrni prazno, naj controller poda human-friendly fallback
  return [];
}
```

---

## Kaj boš dobila po teh spremembah

* Za vprašanja o invalidnosti se bodo v “top-k” vrnili **členi iz ZPIZ-2/ZZRZI/ZIMI**, LLM bo iz njih sestavil jasen odgovor **z ustreznimi citati in URL-ji**.
* Če teh zakonov še ni v indeksu, bo sistem **nehal kazati napačne citate** (ZDR-1/89, OZ/299) in bo lepo prosil za dodatne vire ali natančnejše vprašanje.

Če želiš, ti pripravim **mini seeder** z nekaj dejanskimi členi iz ZPIZ-2/ZZRZI/ZIMI (do mere, ki jo lahko daš v bazo ročno), da boš takoj videla lep odgovor na točno to vprašanje.

## OpenAI flow

Super vprašanje 👌 – zdaj, ko ti sistem že vrača odgovore, je fino natančno vedeti **kje v tvojem AI pravnem asistentu dejansko nastopa OpenAI**.

---

## 🔎 Kje je OpenAI v tvojem flowu

Tvoj asistent ima dva AI “koraka”, kjer kličeš OpenAI API:

### 1. **Embeddings API**

* Vsak člen zakona ali vprašanje uporabnika pošlješ na:

  ```
  POST https://api.openai.com/v1/embeddings
  model: text-embedding-3-small
  input: "besedilo člena" ali "vprašanje uporabnika"
  ```
* OpenAI vrne **vektor (array 1536 števil)**, ki semantično predstavlja pomen besedila.
  Npr. *“odpoved pogodbe”* in *“prenehanje delovnega razmerja”* dobita skoraj identične vektorje.
* Ta embedding shraniš v Postgres (`vector(1536)`) in ga uporabljaš pri iskanju (cosine similarity).

👉 Brez tega koraka bi imela samo navaden “CTRL+F” po besedilu, ne pa **semantičnega iskanja**.

---

### 2. **Chat Completions API**

* Ko najdeš 5–10 najbližjih členov, jih pošlješ v:

  ```
  POST https://api.openai.com/v1/chat/completions
  model: gpt-4o-mini
  messages: [
    {role: "system", content: "Odgovarjaj izključno na podlagi virov …"},
    {role: "user", content: "VIRI:\n ... \n VPRAŠANJE: ..."}
  ]
  ```
* OpenAI model nato:

  * prebere člene,
  * sestavi povzetek v slovenščini,
  * doda reference (npr. “89. člen ZDR-1, URL …”).

👉 Tukaj OpenAI deluje kot **naravni jezikovni generator**, ki iz konteksta zloži lep odgovor.

---

## 📌 Kaj NE dela OpenAI v tvojem sistemu

* **Ne hrani tvoje baze** (členi ostanejo v tvojem Postgresu).
* **Ne odloča sam, kaj vrne** – vedno dobi striktno navodilo: *“Odgovarjaj izključno na podlagi virov”*.
* **Ne išče po internetu** – dela samo s tistim kontekstom (členi), ki mu ga ti pošlješ.

---

## 🎯 Povzetek

* **Embeddings API** = OpenAI ti daje **številčni vektorski zapis** besedila, da lahko v Postgresu delaš semantično iskanje.
* **Chat Completions API** = OpenAI iz konteksta, ki si mu ga ti pripravila (najdeni členi), sestavi lep odgovor v naravnem jeziku.

Vse ostalo (shramba, iskanje, logika, multi-tenancy, billing …) dela tvoj Laravel + Postgres.

---

👉 Bi želela, da ti narišem **diagram podatkovnega toka** (User → Laravel → Embeddings → Postgres → najdeni členi → OpenAI GPT → Laravel → User), da boš imela vizualno jasno, kje točno vstopa OpenAI?

Super vprašanje 👌 – zdaj si prišla do bistva: **od kod tvoj AI pravni asistent dobi “pamet”**.

---

## 1️⃣ Kaj AI sam NE zna

OpenAI (ali katerikoli LLM) **sam po sebi ne pozna slovenske zakonodaje** v natančni, zanesljivi obliki, še posebej:

* ažurnih verzij zakonov (spreminjajo se),
* uradnih objav (PISRS, EUR-Lex, sodna praksa),
* slovenskih pravnih pojmov.

Če bi LLM vprašala kar “kateri člen ureja invalidnost”, bi iz “splošnega znanja” nekaj uganil — a to **ni pravno zanesljivo** in za resne uporabnike nesprejemljivo.

👉 Zato moraš mu **ti sama dati kontekst** = člene, akte, sodbe.

---

## 2️⃣ Kako mu daješ znanje (RAG = Retrieval Augmented Generation)

* Vsak zakon, člen, sodba → “chunk” (kos besedila, npr. en člen).
* Ta chunk shraniš v bazo (Postgres z `pgvector`).
* Ob vprašanju uporabnika narediš embedding vprašanja in poiščeš najbližje chanke.
* Te pošlješ v LLM → ta generira odgovor *na podlagi virov*, ne iz spomina.

To pomeni: **AI ve samo toliko, kolikor si mu “založila” v bazo.**

---

## 3️⃣ Ali moraš vnesti *vse* zakone in sodbe?

Ne nujno, odvisno od cilja:

* **MVP / demo za odvetnico**: vneseš samo nekaj ključnih zakonov (npr. ZDR-1, OZ, ZPP, ZPIZ-2).
* **Produkcija (pravni asistent za širšo uporabo)**:

  * *zakoni in predpisi*: lahko avtomatsko preneseš iz **PISRS** (ima API).
  * *sodna praksa*: lahko začneš z nekaj pomembnimi bazami (npr. odločbe Vrhovnega sodišča, USRS).
  * *EU pravo*: EUR-Lex ima API (direktive, uredbe, sodbe SEU).

---

## 4️⃣ Kako v praksi vnesti vire

* **Zakoni**: 1x uvoz (scrape ali API iz PISRS), nato dnevno spremljanje sprememb.
* **Sodbe**: odvisno od vira → lahko 1x batch + nato redno dodajanje novih.
* **Zasebni dokumenti strank** (če bo SaaS za odvetnike): ti se hranijo pod `tenant_id`, ločeno od PUBLIC zakonodaje.

---

## 5️⃣ Kaj se zgodi, če vira NI v bazi?

* Po tvojem guardrailu AI pove nekaj takega:

  > “V trenutnih virih ni podatkov o invalidnosti. Za zanesljiv odgovor je treba dodati ZPIZ-2 ali ZZRZI.”
* S tem se izogneš “halucinaciji” in zaupanje ostane.

---

## 6️⃣ Kako začeti pragmatično

Ne rabiš takoj vse Slovenije + EU:

1. Za demo → **ZDR-1 + OZ + ZPP** (že 80 % delovnih vprašanj).
2. Za invalidnost → dodaj **ZPIZ-2, ZZRZI, ZIMI**.
3. Postopoma dodajaj zakone glede na potrebe uporabnikov.

Kasneje narediš **scheduled crawler** za PISRS/EUR-Lex, ki bo baze sproti posodabljal.

---

## 🎯 Povzetek

* Da, **moraš vnesti zakone/sodbe v bazo**, ker LLM sam ne bo zanesljivo poznal vsebine.
* Ne rabiš pa “vseh naenkrat” → začneš s tistimi, ki so za tvojo ciljno publiko najpomembnejši.
* Kasneje avtomatiziraš ingest in vzdrževanje baze.

---

👉 Bi želela, da ti konkretno pokažem **primer, kako avtomatsko potegniti zakon iz PISRS (npr. ZDR-1)** in ga razbiješ na člene, da jih lahko daš v svojo bazo?


---
## Povleci zakon - 2 primera

Odlično—gremo konkretno: pokazal ti bom **dva praktična načina** kako “potegneš” zakon (npr. ZDR-1) in ga razbiješ na **člene** ter shraniš v svojo bazo:

1. **A: prek uradnega PISRS API (priporočeno za produkcijo)**

Skupaj s kodo za **Laravel 12** (Artisan ukaz), ki pokliče tvoj **RagService::upsertChunk()** za vsak člen.

> Opombe do virov: PISRS ima uradni **Swagger** (tam vidiš točne endpointe in parametre) in je uradni izvor slovenske zakonodaje. EU del (EUR-Lex) ima uradni web-service (SOAP) za EU akte. ([pisrs.si][1], [EUR-Lex][2])

---

# A) PISRS prek API (kadar imaš dostop do endpointov)

1. V `app/Services/PisrsApi.php` (preprost klient):

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PisrsApi
{
    public function __construct(
        private readonly string $base = 'https://pisrs.si' // glej Swagger
    ) {}

    /** Vrne meta podatke o aktu (naslov, URL …) */
    public function getAct(string $id): array
    {
        // Primer: GET /api/predpisi/{id} (TOČEN endpoint poglej v Swaggerju)
        $res = Http::get($this->base.'/api/predpisi/'.$id);
        $res->throw();
        return $res->json();
    }

    /** Vrne vsebino akta razbito po členih */
    public function getArticles(string $id): array
    {
        // Primer: GET /api/predpisi/{id}/cleni ali podobno (glej Swagger)
        $res = Http::get($this->base."/api/predpisi/{$id}/cleni");
        $res->throw();
        return $res->json(); // pričakujemo [{article_no:'89', text:'...'}, ...]
    }
}
```

2. Artisan ukaz, ki akt razbije na “chunke” in shrani:

```php
<?php
// app/Console/Commands/PisrsIngestApi.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PisrsApi;
use App\Services\RagService;

class PisrsIngestApi extends Command
{
    protected $signature = 'pisrs:ingest-api {id : PISRS id, npr. ZAKO3850} {--tenant=PUBLIC}';
    protected $description = 'Uvozi akt iz PISRS API in shrani člene v bazo';

    public function handle(PisrsApi $api, RagService $rag)
    {
        $id = $this->argument('id');
        $tenant = $this->option('tenant');

        $act = $api->getAct($id);               // meta (title, url ...)
        $articles = $api->getArticles($id);     // [{article_no,text}, ...]

        $title = $act['title'] ?? ($act['name'] ?? $id);
        $url   = $act['url']   ?? "https://pisrs.si/pregledPredpisa?id={$id}";

        foreach ($articles as $art) {
            $text = trim($art['text'] ?? '');
            $no   = $art['article_no'] ?? $art['number'] ?? null;
            if (!$text || !$no) continue;

            $rag->upsertChunk([
                'tenant_id' => $tenant,
                'source'    => 'PISRS',
                'title'     => $title,
                'citation'  => "člen {$no}",
                'url'       => $url,
                'text'      => $text
            ]);

            $this->info("OK: {$title} člen {$no}");
        }

        $this->info('✅ Končano.');
    }
}
```

> Točne poti endpointov in strukturo JSON prebereš v **Swagger UI** na PISRS (potreben je JS, a to je uradna dokumentacija). ([pisrs.si][1])

---



Zdaj lahko poženeš (primer za ZDR-1):

```bash
php artisan pisrs:ingest-api ZAKO3800
```

**Kje dobiš ID akta?**
Na PISRS je to parameter `id` v URL (npr. `.../pregledPredpisa?id=ZAKO3850`). ([pisrs.si][3])

---

## EU pravo (EUR-Lex) – na kratko

* EUR-Lex ima uradni **Webservice (SOAP)**: registriraš se, poiščeš dokumente po **CELEX** in dobiš XML. ([EUR-Lex][2])
* V praksi narediš mali “adapter”: *search by CELEX* → prenesi HTML/XML → razreži na **člene** podobno kot zgoraj → shrani v svojo bazo.

---

## Kaj ti to prinese danes

*  **uradni PISRS API** (stabilno, čisto).
* **EU** dodaš prek EUR-Lex webservice (CELEX → člen → chunk).

---

## Best-practice nasveti

* Vsak chunk shrani z: `source=PISRS/EUR-LEX`, `title`, `citation` (npr. “člen 89”), `url`, `text`, `tenant_id` (`PUBLIC` za javne).
* Dodaj **scheduler** (cron), ki tedensko preveri spremembe in posodobi/briše “zastarele” chanke (npr. na podlagi datuma objave/veljavnosti iz API).
* Spoštuj **pogoje ponovne uporabe** PISRS in **EUR-Lex** (EU podatki so praviloma prosto ponovno uporabni ob navajanju vira). ([EUR-Lex][4])

---

Če želiš, ti lahko zdaj pripravim **konkreten primer z ZPIZ-2** (členi o invalidnosti), da bo vprašanje “Kateri člen ureja invalidnost?” vrnilo lep, točen odgovor z URL-jem.

[1]: https://pisrs.si/swagger "PISRS API za razvijalce"
[2]: https://eur-lex.europa.eu/content/help/data-reuse/webservice.html?utm_source=chatgpt.com "Webservice - EUR-Lex - European Union"
[3]: https://pisrs.si/?utm_source=chatgpt.com "Pravno-informacijski sistem Republike Slovenije (PISRS)"
[4]: https://eur-lex.europa.eu/content/help/data-reuse/reuse-contents-eurlex-details.html?utm_source=chatgpt.com "Reuse EUR-Lex content - European Union"


## Povleci vse zakone
Odlično vprašanje. Da “povlečeš **vse zakone** s PISRS in shraniš **vse člene**”, rabiš poln **ingest pipeline**: seznam aktov → prenos vsebine → rezanje na člene → embeddings → shranjevanje → ponovitve (delta posodobitve). Spodaj dobiš **zelo konkreten načrt + kodo (Laravel 12)**, ki ga lahko takoj vgradiš. Ker uradni endpointi PISRS se razlikujejo po okolju, so URL-ji označeni kot TODO – zamenjaš jih z dejanskimi iz Swaggerja.

---

# Kako pristopiš (na kratko)

1. **Seznam aktov** (paginated): kličeš PISRS “list acts” (po možnosti s filtrom *veljavni*).
2. **Za vsak akt**: prenesi strukturo (členi/odstavki) → razreži na “chunks” (1 člen = 1 chunk).
3. **Shrani metapodatke** (act\_id, naslov, URL, datum/veljavnost, verzija).
4. **Embeddings** v batchih (upoštevaj kvoto/rate limit). Če Embeddings API ni na voljo → shrani `embedding=NULL` in dopolni z “repair” opravilom kasneje.
5. **Idempotentno**: če chunk že obstaja (isti act\_id + čl. št. + hash besedila), ne vnašaj znova.
6. **Delta update**: dnevno/tedensko poženi “updated\_after=YYYY-MM-DD” in osveži le spremenjene akte.

---

# Razširjena shema (SQL)

Dodan vir/različice + hash za idempotentnost:

```sql
-- meta o aktih
CREATE TABLE IF NOT EXISTS sources (
  id TEXT PRIMARY KEY,              -- PISRS act id (npr. ZAKO3850)
  title TEXT NOT NULL,
  url TEXT NOT NULL,
  source_kind TEXT NOT NULL,        -- 'PISRS'
  last_modified_at TIMESTAMP,       -- iz API
  version_tag TEXT,                 -- če PISRS izpostavi (npr. šifra prečiščenja)
  created_at TIMESTAMP DEFAULT now(),
  updated_at TIMESTAMP DEFAULT now()
);

-- chunki (členi)
ALTER TABLE law_chunks
  ADD COLUMN IF NOT EXISTS law_id TEXT,
  ADD COLUMN IF NOT EXISTS text_sha256 TEXT,
  ADD COLUMN IF NOT EXISTS valid_from DATE,
  ADD COLUMN IF NOT EXISTS valid_to DATE;

CREATE INDEX IF NOT EXISTS law_chunks_law_id_idx ON law_chunks (law_id);
CREATE INDEX IF NOT EXISTS law_chunks_text_sha_idx ON law_chunks (text_sha256);
```

---

# Laravel: PISRS klient (seznam + vsebina)

`app/Services/PisrsApi.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PisrsApi
{
    public function __construct(
        private readonly string $base = 'https://pisrs.si' // TODO: zamenjaj po Swaggerju
    ) {}

    /** Paginiran seznam aktov (vrne [ {id,title,url,last_modified}, ... ]) */
    public function listActs(int $page = 1, int $pageSize = 100, ?string $updatedAfter = null): array
    {
        $q = [
            'page' => $page,
            'pageSize' => $pageSize,
            // primer: filter veljavnosti/sprememb; uskladi s Swaggerjem:
            // 'status' => 'valid',
            // 'updatedAfter' => $updatedAfter,
        ];
        $res = Http::get("{$this->base}/api/predpisi", $q);
        $res->throw();
        return $res->json(); // pričakujemo keys: items[], totalPages, ...
    }

    /** Vsebina akta (naslov, url, členi) */
    public function getActWithArticles(string $id): array
    {
        $meta = Http::get("{$this->base}/api/predpisi/{$id}")->throw()->json();
        $arts = Http::get("{$this->base}/api/predpisi/{$id}/cleni")->throw()->json();
        return [
            'id' => $id,
            'title' => $meta['title'] ?? $meta['name'] ?? $id,
            'url' => $meta['url'] ?? "https://pisrs.si/pregledPredpisa?id={$id}",
            'last_modified' => $meta['last_modified'] ?? null,
            'version_tag' => $meta['version'] ?? null,
            'articles' => $arts, // [{article_no, text, valid_from, valid_to}, ...]
        ];
    }
}
```

> Če PISRS vrača drugačna polja, jih preimenuj v skladu s Swaggerjem.

---

# Hash util (za idempotentnost)

`app/Support/TextHash.php`

```php
<?php
namespace App\Support;

class TextHash {
    public static function sha(string $text): string {
        // normaliziraj whitespace, da majhne razlike ne ustvarjajo novih hashov
        $t = preg_replace('/\s+/u', ' ', trim($text));
        return hash('sha256', $t);
    }
}
```

---

# Ingest servis: shrani akt + člene (z varnim embeddingom)

`app/Services/IngestService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\TextHash;

class IngestService
{
    public function __construct(private RagService $rag) {}

    /** Shrani/posodobi source meta */
    public function upsertSource(array $src): void
    {
        DB::table('sources')->updateOrInsert(
            ['id' => $src['id']],
            [
                'title' => $src['title'],
                'url' => $src['url'],
                'source_kind' => 'PISRS',
                'last_modified_at' => $src['last_modified'] ?? null,
                'version_tag' => $src['version_tag'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /** Vstavi ali posodobi člena; embedding je opcijski (NULL, če pade quota) */
    public function upsertArticleChunk(string $tenantId, array $act, array $art): void
    {
        $text = trim($art['text'] ?? '');
        $no   = $art['article_no'] ?? $art['number'] ?? null;
        if (!$text || !$no) return;

        $sha = TextHash::sha($text);

        // poskusi embed
        $vec = $this->rag->embed($text); // lahko vrne []

        $payload = [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'source' => 'PISRS',
            'title' => $act['title'],
            'citation' => "člen {$no}",
            'url' => $act['url'],
            'text' => $text,
            'embedding_model' => 'text-embedding-3-small',
            'law_id' => $act['id'],
            'text_sha256' => $sha,
            'valid_from' => $art['valid_from'] ?? null,
            'valid_to' => $art['valid_to'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Če že obstaja identičen hash za ta law_id+člen → update metapodatkov, ne podvajaj
        $exists = DB::table('law_chunks')->where([
            ['law_id', '=', $act['id']],
            ['citation', '=', "člen {$no}"],
            ['text_sha256', '=', $sha],
            ['tenant_id', '=', $tenantId],
        ])->first();

        if ($exists) {
            DB::table('law_chunks')->where('id', $exists->id)->update([
                'url' => $act['url'],
                'valid_from' => $payload['valid_from'],
                'valid_to' => $payload['valid_to'],
                'updated_at' => now(),
            ]);
            return;
        }

        // Vstavi (embedding lahko NULL, če ga trenutno ni)
        DB::table('law_chunks')->insert(array_merge(
            $payload,
            ['embedding' => empty($vec) ? null : sprintf('[%s]', implode(',', $vec))]
        ));
    }
}
```

---

# Batch ingest (Artisan ukaz): FULL + DELTA

`app/Console/Commands/PisrsIngestAll.php`

```php
<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Services\PisrsApi;
use App\Services\IngestService;

class PisrsIngestAll extends Command
{
    protected $signature = 'pisrs:ingest-all {--updated-after=} {--tenant=PUBLIC} {--pagesize=100}';
    protected $description = 'Povleci seznam vseh PISRS aktov in shrani vse člene (full ali delta ingest).';

    public function handle(PisrsApi $api, IngestService $ing)
    {
        $tenant = $this->option('tenant');
        $pageSize = (int) $this->option('pagesize');
        $updatedAfter = $this->option('updated-after'); // npr. 2025-01-01

        $page = 1;
        $totalPages = 1;

        do {
            $this->info("Seznam aktov: page {$page}");
            $list = $api->listActs($page, $pageSize, $updatedAfter);

            $items = $list['items'] ?? $list['results'] ?? [];
            $totalPages = (int) ($list['totalPages'] ?? ($items ? $page : $page));

            foreach ($items as $it) {
                $id = $it['id'] ?? null;
                if (!$id) continue;

                // backoff & throttling (preprosto)
                usleep(200_000); // 200ms med klici; po potrebi povečaj

                try {
                    $act = $api->getActWithArticles($id);
                } catch (\Throwable $e) {
                    $this->error("NAPAKA pri aktu {$id}: ".$e->getMessage());
                    continue;
                }

                $ing->upsertSource([
                    'id' => $act['id'],
                    'title' => $act['title'],
                    'url' => $act['url'],
                    'last_modified' => $act['last_modified'] ?? null,
                    'version_tag' => $act['version_tag'] ?? null,
                ]);

                $arts = $act['articles'] ?? [];
                $this->line("  → {$act['title']} (členov: ".count($arts).")");

                foreach ($arts as $art) {
                    $ing->upsertArticleChunk($tenant, $act, $art);
                }
            }

            $page++;
        } while ($page <= $totalPages);

        $this->info('✅ Ingest končan.');
        return self::SUCCESS;
    }
}
```

> Ko PISRS vrne **totalPages/nextPage** drugače, prilagodi zanko. Če ima “cursor”/“offset”, uporabi to shemo namesto `page`.

---

## Performance & stroški (praksa)

* **Rate limit** PISRS: dodaj `usleep()` med klici in “retry with backoff” pri 429/5xx.
* **Embeddings**:

  * Delaj **batch** (lahko z asink. queue jobs), ali najprej vnesi `embedding=NULL`, nato zaženite “repair” job, ki v ozadju zapolni vektorje (izogneš se time-outom in OpenAI kvoti).
  * Če je kvota problem: uporabi **lokalni embedding model** (Ollama, bge-small).
* **Indeksi**: IVFFLAT (`CREATE INDEX ... USING ivfflat`) ustvari **po večjem polnjenju**, ne prej.
* **Disk**: za vso SI/EU zakonodajo računaj na nekaj 100 MB \~ par GB (odvisno od števila chunkov).
* **Idempotentnost**: hash besedila + law\_id + čl. št. → brez podvajanja.

---

## Vzdrževanje (delta ingest)

* Enkrat na dan/tedensko poženi:

  ```bash
  php artisan pisrs:ingest-all --updated-after=2025-08-01
  ```
* Za vsako posodobljen akt ponovno preberi vsebino in **prepiši samo spremenjene** člene (hash se bo razlikoval) – ostali ostanejo nedotaknjeni.
* Po večjem uvozu:

  ```sql
  CREATE INDEX law_chunks_vec_idx ON law_chunks
  USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
  ```

---

## Če API ni takoj na voljo

* Pazi na **pogoje uporabe** in *robots.txt*. Za produkcijo raje API.

---

### Kaj dobiš s tem pristopom

* “One-click” **FULL ingest** (z `pisrs:ingest-all`).
* **DELTA ingest** (z `--updated-after`).
* Idempotentno shranjevanje členov (brez duplikatov).
* Možnost, da embeddings delaš **asinhrono** (če želiš) in s tem obvladaš kvote/stroške.

Če želiš, ti dodam še **queue job** za embeddings (da bo vsak člen embed-an v ozadju) in **repair command** za `embedding IS NULL`.

