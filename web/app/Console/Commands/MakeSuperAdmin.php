<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class MakeSuperAdmin extends Command
{
    protected $signature = 'dmarc:make-super-admin {email?} {--name=} {--password-stdin : Read the password from standard input (for unattended installs)}';

    protected $description = 'Create (or promote) an MSP super-admin user';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text('Email', required: true);
        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::firstWhere('email', $email);
        if ($user) {
            $user->forceFill(['is_super_admin' => true])->save();
            $this->info("Promoted {$email} to super-admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?? text('Name', required: true);
        if ($this->option('password-stdin')) {
            $pass = rtrim((string) fgets(STDIN), "\r\n");
            if (strlen($pass) < 12) {
                $this->error('The password must be at least 12 characters.');

                return self::FAILURE;
            }
        } else {
            $pass = password('Password (min 12 characters)', required: true, validate: fn (string $v) => strlen($v) < 12 ? 'Use at least 12 characters.' : null);
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $pass]);
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        $this->info("Created super-admin {$email}. You will be asked to set up two-factor authentication at first login.");

        return self::SUCCESS;
    }
}
