<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RagService;

class LawRepairEmbeddings extends Command
{
    protected $signature = 'law:repair-embeddings {--limit=1000}';
    protected $description = 'Backfill NULL embeddings for law_chunks';

    public function handle(RagService $rag)
    {
        $limit = (int) $this->option('limit');
        $rows = \DB::table('law_chunks')->whereNull('embedding')->limit($limit)->get();
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
        $this->info('Done.');
    }
}
