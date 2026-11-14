<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_word_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('word_id')->constrained()->cascadeOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'word_id']);
        });
        // Latest timestamp wins; highest id breaks ties. Null is an intentional clear.
        // Keep every legacy collection_notes row unchanged as a conflict archive.
        foreach (DB::table('collection_notes')->orderByDesc('updated_at')->orderByDesc('id')->cursor() as $row) {
            DB::table('user_word_notes')->insertOrIgnore([
                'user_id' => $row->user_id, 'word_id' => $row->word_id,
                'note' => $row->note, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        // Legacy archived values remain; post-migration global edits require a backup before rollback.
        Schema::dropIfExists('user_word_notes');
    }
};
