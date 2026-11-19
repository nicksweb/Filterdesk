<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SettingsRepository
{
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $exact = Cache::remember("setting.{$key}", 300, fn () => Setting::query()->where('key', $key)->first());
            if ($exact) {
                return $exact->value ?? $default;
            }

            if (str_contains($key, '.')) {
                [$root, $path] = explode('.', $key, 2);
                $rootValue = Cache::remember("setting.{$root}", 300, fn () => Setting::query()->where('key', $root)->value('value'));

                return data_get($rootValue, $path, $default);
            }

            return $default;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
    }

    public function installed(): bool
    {
        try {
            return (bool) Setting::query()->where('key', 'installation.completed')->value('value');
        } catch (Throwable) {
            return false;
        }
    }
}
