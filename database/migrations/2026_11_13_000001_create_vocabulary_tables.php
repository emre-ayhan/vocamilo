<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('words', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('term', 500);
            $t->text('definition');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'id']);
        });
        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->text('description')->nullable();
            $t->uuid('share_uuid')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('collection_word', function (Blueprint $t) {
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('word_id')->constrained()->cascadeOnDelete();
            $t->primary(['collection_id', 'word_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_word');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('words');
    }
};
