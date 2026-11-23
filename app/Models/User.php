<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'oidc_subject', 'role', 'enabled', 'last_login_at', 'notify_new_requests', 'auth_source', 'password_login_enabled', 'network_types', 'department', 'sso_groups', 'name_locked'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Mirrors the column defaults so new, unsaved models behave the same. */
    protected $attributes = [
        'role' => 'teacher',
        'enabled' => true,
        'notify_new_requests' => true,
        'auth_source' => 'local',
        'password_login_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'enabled' => 'boolean',
            'password_login_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'notify_new_requests' => 'boolean',
            'network_types' => 'array',
            'sso_groups' => 'array',
            'name_locked' => 'boolean',
        ];
    }

    public function isIt(): bool
    {
        return in_array($this->role, ['it', 'admin'], true);
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /** @param Builder<self> $query */
    /**
     * The network types whose probes this user's website checks run on: a
     * student sees only their own level of access (their network types from
     * sign-in, otherwise Students); staff and IT see every network (null).
     *
     * @return list<string>|null
     */
    public function checkAudiences(): ?array
    {
        return $this->isStudent() ? (array_values(array_filter((array) $this->network_types)) ?: ['student']) : null;
    }

    /** Placeholder that keeps the requests of deleted users. */
    public const DELETED_EMAIL = 'deleted-user@filterdesk.invalid';

    public static function deletedPlaceholder(): self
    {
        return self::query()->firstOrCreate(['email' => self::DELETED_EMAIL], [
            'name' => 'Deleted user', 'role' => 'student', 'enabled' => false, 'auth_source' => 'deleted',
            'password' => str()->random(64), 'password_login_enabled' => false, 'notify_new_requests' => false,
        ]);
    }

    public function isDeletedPlaceholder(): bool
    {
        return $this->auth_source === 'deleted';
    }

    public function scopeItStaff(Builder $query): void
    {
        $query->whereIn('role', ['it', 'admin'])->where('enabled', true)->where('auth_source', '!=', 'preview')->orderBy('name');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
