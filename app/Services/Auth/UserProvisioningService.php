<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Oidc\OidcClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Just-in-time OIDC users with rule-based roles.
 *
 * Role rules (Settings → Authentication) map an email domain or a group/role
 * claim to an application role, first match wins. Without a match the
 * configured default role applies, or sign-in is refused when the default is
 * "deny". Rules are applied when the account is created and, if enabled, on
 * each sign-in. Automatic re-evaluation never removes IT or administrator
 * access granted by hand.
 */
class UserProvisioningService
{
    public const ROLES = ['student', 'teacher', 'it', 'admin'];

    public function __construct(private readonly OidcClient $oidc, private readonly AuditLogger $audit) {}

    /** @param array{sub: string, email: string, name: string, groups?: list<string>} $claims */
    public function provision(array $claims): User
    {
        $configuration = $this->oidc->integration()->configuration ?? [];
        $allowed = array_values(array_filter(array_map(fn ($value) => strtolower(trim((string) $value)), $configuration['allowed_domains'] ?? [])));
        $domain = str($claims['email'])->afterLast('@')->lower()->toString();

        if ($allowed !== [] && ! in_array($domain, $allowed, true)) {
            throw new AuthorizationException('Your email domain is not permitted to use this service.');
        }

        $user = User::query()->where('oidc_subject', $claims['sub'])->first()
            ?? User::query()->whereNull('oidc_subject')->where('email', $claims['email'])->first();
        $isNew = ! $user;
        $mapped = $this->roleFor($claims, $configuration);

        if ($isNew && $mapped === null) {
            throw new AuthorizationException('Your account is not permitted to use this service.');
        }

        $user ??= new User(['email' => $claims['email'], 'role' => $mapped, 'password' => str()->random(64)]);
        $user->fill(['name' => $claims['name'], 'email' => $claims['email'], 'oidc_subject' => $claims['sub'], 'last_login_at' => now(), 'auth_source' => 'oidc']);

        if (! $isNew && $mapped !== null && ($configuration['sync_roles'] ?? false) && ! $user->isIt() && $user->role !== $mapped) {
            $this->audit->log('user.role_synced', $user, ['from' => $user->role, 'to' => $mapped]);
            $user->role = $mapped;
        }

        $user->save();

        if ($isNew) {
            $this->audit->log('user.provisioned', $user, ['role' => $user->role]);
        }

        if (! $user->enabled) {
            throw new AuthorizationException('This account has been disabled.');
        }

        return $user;
    }

    /**
     * @param  array{email: string, groups?: list<string>}  $claims
     * @param  array<string, mixed>  $configuration
     */
    public function roleFor(array $claims, array $configuration): ?string
    {
        $domain = str($claims['email'])->afterLast('@')->lower()->toString();
        $groups = array_map('strtolower', $claims['groups'] ?? []);

        foreach ($configuration['role_rules'] ?? [] as $rule) {
            $value = strtolower(trim((string) ($rule['value'] ?? '')));
            $role = (string) ($rule['role'] ?? '');
            if ($value === '' || ! in_array($role, self::ROLES, true)) {
                continue;
            }

            $matches = match ($rule['type'] ?? null) {
                'email_domain' => $domain === $value || str_ends_with($domain, '.'.$value),
                'group' => in_array($value, $groups, true),
                default => false,
            };

            if ($matches) {
                return $role;
            }
        }

        $default = $configuration['default_role'] ?? 'teacher';

        return $default === 'deny' ? null : (in_array($default, self::ROLES, true) ? $default : 'teacher');
    }

    /**
     * Parse "email_domain:students.example.edu.au=student" style lines.
     *
     * @return list<array{type: string, value: string, role: string}>
     */
    public static function parseRules(string $text): array
    {
        $rules = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^(email_domain|domain|group):\s*([^=]+?)\s*=\s*(student|teacher|it|admin)$/i', $line, $matches)) {
                throw ValidationException::withMessages(['role_rules' => "Invalid role rule: {$line}"]);
            }
            $rules[] = ['type' => strtolower($matches[1]) === 'group' ? 'group' : 'email_domain', 'value' => strtolower(trim($matches[2])), 'role' => strtolower($matches[3])];
        }

        return $rules;
    }

    /** @param list<array{type: string, value: string, role: string}> $rules */
    public static function formatRules(array $rules): string
    {
        return collect($rules)->map(fn (array $rule) => "{$rule['type']}:{$rule['value']}={$rule['role']}")->implode("\n");
    }
}
