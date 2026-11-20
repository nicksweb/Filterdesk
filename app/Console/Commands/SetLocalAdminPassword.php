<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** Recover emergency local access from the host: docker compose exec app php artisan itrequests:admin-password you@school */
class SetLocalAdminPassword extends Command
{
    protected $signature = 'itrequests:admin-password {email} {--enable-local-login : Also re-enable emergency local sign-in}';

    protected $description = 'Set the emergency local sign-in password for an administrator';

    public function handle(AuditLogger $audit, SettingsRepository $settings): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();

        if (! $user || ! $user->isAdmin()) {
            $this->error('No administrator account exists with that email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (minimum 14 characters)');
        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('Passwords did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:14']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'enabled' => true, 'password_login_enabled' => true])->save();
        RateLimiter::clear('login-account:'.sha1($user->email));
        if ($this->option('enable-local-login')) {
            $settings->set('auth.emergency_local_login', true);
        }
        $audit->log('user.local_password_set_cli', $user);

        $this->info("Password updated for {$user->email}.");
        if (! $settings->get('auth.emergency_local_login', true)) {
            $this->warn('Emergency local sign-in is disabled. Re-run with --enable-local-login to use it.');
        }

        return self::SUCCESS;
    }
}
