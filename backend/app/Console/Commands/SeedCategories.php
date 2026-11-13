<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DefaultCategories;
use Illuminate\Console\Command;

class SeedCategories extends Command
{
    protected $signature = 'finance:seed-categories';

    protected $description = 'Add the default income and expense categories for the owner (safe to run again)';

    public function handle(DefaultCategories $defaults): int
    {
        $user = User::query()->first();

        if ($user === null) {
            $this->error('No owner exists yet. Run finance:create-owner first.');

            return self::FAILURE;
        }

        $this->info('Added '.$defaults->seedFor($user).' categories.');

        return self::SUCCESS;
    }
}
