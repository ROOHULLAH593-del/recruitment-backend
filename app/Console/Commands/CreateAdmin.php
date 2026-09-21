<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('admin:create {--name= : Full name} {--email= : Login email} {--password= : Password (min 12 chars, mixed case, with a number)} {--force : Create an additional admin even though one already exists}')]
#[Description('Create the first admin account on a fresh database (bootstrap-only unless --force is given)')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     *
     * Deliberately non-interactive (everything comes from options) so it can
     * run as a one-off command on hosts without a real shell.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        // Checked before anything else so a refused run reveals nothing about
        // the supplied values and never touches the database.
        if (! $force && User::where('role', UserRole::Admin)->exists()) {
            $this->error('An admin account already exists. This command only bootstraps the first admin; re-run with --force to create another.');

            return self::FAILURE;
        }

        $input = [
            'name' => $this->option('name'),
            'email' => $this->option('email'),
            'password' => $this->option('password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // Stricter than the app-wide Password::defaults() used for
            // candidates — this account can do everything.
            'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $admin = User::create([
            'name' => trim($input['name']),
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => UserRole::Admin,
        ]);

        $this->info("Admin account created: {$admin->email}");

        if ($force) {
            $this->warn('--force was used: there may now be more than one admin account.');
        }

        return self::SUCCESS;
    }
}
