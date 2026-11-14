<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('share_access', 16)->default('guests');
        });
        Schema::create('collection_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['collection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_blocks');
        Schema::table('collections', fn (Blueprint $table) => $table->dropColumn('share_access'));
    }
};
