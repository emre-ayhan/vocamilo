<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_members', fn (Blueprint $t) => $t->timestamp('progress_consented_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('collection_members', fn (Blueprint $t) => $t->dropColumn('progress_consented_at'));
    }
};
