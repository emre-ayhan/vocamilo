<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_answers', function (Blueprint $t) {
            $t->boolean('had_error')->default(false);
            $t->boolean('skipped')->default(false);
        });
        Schema::table('study_sessions', fn (Blueprint $t) => $t->unsignedInteger('skipped_count')->default(0));
    }

    public function down(): void
    {
        Schema::table('study_answers', fn (Blueprint $t) => $t->dropColumn(['had_error', 'skipped']));
        Schema::table('study_sessions', fn (Blueprint $t) => $t->dropColumn('skipped_count'));
    }
};
