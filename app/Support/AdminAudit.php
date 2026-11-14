<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AdminAudit
{
    public static function record(?int $actor, string $action, ?int $collection = null, ?int $targetUser = null): void
    {
        DB::table('admin_audit_logs')->insert(['actor_id' => $actor, 'action' => $action, 'collection_id' => $collection, 'target_user_id' => $targetUser, 'created_at' => now()]);
    }
}
