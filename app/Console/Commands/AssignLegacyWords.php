<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignLegacyWords extends Command
{
    protected $signature = 'vocamilo:assign-legacy-words {email} {pool} {--apply}';

    protected $description = 'Preview or explicitly assign only one account’s unpooled words to an owned pool';

    public function handle(): int
    {
        return DB::transaction(function () {
            $email = (string) $this->argument('email');
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || $user->email !== $email || ! ctype_digit((string) $this->argument('pool'))) {
                $this->error('Exact existing account email and pool ID required.');

                return self::FAILURE;
            }
            $pool = $user->wordPools()->find($this->argument('pool'));
            if (! $pool) {
                $this->error('Pool must belong to the specified account.');

                return self::FAILURE;
            }
            $words = $user->words()->whereDoesntHave('pools');
            $count = $words->count();
            $this->info(($this->option('apply') ? 'Apply' : 'Dry run').': '.$count.' unpooled words for '.$email.' into pool '.$pool->id.' ('.$pool->name.').');
            if ($this->option('apply')) {
                $words->select('words.id')->chunkById(500, function ($chunk) use ($pool) {
                    $pool->words()->syncWithoutDetaching($chunk->pluck('id')->all());
                });
            }

            return self::SUCCESS;
        });
    }
}
