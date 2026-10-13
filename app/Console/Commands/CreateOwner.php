<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'finance:create-owner {name} {email} {--business= : Business name}';

    protected $description = 'Create the owner login and business profile (only works while no user exists)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An owner already exists. Registration is closed.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'name' => $this->argument('name'),
                'email' => $this->argument('email'),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($password) {
            $user = User::create([
                'name' => $this->argument('name'),
                'email' => $this->argument('email'),
                'password' => $password, // hashed by the model cast
            ]);

            $user->business()->create([
                'name' => $this->option('business') ?: 'My Phone Business',
            ]);
        });

        $this->info('Owner and business created. You can now log in.');

        return self::SUCCESS;
    }
}
