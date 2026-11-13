<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_word', function (Blueprint $t) {
            $t->timestamp('added_at')->nullable();
        });
        Schema::create('collection_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['collection_id', 'user_id']);
        });
        Schema::create('collection_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('word_id')->constrained()->cascadeOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['collection_id', 'user_id', 'word_id']);
        });
    }

    public function down(): void
    {
        Schema::table('collection_word', fn (Blueprint $t) => $t->dropColumn('added_at'));
        Schema::dropIfExists('collection_notes');
        Schema::dropIfExists('collection_members');
    }
};
