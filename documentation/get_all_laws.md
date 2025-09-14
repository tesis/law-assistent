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

* Za prvo polnjenje lahko uporabiš **HTML fallback** (prejšnje sporočilo).
* Pazi na **pogoje uporabe** in *robots.txt*. Za produkcijo raje API.

---

### Kaj dobiš s tem pristopom

* “One-click” **FULL ingest** (z `pisrs:ingest-all`).
* **DELTA ingest** (z `--updated-after`).
* Idempotentno shranjevanje členov (brez duplikatov).
* Možnost, da embeddings delaš **asinhrono** (če želiš) in s tem obvladaš kvote/stroške.

Če želiš, ti dodam še **queue job** za embeddings (da bo vsak člen embed-an v ozadju) in **repair command** za `embedding IS NULL`.
