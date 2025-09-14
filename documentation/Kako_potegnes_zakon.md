Odlično—gremo konkretno: pokazal ti bom **dva praktična načina** kako “potegneš” zakon (npr. ZDR-1) in ga razbiješ na **člene** ter shraniš v svojo bazo:

1. **A: prek uradnega PISRS API (priporočeno za produkcijo)**
2. **B: “fallback” HTML ingest (scrape javne strani) za hiter začetek**

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

# B) Fallback: HTML “ingest” (dela takoj brez API)

Če API še ne uporabljaš, lahko za začetek prebereš **javno stran akta** in jo razrežeš po **ključu “člen”**. (Upoštevaj pogoje uporabe in *robots.txt*.)

1. Namesti parser:

```bash
composer require symfony/dom-crawler symfony/css-selector
```

2. Ustvari servis, ki prenese HTML in ga razreže na člene (robustno, z rezervo):

```php
<?php
// app/Services/PisrsHtml.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class PisrsHtml
{
    public function fetchActText(string $id): array
    {
        $url = "https://pisrs.si/pregledPredpisa?id={$id}";
        $html = Http::get($url)->body();
        $crawler = new Crawler($html);

        // naslov akta (fallback na <title>)
        $title = trim($crawler->filter('h1, .naslov, title')->first()->text('')) ?: $id;

        // 1) poskusi členovne blok elemente, če obstajajo:
        $articles = [];
        $crawler->filter('article, .clen, .cleni, .article')->each(function(Crawler $node) use (&$articles) {
            $text = trim($node->text(''));
            if ($text) $articles[] = $text;
        });

        $textAll = '';
        if (!$articles) {
            // 2) fallback: vzemi glavno vsebino/tekst dokumenta
            $textAll = $crawler->filter('main, .content, #content, body')->first()->text('');
        }

        // Normalizacija in rezanje na člene
        $chunks = [];
        if ($articles) {
            foreach ($articles as $raw) {
                // poskusi ujeti "123. člen" ali "Člen 123"
                if (preg_match('/^\s*(\d+)\.\s*člen\b/i', $raw, $m)) {
                    $no = $m[1];
                    $chunks[] = ['article_no'=>$no, 'text'=>trim($raw)];
                } else {
                    // če ni glave, poskusi izluščiti številko iz začetka
                    if (preg_match('/\bčlen\s*(\d+)\b/i', $raw, $m)) {
                        $chunks[] = ['article_no'=>$m[1], 'text'=>trim($raw)];
                    }
                }
            }
        } else {
            // Regex split na osnovi "N. člen" (zadrži naslov člena)
            $parts = preg_split('/(?=\n?\s*\d+\.\s*člen\b)/iu', $textAll);
            foreach ($parts as $p) {
                $p = trim($p);
                if (!$p) continue;
                if (preg_match('/^(\d+)\.\s*člen\b(.*)$/isu', $p, $m)) {
                    $chunks[] = ['article_no'=>$m[1], 'text'=>trim("{$m[1]}. člen ".$m[2])];
                }
            }
        }

        return [
            'title' => $title,
            'url'   => $url,
            'articles' => $chunks
        ];
    }
}
```

3. Artisan ukaz, ki uporabi HTML fallback in shrani člene:

```php
<?php
// app/Console/Commands/PisrsIngestHtml.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PisrsHtml;
use App\Services\RagService;

class PisrsIngestHtml extends Command
{
    protected $signature = 'pisrs:ingest-html {id : PISRS id, npr. ZAKO3850} {--tenant=PUBLIC}';
    protected $description = 'Uvozi akt iz PISRS HTML strani in shrani člene v bazo';

    public function handle(PisrsHtml $svc, RagService $rag)
    {
        $id = $this->argument('id');
        $tenant = $this->option('tenant');

        $doc = $svc->fetchActText($id);
        $title = $doc['title'] ?: $id;
        $url   = $doc['url'];

        if (empty($doc['articles'])) {
            $this->warn('Ni bilo mogoče razrezati na člene — preveri HTML selektorje/regex.');
            return self::FAILURE;
        }

        foreach ($doc['articles'] as $art) {
            if (!($art['article_no'] ?? null) || !($art['text'] ?? null)) continue;
            $rag->upsertChunk([
                'tenant_id' => $tenant,
                'source'    => 'PISRS',
                'title'     => $title,
                'citation'  => "člen {$art['article_no']}",
                'url'       => $url,
                'text'      => $art['text'],
            ]);
            $this->info("OK: {$title} člen {$art['article_no']}");
        }

        $this->info('✅ Končano (HTML fallback).');
        return self::SUCCESS;
    }
}
```

Zdaj lahko poženeš (primer za ZDR-1):

```bash
php artisan pisrs:ingest-html ZAKO3850
# ali, ko boš imela API:
php artisan pisrs:ingest-api ZAKO3850
```

**Kje dobiš ID akta?**
Na PISRS je to parameter `id` v URL (npr. `.../pregledPredpisa?id=ZAKO3850`). ([pisrs.si][3])

---

## EU pravo (EUR-Lex) – na kratko

* EUR-Lex ima uradni **Webservice (SOAP)**: registriraš se, poiščeš dokumente po **CELEX** in dobiš XML. ([EUR-Lex][2])
* V praksi narediš mali “adapter”: *search by CELEX* → prenesi HTML/XML → razreži na **člene** podobno kot zgoraj → shrani v svojo bazo.

---

## Kaj ti to prinese danes

* **Danes** lahko že z “HTML fallback” vneseš 1–2 ključna zakona (ZDR-1, OZ …) in dobiš **realne odgovore s citati**.
* **Jutri** preklopiš na **uradni PISRS API** (stabilno, čisto).
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

## kaj pa če hočem vse zakone s PISRS-a prenesti in shraniti vse člene?

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

## kaj pa če odvetnika zanima nek drug zakon, ki ga ni v moji podatkovni bazi?

Odlično vprašanje. Rešitev je: **“on-demand (JIT) uvoz”** – ko sistem za neko vprašanje ne najde dovolj konteksta, poskusi **takoj** pridobiti manjkajoč zakon, ga razreže na člene in odgovori. Ni ti treba vnaprej imeti *vseh* zakonov.

# Kako to deluje (praktično)

1. Uporabnik vpraša za zakon, ki ga še nimaš v bazi.
2. Tvoj API preveri rezultate iskanja (BM25/vektor). Če **ni dovolj relevantnih chunkov** (npr. score < 0.75 ali 0 zadetkov), sproži **JIT uvoz**:

   * poskusi po **PISRS ID** (če ga prepoznaš v vprašanju ali ga uporabnik poda),
   * ali po **URL** (če ti ga uporabnik prilepi),
   * ali naredi **HTML fallback** parsanje strani akta (če API ni na voljo).
3. Po uspešnem uvozu: **ponovi iskanje** in vrni odgovor z novimi citati.
4. Če uvoz ne uspe: vrni prijazen “Nimam tega akta. Prilepite PISRS URL ali ID (npr. ZAKO3850) in ga dodam.”

# UX predlog (jasen in prijazen)

* Ko ni konteksta, prikaži v UI:

  * “Tega zakona še nimamo v indeksu.”
  * polje za **PISRS ID ali URL**, gumb **“Dodaj zakon”**,
  * in inline info: “Podprti viri: PISRS, EUR-Lex. Po dodajanju poskusi znova.”

# Minimalni “JIT ingest” v Laravelu

## 1) Dodaj “relevance” prag v AskController

Če ni dovolj relevantnih zadetkov, pokliči JIT ingest in takoj ponovi iskanje.

```php
// app/Http/Controllers/AskController.php
$chunks = $mode === 'vector'
    ? $rag->vectorSearch($data['q'], $tenantId, 6)
    : $rag->hybridSearch($data['q'], $tenantId, 6, 100);

// prag relevantnosti
$minScore = 0.75;
$relevant = collect($chunks)->filter(fn($c) => ($c['score'] ?? 0) >= $minScore)->values()->all();

if (empty($relevant)) {
    // poskusi JIT ingest (če v besedilu najde PISRS ID ali URL)
    $added = app(\App\Services\JitIngest::class)->tryFromQuestion($data['q'], $tenantId);

    if ($added) {
        // ponovi iskanje
        $chunks = $rag->hybridSearch($data['q'], $tenantId, 6, 100);
        $relevant = collect($chunks)->filter(fn($c)=>($c['score']??0) >= $minScore)->values()->all();
    }
}

if (empty($relevant)) {
    return response()->json([
        'answer' => 'Tega akta še nimam v indeksu. Prilepi PISRS URL ali ID (npr. ZAKO3850) in ga dodam.',
        'citations' => []
    ]);
}

$answer = $rag->answer($data['q'], $relevant);
$cites = collect($relevant)->take(4)->map(fn($c)=>[
    'title'=>$c['title'],'citation'=>$c['citation'],'url'=>$c['url']
])->values();

return response()->json(['answer'=>$answer, 'citations'=>$cites]);
```

## 2) JIT servis: poskuša izluščiti PISRS ID/URL in uvoziti

Uporabiš obstoječi **PisrsApi** (API varianta) ali **PisrsHtml** (HTML fallback).

```php
// app/Services/JitIngest.php
namespace App\Services;

class JitIngest
{
    public function __construct(
        private PisrsApi $api,          // ali PisrsHtml $html
        private IngestService $ingest
    ) {}

    public function tryFromQuestion(string $q, string $tenantId = 'PUBLIC'): bool
    {
        // 1) PISRS ID?
        if (preg_match('/\b(ZAKO[0-9]+)\b/i', $q, $m)) {
            return $this->ingestAct($m[1], $tenantId);
        }

        // 2) URL?
        if (preg_match('#https?://[^\s]+pisrs[^\s]+id=([A-Z0-9]+)#i', $q, $m)) {
            return $this->ingestAct($m[1], $tenantId);
        }

        return false;
    }

    private function ingestAct(string $id, string $tenantId): bool
    {
        try {
            $act = $this->api->getActWithArticles($id); // ali $this->html->fetchActText($id)
        } catch (\Throwable $e) {
            \Log::warning("JIT ingest fail for {$id}: ".$e->getMessage());
            return false;
        }

        $this->ingest->upsertSource([
            'id' => $act['id'],
            'title' => $act['title'],
            'url' => $act['url'],
            'last_modified' => $act['last_modified'] ?? null,
            'version_tag' => $act['version_tag'] ?? null,
        ]);

        foreach (($act['articles'] ?? []) as $art) {
            $this->ingest->upsertArticleChunk($tenantId, $act, $art);
        }
        return true;
    }
}
```

## 3) Ročni endpoint “Dodaj zakon”

Če odvetnik prilepi URL/ID, lahko pokličeš to ruto:

```php
// routes/api.php
Route::post('/add-source', [\App\Http\Controllers\AddSourceController::class, '__invoke']);

// app/Http/Controllers/AddSourceController.php
public function __invoke(\Illuminate\Http\Request $r, \App\Services\JitIngest $jit) {
    $data = $r->validate(['id'=>'nullable|string', 'url'=>'nullable|url', 'tenant'=>'nullable|string']);
    $tenant = $data['tenant'] ?? 'PUBLIC';
    $q = trim(($data['id'] ?? '') . ' ' . ($data['url'] ?? ''));

    $ok = $jit->tryFromQuestion($q, $tenant);
    return $ok
      ? response()->json(['ok'=>true, 'message'=>'Zakon dodan.'])
      : response()->json(['ok'=>false, 'message'=>'Ne najdem PISRS ID v podanem nizu.'], 400);
}
```

# Varnost, stroški in pravila

* **Rate limiting**: zaščiti JIT (npr. max 3 JIT uvozi/min na tenant), da ti ne “zaspammajo” ingest.
* **Legalno**: upoštevaj pogoje PISRS (za produkcijo raje API kot scraping).
* **Embeddings kvota**: če ostaneš brez kvote, `upsertArticleChunk()` pusti `embedding=NULL` in kasneje poženi “repair” ukaz.
* **Kakovost**: vedno odgovarjaj *izključno na podlagi virov*; če vir ni najden ali je prekratek → izrecno povej.

# Kaj ti to prinese

* Nimaš več pritiska “vse vnesti vnaprej”.
* Odvetnik lahko **takoj dela** z zakoni, ki jih rabi – sistem jih po potrebi doda.
* Sprotno gradnje “PUBLIC indeksa”: sčasoma imaš vedno več pokritih aktov.

Če želiš, ti lahko pripravim še **majhen UI modal** “Dodaj zakon (PISRS ID/URL)” in “ponovi vprašanje po uvozu”, da bo izkušnja res gladka.

super — tukaj je mini UI (Blade + vanilla JS), ki doda gumb “Dodaj zakon”, odpre modal za **PISRS ID ali URL**, pošlje na `/api/add-source`, in nato **samodejno ponovno izvede vprašanje** na `/api/ask`.

---

# 1) Route za dodajanje vira (API)

`routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AddSourceController;
use App\Http\Controllers\AskController;

Route::post('/ask', AskController::class);

// dodaj vir (PISRS ID ali URL)
Route::post('/add-source', AddSourceController::class);
```

`app/Http/Controllers/AddSourceController.php`

```php
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\JitIngest;

class AddSourceController extends Controller
{
    public function __invoke(Request $r, JitIngest $jit)
    {
        $data = $r->validate([
            'id'    => 'nullable|string',
            'url'   => 'nullable|url',
            'tenant'=> 'nullable|string'
        ]);
        $tenant = $data['tenant'] ?? 'PUBLIC';
        $q = trim(($data['id'] ?? '').' '.($data['url'] ?? ''));

        $ok = $jit->tryFromQuestion($q, $tenant);

        return $ok
            ? response()->json(['ok'=>true,'message'=>'Zakon dodan.'])
            : response()->json(['ok'=>false,'message'=>'Ne najdem PISRS ID v podanem nizu. Uporabi npr. ZAKO3850 ali prilepi PISRS URL.'], 400);
    }
}
```

(Če že imaš `JitIngest`, ni treba nič menjati.)

---

# 2) Preprost demo UI z modalom (Blade)

`resources/views/ask.blade.php`

```html
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <title>AI pravni asistent</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem}
    .btn{background:#2563eb;color:#fff;border:0;border-radius:.5rem;padding:.6rem .9rem;cursor:pointer}
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .link{color:#2563eb;text-decoration:none}
    .muted{color:#666;font-size:.9rem}
    .row{display:flex;gap:.5rem}
    .input{flex:1;border:1px solid #ddd;border-radius:.5rem;padding:.6rem}
    .card{border:1px solid #eee;border-radius:.75rem;padding:1rem;margin-top:1rem;box-shadow:0 1px 6px rgba(0,0,0,.05)}
    /* modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;padding:1rem}
    .modal{background:#fff;border-radius:.9rem;max-width:520px;width:100%;padding:1rem;border:1px solid #eee}
    .modal h3{margin:.2rem 0 1rem}
    .modal .row{margin-top:.5rem}
  </style>
</head>
<body>
  <h1>AI pravni asistent</h1>
  <p class="muted">Odgovarja izključno na podlagi virov (PISRS/EUR-Lex). Če vira ni, ga lahko dodaš.</p>

  <form id="ask-form" class="row" onsubmit="return ask(event)">
    <input id="q" class="input" placeholder="Vnesi vprašanje (npr. Kateri člen ureja invalidnost?)">
    <button id="ask-btn" class="btn">Vprašaj</button>
    <button type="button" class="btn" style="background:#0ea5e9" onclick="openModal()">Dodaj zakon</button>
  </form>

  <div id="status" class="muted" style="margin-top:.5rem"></div>

  <div id="answer" class="card" style="display:none">
    <h3>Odgovor</h3>
    <div id="answer-text"></div>
    <h4 style="margin-top:1rem">Citati</h4>
    <ul id="cites" style="padding-left:1.1rem"></ul>
  </div>

  <!-- Modal za dodajanje zakona -->
  <div id="modal" class="modal-backdrop">
    <div class="modal">
      <h3>Dodaj zakon (PISRS)</h3>
      <p class="muted">Vnesi <b>PISRS ID</b> (npr. <code>ZAKO3850</code>) ali prilepi <b>PISRS URL</b> (npr. <code>https://www.pisrs.si/Pis.web/pregledPredpisa?id=ZAKO3850</code>).</p>
      <div class="row">
        <input id="pisrs-id" class="input" placeholder="PISRS ID (npr. ZAKO3850)">
      </div>
      <div class="row">
        <input id="pisrs-url" class="input" placeholder="PISRS URL (opcijsko)">
      </div>
      <div class="row" style="justify-content:flex-end;margin-top:1rem">
        <button class="btn" type="button" style="background:#6b7280" onclick="closeModal()">Prekliči</button>
        <button class="btn" type="button" id="add-btn" onclick="addSource()">Dodaj</button>
      </div>
      <div id="add-status" class="muted" style="margin-top:.5rem"></div>
    </div>
  </div>

<script>
const api = (path, body) =>
  fetch(path, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(body || {})
  });

function setStatus(msg){ document.getElementById('status').textContent = msg || ''; }
function showAnswer(ans, cites){
  const a = document.getElementById('answer');
  document.getElementById('answer-text').textContent = ans || '';
  const ul = document.getElementById('cites'); ul.innerHTML = '';
  (cites||[]).forEach(c=>{
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = c.url; a.textContent = `${c.title} — ${c.citation}`; a.target = '_blank';
    li.appendChild(a); ul.appendChild(li);
  });
  document.getElementById('answer').style.display = 'block';
}

async function ask(e){
  e.preventDefault();
  const q = document.getElementById('q').value.trim();
  if(!q) return;
  setStatus('Iščem po virih ...');
  const btn = document.getElementById('ask-btn'); btn.disabled = true;

  try {
    const r = await api('/api/ask', { q, mode:'hybrid' });
    const j = await r.json();
    showAnswer(j.answer, j.citations);
    setStatus('');
  } catch (err) {
    setStatus('Napaka pri poizvedbi.');
  } finally {
    btn.disabled = false;
  }
}

// Modal controls
function openModal(){ document.getElementById('modal').style.display='flex'; document.getElementById('add-status').textContent=''; }
function closeModal(){ document.getElementById('modal').style.display='none'; }

async function addSource(){
  const id  = document.getElementById('pisrs-id').value.trim();
  const url = document.getElementById('pisrs-url').value.trim();
  const addBtn = document.getElementById('add-btn');
  const addStatus = document.getElementById('add-status');

  if(!id && !url){
    addStatus.textContent = 'Vnesi PISRS ID ali URL.'; return;
  }
  addBtn.disabled = true; addStatus.textContent = 'Dodajam zakon ...';

  try{
    const r = await api('/api/add-source', { id, url, tenant:'PUBLIC' });
    const j = await r.json();
    if(!r.ok || !j.ok){ addStatus.textContent = j.message || 'Dodajanje ni uspelo.'; addBtn.disabled = false; return; }
    addStatus.textContent = 'Zakon dodan. Ponovno iščem ...';

    // zapri modal po uspehu
    setTimeout(()=>{ closeModal(); }, 400);

    // avtomatsko ponovno vprašanje, če je polje Q izpolnjeno
    const q = document.getElementById('q').value.trim();
    if(q){
      setStatus('Zakon dodan – ponovno iščem ...');
      const r2 = await api('/api/ask', { q, mode:'hybrid' });
      const j2 = await r2.json();
      showAnswer(j2.answer, j2.citations);
      setStatus('');
    }
  }catch(e){
    addStatus.textContent = 'Napaka pri dodajanju.';
  }finally{
    addBtn.disabled = false;
  }
}
</script>
</body>
</html>
```

`routes/web.php`

```php
Route::get('/ask-demo', fn() => view('ask'));
```

Odpri v brskalniku: `http://localhost:8000/ask-demo`

---

# Kako deluje UX

* Vneseš vprašanje → `POST /api/ask` → vidiš odgovor + citate.
* Če vira ni, klikneš **Dodaj zakon**, vpišeš **PISRS ID/URL** → `POST /api/add-source` sproži **JIT ingest** → modal se zapre → UI **samodejno ponovno pošlje isto vprašanje** in prikaže nove citate.

---

# Optional (poliranje)

* Dodaš “spinner” (CSS), bolj vidno obarvanje citatov, preverjanje formata ID (regex `ZAKO\d+`), in throttling (npr. ne dovoli več kot 3 “Dodaj zakon” v minuti na tenant).
* Če uporabljaš **auth**: dodaj `Authorization: Bearer <token>` header v `fetch`.

Če želiš, ti še priredim **Vue 3 komponento** z enakim modalom (če imaš SPA) ali dodam **toastr** obvestila.


# Kako potegniti vse zakone?
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
