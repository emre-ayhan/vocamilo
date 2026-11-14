<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_pools', fn (Blueprint $t) => $t->string('text_direction', 3)->default('ltr'));
        Schema::table('words', fn (Blueprint $t) => $t->string('text_direction', 3)->default('ltr'));
    }

    public function down(): void
    {
        Schema::table('word_pools', fn (Blueprint $t) => $t->dropColumn('text_direction'));
        Schema::table('words', fn (Blueprint $t) => $t->dropColumn('text_direction'));
    }
};
