<?php
// app/Console/Commands/PisrsIngestApi.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PisrsApi;
use App\Services\RagService;

class PisrsIngestApi1 extends Command
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
        $url   = $act['url']   ?? "https://pisrs.si/extapi/predpis/register-predpisov?mopedID={$id}";
        // logger(__METHOD__.' '.__LINE__."\n". print_r($act, true));
        // logger(__METHOD__.' '.__LINE__."\n". print_r($articles, true));

        if (!$articles) {
            $this->info("Ni artiklov: {$title} ");
            return;
        }
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
