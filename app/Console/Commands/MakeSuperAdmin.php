<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class MakeSuperAdmin extends Command
{
    protected $signature = 'lookdo:make-super-admin
                            {email? : Super Admin e-mail}
                            {--name= : Display name}
                            {--password= : Password (omit to enter it securely)}';

    protected $description = 'Create or promote a LOOKDO Super Admin';

    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?: $this->ask('E-mail'));
        $name = (string) ($this->option('name') ?: $this->ask('Name', 'LOOKDO Admin'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        if (! $this->option('password')) {
            $confirmation = (string) $this->secret('Repeat password');

            if (! hash_equals($password, $confirmation)) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', Password::min(12)->letters()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'locale' => $user->locale ?: 'de',
            'is_active' => true,
            'is_super_admin' => true,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();

        $this->info("Super Admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
