<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_law_id_to_law_chunks.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('law_chunks', function (Blueprint $table) {
            // adjust length if you prefer
            $table->string('law_id', 64)->nullable()->index()->after('source');
        });
    }
    public function down(): void
    {
        Schema::table('law_chunks', function (Blueprint $table) {
            $table->dropColumn('law_id');
        });
    }
};
