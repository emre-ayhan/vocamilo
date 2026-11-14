<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical consent covered progress only, not owner email search.
        Schema::table('collection_members', fn (Blueprint $t) => $t->unsignedInteger('progress_consent_version')->default(1));
    }

    public function down(): void
    {
        Schema::table('collection_members', fn (Blueprint $t) => $t->dropColumn('progress_consent_version'));
    }
};
