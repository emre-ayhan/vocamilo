<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_pools', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('language', 35);
            $t->timestamps();
        });
        Schema::create('pool_word', function (Blueprint $t) {
            $t->foreignId('word_pool_id')->constrained('word_pools')->cascadeOnDelete();
            $t->foreignId('word_id')->constrained()->cascadeOnDelete();
            $t->primary(['word_pool_id', 'word_id']);
            $t->index('word_id');
        });
        Schema::table('collections', fn (Blueprint $t) => $t->string('voice_language', 35)->nullable());
    }

    public function down(): void
    {
        Schema::table('collections', fn (Blueprint $t) => $t->dropColumn('voice_language'));
        Schema::dropIfExists('pool_word');
        Schema::dropIfExists('word_pools');
    }
};
