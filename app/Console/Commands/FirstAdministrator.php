<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FirstAdministrator extends Command
{
    protected $signature = 'vocamilo:first-admin {email : Exact email of an existing account}';

    protected $description = 'Explicitly grant the first administrator role from the trusted server CLI';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive confirmation is required.');

            return self::FAILURE;
        }
        if (User::where('is_admin', true)->exists()) {
            $this->error('An administrator already exists. No role was changed.');

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || $user->suspended_at) {
            $this->error('An active existing account with that exact email is required.');

            return self::FAILURE;
        }
        if (! $this->confirm('Grant administrator access to '.$user->email.'? This permits account management and collection viewing, never private notes.')) {
            return self::FAILURE;
        }

        return DB::transaction(function () use ($user) {
            // Serialize first-admin grants, including competing CLI processes.
            $users = User::orderBy('id')->lockForUpdate()->get(['id', 'is_admin', 'suspended_at']);
            if ($users->contains('is_admin', true) || $users->firstWhere('id', $user->id)?->suspended_at) {
                $this->error('Account state changed. No role was changed.');

                return self::FAILURE;
            }
            $user->forceFill(['is_admin' => true])->save();
            AdminAudit::record($user->id, 'first_admin_granted', null, $user->id);
            $this->info('First administrator granted. Disclosure acceptance is still required.');

            return self::SUCCESS;
        });
    }
}
