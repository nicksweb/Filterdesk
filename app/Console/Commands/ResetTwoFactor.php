<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\TwoFactor;
use Illuminate\Console\Command;

/** Recover an account whose phone is lost, from the host: docker compose exec app php artisan filterdesk:reset-two-factor you@school */
class ResetTwoFactor extends Command
{
    protected $signature = 'filterdesk:reset-two-factor {email}';

    protected $description = 'Turn off two-factor sign-in for an account (it is set up again at the next sign-in if required)';

    public function handle(TwoFactor $twoFactor, AuditLogger $audit): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user) {
            $this->error('No account exists with that email address.');

            return self::FAILURE;
        }
        $twoFactor->disable($user);
        $audit->log('auth.two_factor_reset', $user, ['by' => 'console']);
        $this->info("Two-factor sign-in reset for {$user->email}.");

        return self::SUCCESS;
    }
}
