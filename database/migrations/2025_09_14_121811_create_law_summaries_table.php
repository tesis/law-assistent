<?php

// database/migrations/2025_09_14_000000_create_law_summaries_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('law_summaries', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('law_id', 64)->index();
            $t->string('focus', 255)->nullable()->index(); // npr. "odpoved" (fokusni povzetek)
            $t->text('summary');                            // besedilo povzetka (Markdown/Plain)
            $t->string('fingerprint', 120)->nullable();     // za invalidacijo (npr. count:maxUpdatedAt)
            $t->integer('tokens_used')->nullable();
            $t->timestamps();
            $t->unique(['law_id','focus']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('law_summaries');
    }
};
