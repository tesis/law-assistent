<?php
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

