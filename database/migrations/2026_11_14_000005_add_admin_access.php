<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->timestamp('suspended_at')->nullable();
            $t->unsignedInteger('admin_disclosure_version')->nullable();
            $t->timestamp('admin_disclosure_accepted_at')->nullable();
        });
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id')->nullable()->index();
            $t->unsignedBigInteger('collection_id')->nullable()->index();
            $t->unsignedBigInteger('target_user_id')->nullable();
            $t->string('action', 60);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'suspended_at', 'admin_disclosure_version', 'admin_disclosure_accepted_at']));
    }
};
