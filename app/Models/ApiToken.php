<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A token for the API. Only its SHA-256 hash is stored; the token itself is
 * shown once. It acts as the IT officer who created it, with the abilities
 * chosen: read (requests, versions, the explorer), requests (submit on
 * someone's behalf) and decide (approve or decline, two-person rules apply).
 */
class ApiToken extends Model
{
    public const ABILITIES = ['read' => 'Read requests, versions and access answers', 'requests' => 'Submit requests on behalf of someone', 'decide' => 'Approve and decline requests'];

    protected $fillable = ['user_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string} the token and its plain text, shown once
     */
    public static function issue(User $user, string $name, array $abilities, ?\DateTimeInterface $expires = null): array
    {
        $plain = 'fd_'.Str::random(48);
        $token = static::query()->create(['user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain), 'abilities' => array_values($abilities), 'expires_at' => $expires]);

        return [$token, $plain];
    }

    public static function findPlain(string $plain): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $plain))->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
