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

#[Fillable(['name', 'email', 'password', 'oidc_subject', 'role', 'enabled', 'last_login_at', 'notify_new_requests', 'auth_source'])]
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
            'last_login_at' => 'datetime',
            'notify_new_requests' => 'boolean',
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
    public function scopeItStaff(Builder $query): void
    {
        $query->whereIn('role', ['it', 'admin'])->where('enabled', true)->orderBy('name');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
