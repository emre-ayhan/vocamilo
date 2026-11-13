<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->string('mode', 16);
            $t->json('settings');
            $t->boolean('practice_round')->default(false);
            $t->json('questions');
            $t->unsignedInteger('total_count');
            $t->unsignedInteger('correct_count')->nullable();
            $t->unsignedInteger('self_assessed_count')->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['collection_id', 'completed_at']);
        });
        Schema::create('study_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('study_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('word_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('position');
            $t->text('prompt');
            $t->text('expected');
            $t->text('answer')->nullable();
            $t->boolean('correct');
            $t->boolean('self_assessed')->default(false);
            $t->unique(['study_session_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_answers');
        Schema::dropIfExists('study_sessions');
    }
};
