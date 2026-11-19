<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Key/value settings stored as JSON. Keys may be exact ("auth.emergency_local_login")
 * or a path into a root object ("school.name" → the "school" row's "name").
 *
 * The whole table is cached as a plain array. Eloquent models are never put
 * in the cache, because the cache only unserializes plain values.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'settings.all.v2';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $all = $this->all();
        } catch (Throwable) {
            return $default;
        }

        if (array_key_exists($key, $all)) {
            return $all[$key] ?? $default;
        }

        if (str_contains($key, '.')) {
            [$root, $path] = explode('.', $key, 2);

            return is_array($all[$root] ?? null) ? data_get($all[$root], $path, $default) : $default;
        }

        return $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->forget();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->memo ??= Cache::remember(self::CACHE_KEY, 300, fn (): array => Setting::query()->pluck('value', 'key')->all());
    }

    public function forget(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    public function installed(): bool
    {
        try {
            return (bool) Setting::query()->where('key', 'installation.completed')->value('value');
        } catch (Throwable) {
            return false;
        }
    }

    /** Parse a wall-clock value entered in the school's timezone (e.g. datetime-local) into UTC. */
    public function parseLocal(?string $value): ?Carbon
    {
        return blank($value) ? null : Carbon::parse($value, $this->timezone())->utc();
    }

    /** The school's display timezone. Storage stays in UTC. */
    public function timezone(): string
    {
        $timezone = (string) $this->get('school.timezone', config('app.display_timezone') ?: 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
