<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of network the tool manages, such as Staff, Students, CCTV or
 * Exam rooms. Each type has its own shared allow and block lists; every
 * filter policy of that type inherits them. Requests target one or more
 * types. The key is stable; the name can change freely.
 */
class NetworkType extends Model
{
    protected $fillable = ['key', 'name', 'description', 'requestable', 'auto_approve', 'sort_order'];

    /** @var array<string, self>|null */
    private static ?array $byKey = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::$byKey = null);
        static::deleted(fn () => self::$byKey = null);
    }

    protected function casts(): array
    {
        return ['requestable' => 'boolean', 'auto_approve' => 'boolean'];
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function filterPolicies(): HasMany
    {
        return $this->hasMany(FilterPolicy::class, 'audience', 'key');
    }

    /** Cached lookup by key, for labels. */
    public static function byKey(string $key): ?self
    {
        self::$byKey ??= static::query()->get()->keyBy('key')->all();

        return self::$byKey[$key] ?? null;
    }

    public static function flushCache(): void
    {
        self::$byKey = null;
    }

    /** A display name for a key, even one that no longer exists. */
    public static function labelFor(?string $key): string
    {
        if ($key === null || $key === '') {
            return 'No type';
        }

        return self::byKey($key)?->name ?? ucfirst(str_replace(['-', '_'], ' ', $key));
    }

    /** @return array<string, string> key => name, in display order */
    public static function options(): array
    {
        return static::query()->ordered()->pluck('name', 'key')->all();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return static::query()->ordered()->pluck('key')->all();
    }

    /** Make sure a type exists for a key, creating it with a readable name if needed. */
    public static function ensure(string $key, ?string $name = null): self
    {
        $type = static::query()->firstOrCreate(['key' => $key], [
            'name' => $name ?? ucfirst(str_replace(['-', '_'], ' ', $key)),
            'requestable' => false,
            'sort_order' => (int) static::query()->max('sort_order') + 1,
        ]);
        self::$byKey = null;

        return $type;
    }

    public function inUse(): bool
    {
        return FilterPolicy::query()->where('audience', $this->key)->exists()
            || PolicyList::query()->where('audience', $this->key)->exists()
            || NetworkProfile::query()->where('audience', $this->key)->exists();
    }
}
