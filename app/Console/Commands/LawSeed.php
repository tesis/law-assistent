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
