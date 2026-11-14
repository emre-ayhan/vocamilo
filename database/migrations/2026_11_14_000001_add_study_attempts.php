<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $t) {
            $t->unsignedTinyInteger('rules_version')->default(1);
            $t->unsignedInteger('total_attempts')->nullable();
        });
        Schema::table('study_answers', function (Blueprint $t) {
            $t->unsignedInteger('question_index')->nullable();
            $t->unsignedInteger('attempt_no')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('study_answers', fn (Blueprint $t) => $t->dropColumn(['question_index', 'attempt_no']));
        Schema::table('study_sessions', fn (Blueprint $t) => $t->dropColumn(['rules_version', 'total_attempts']));
    }
};
