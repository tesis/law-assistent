# AI Pravni Asistent (AI Law Assistant)

A Retrieval-Augmented Generation (RAG) application that lets users ask natural-language legal
questions in Slovenian and get answers grounded in actual legislation, with citations to the
specific articles (*členi*) they come from — not a hallucinated summary.

Built as an exploration of how to take a RAG pipeline from "toy demo" to something that handles
real-world messiness: inconsistent source HTML, long documents that don't fit in a context window,
and the need to always point back to a verifiable source.

> 🇸🇮 Domain content (legislation, prompts, UI copy) is in Slovenian, since the data source
> (PISRS — Slovenia's official register of legislation) is a Slovenian government system.

## What it does

1. **Ingests** full laws from [PISRS](https://pisrs.si) (Slovenia's official legal register) via its API,
   automatically resolving the latest consolidated version (*zadnje neuradno prečiščeno besedilo*) of an act.
2. **Parses** the raw HTML/PDF into individual articles (*členi*), handling the inconsistent
   structure of government-published legal text (multiple fallback strategies: PISRS-specific
   paragraph classes → headings → anchor IDs → plain-text regex).
3. **Embeds** each article with OpenAI embeddings and stores it in **Postgres + pgvector**,
   alongside a full-text search index.
4. **Answers questions** using hybrid retrieval (BM25 full-text search → vector re-ranking),
   feeds the top-matching articles to an LLM with a strict "answer only from these sources,
   always cite the article number" system prompt, and returns an answer with inline citations
   and source links — with a relevance-score cutoff so it refuses to answer when nothing in the
   database is actually relevant, instead of making something up.
5. **Summarizes** an entire act on demand, optionally focused on a topic (e.g. "termination of
   employment"), with the summary cached and automatically invalidated when the underlying
   articles change.

## Why it's interesting (engineering-wise)

- **Hybrid search, not just vector search** — BM25 candidate generation followed by vector
  re-ranking (`RagService::hybridSearch`), which is noticeably more precise on short, keyword-heavy
  legal queries than pure cosine similarity.
- **Grounded answers with a refusal path** — a relevance threshold filters out weak matches
  before they ever reach the LLM, and the system prompt forces citations; if the model call fails,
  the app degrades gracefully to returning the raw matched citations instead of a blank error.
- **Token-budget-aware context building** — `RagService::answer()` packs retrieved chunks into
  a character budget, trimming the last chunk instead of dropping it, so prompts stay inside the
  model's context window without an arbitrary chunk-count cutoff.
- **Resilient HTML parsing** — `ArticleExtractor` walks the actual PISRS paragraph-class structure
  (`clen`, `odstavek`, `stevilcna_tocka`, …) first, and falls back through headings → anchor IDs →
  plain-text regex extraction for pages that don't follow the expected structure, with PDF text
  extraction as a last resort for acts only published that way.
- **Cache invalidation via fingerprinting** — law summaries are cached per (law, focus) pair and
  invalidated using a cheap `COUNT + MAX(updated_at)` fingerprint instead of a TTL, so a summary is
  only ever regenerated when its underlying source articles actually changed.
- **Multi-tenant-ready schema** — every chunk carries a `tenant_id` so the retrieval layer can
  scope a query to `PUBLIC` legislation plus a tenant's private documents, laying groundwork for
  per-client knowledge bases on the same index.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Database | PostgreSQL + [pgvector](https://github.com/pgvector/pgvector) (vector similarity + full-text search in one store) |
| Embeddings / LLM | OpenAI (`text-embedding-3-small`, `gpt-4o-mini`) |
| HTML/PDF parsing | Symfony DomCrawler, `smalot/pdfparser` |
| Frontend (demo UI) | Blade + Tailwind, Vite |
| Testing | PHPUnit |

## Architecture

```
PISRS API ──▶ PisrsApi ──▶ ArticleExtractor ──▶ RagService::upsertChunk ──▶ law_chunks (pgvector)
 (law text)   (fetch +      (HTML/PDF → per-     (embed + store)              │
               resolve       article text)                                    │
               latest ver.)                                                   │
                                                                                ▼
User question ──▶ AskController ──▶ RagService::hybridSearch ──▶ relevance filter
                                           (BM25 + vector)              │
                                                                         ▼
                                                            RagService::answer (LLM + citations)
                                                                         │
                                                                         ▼
                                                              JSON: { answer, citations[] }
```

**Key components:**

- `app/Services/PisrsApi.php` — client for the PISRS legal register API; resolves an act's latest
  consolidated version and fetches its HTML or PDF.
- `app/Services/ArticleExtractor.php` — splits raw HTML/PDF text into individual articles with
  multiple fallback parsing strategies.
- `app/Services/RagService.php` — embedding, hybrid search, and LLM answer generation.
- `app/Services/LawSummarizer.php` — builds and caches whole-act summaries, optionally focused
  on a topic.
- `app/Console/Commands/PisrsIngestApi.php` — CLI command that runs the full ingest pipeline for
  a given act (`php artisan pisrs:ingest-zbirka {id}`).
- `app/Http/Controllers/AskController.php` — the `/api/ask` Q&A endpoint.

## Getting started

### Requirements

- PHP 8.2+, Composer
- Node.js + npm
- PostgreSQL with the `pgvector` extension (a ready-made image is provided via Docker Compose)
- An OpenAI API key

### Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Start Postgres with pgvector:

```bash
docker compose up -d
```

Fill in your `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=appdb
DB_USERNAME=app
DB_PASSWORD=app

OPENAI_API_KEY=sk-...

PISRS_BASE_URL=https://pisrs.si/api
PISRS_API_TOKEN=...
PISRS_API_KEY=...
```

Run migrations (creates the `pgvector` extension and the `law_chunks` / `law_summaries` tables):

```bash
php artisan migrate
```

### Loading data

Seed a handful of demo articles:

```bash
php artisan law:seed
```

Or ingest a full act from PISRS by its MOPED id (found in the act's PISRS URL, e.g. `ZAKO3850`
for the Employment Relationships Act):

```bash
php artisan pisrs:ingest-zbirka ZAKO3850
```

### Running it

```bash
php artisan serve
npm run dev
```

- Demo Q&A UI: `http://localhost:8000/ask-demo`
- Browse an act's articles: `http://localhost:8000/laws/{mopedId}?q=odpoved`
- Ask via API:

  ```bash
  curl -X POST http://localhost:8000/api/ask \
    -H "Content-Type: application/json" \
    -d '{"q": "Kateri člen ureja redno odpoved pogodbe o zaposlitvi?"}'
  ```

- Summarize an act via API: `GET /api/laws/{mopedId}/summary?focus=odpoved`

### Tests

```bash
php artisan test
```

## Project status

This is a working prototype / portfolio project, not a production system. Known rough edges:

- No authentication/authorization on the API yet — `tenant_id` scoping exists in the schema and
  retrieval layer but isn't wired to a real auth system.
- Test coverage focuses on the trickiest part of the pipeline (HTML article extraction); the ask/
  retrieval flow doesn't yet have integration tests against a live-ish database.

## License

No license specified yet — all rights reserved by the author unless/until one is added.
