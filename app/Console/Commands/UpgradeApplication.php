<?php

namespace App\Console\Commands;

use App\Services\Policy\PolicyBackfill;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Brings a deployed instance up to the version in this image. Run by the
 * one-shot `migrate` service on every `docker compose up`; safe to re-run.
 *
 *   1. Refuse versions too old to upgrade directly.
 *   2. Run migrations.
 *   3. Backfill defaults added by this release (policies, lists, entries).
 *   4. Clear compiled caches so stale views cannot survive an upgrade.
 *   5. Ask queue workers to restart onto the new code.
 *   6. Record the installed version.
 */
class UpgradeApplication extends Command
{
    protected $signature = 'itrequests:upgrade {--skip-migrations : Run everything except database migrations}';

    protected $description = 'Migrate, backfill and clear caches to finish an upgrade to this release';

    public function handle(SettingsRepository $settings): int
    {
        $target = (string) config('version.app');
        $key = (string) config('version.stored_version_key');
        $current = (string) $settings->get($key, '0.1.0');
        $this->line("IT Requests upgrade: <info>{$current}</info> → <info>{$target}</info>");

        if (version_compare($current, (string) config('version.min_upgrade_from'), '<')) {
            $this->error("This release cannot upgrade an instance on {$current} directly. See docs/UPGRADING.md.");

            return self::FAILURE;
        }

        $steps = [];
        if (! $this->option('skip-migrations')) {
            $steps['Running migrations'] = fn () => Artisan::call('migrate', ['--force' => true], $this->output);
        }
        $steps['Backfilling policy defaults'] = fn () => $settings->installed() ? $this->line('  '.app(PolicyBackfill::class)->run()) : $this->line('  Not installed yet; skipped.');
        $steps['Clearing compiled caches'] = function (): void {
            foreach (['view:clear', 'config:clear', 'route:clear', 'event:clear', 'cache:clear'] as $command) {
                Artisan::call($command, [], $this->output);
            }
        };
        $steps['Restarting queue workers'] = fn () => Artisan::call('queue:restart', [], $this->output);

        foreach ($steps as $label => $step) {
            $this->line("→ {$label}…");
            try {
                $step();
            } catch (Throwable $exception) {
                $this->error("{$label} failed: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($settings->installed()) {
            $settings->set($key, $target);
        }
        $this->info("Upgrade complete: {$target}.");

        return self::SUCCESS;
    }
}
