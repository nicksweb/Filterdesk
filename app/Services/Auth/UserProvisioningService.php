<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Oidc\OidcClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
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
        // A name an admin corrected under Users is kept.
        $user->fill(['name' => $user->exists && $user->name_locked ? $user->name : $claims['name'], 'email' => $claims['email'], 'oidc_subject' => $claims['sub'], 'last_login_at' => now(), 'auth_source' => 'oidc']);

        // The groups this sign-in carried, shown under Users.
        $user->sso_groups = array_values(array_unique(array_filter(array_map('strval', $claims['groups'] ?? [])))) ?: null;

        // Identity mapping: network types and department from the same claims, refreshed at every sign-in.
        $identity = $this->identityFor($claims, $configuration);
        if ($identity['types'] !== []) {
            $user->network_types = $identity['types'];
        }
        if ($identity['department'] !== null) {
            $user->department = $identity['department'];
        }

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
        return $this->explainRole($claims, $configuration)['role'];
    }

    /**
     * The role these claims get and why: the rule that matched (as written
     * in the rules box), or null when the default role applied.
     *
     * @param  array{email: string, groups?: list<string>}  $claims
     * @param  array<string, mixed>  $configuration
     * @return array{role: ?string, rule: ?string}
     */
    public function explainRole(array $claims, array $configuration): array
    {
        $domain = str($claims['email'])->afterLast('@')->lower()->toString();
        $groups = self::groupNames($claims['groups'] ?? []);

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
                return ['role' => $role, 'rule' => "{$rule['type']}:{$value}={$role}"];
            }
        }

        $default = $configuration['default_role'] ?? 'teacher';

        return ['role' => $default === 'deny' ? null : (in_array($default, self::ROLES, true) ? $default : 'teacher'), 'rule' => null];
    }

    /**
     * What the rules would change for single sign-on accounts, from the
     * groups each person had at their last sign-in. IT and administrator
     * access is never taken away here ("kept"), as at sign-in.
     *
     * @param  array<string, mixed>  $configuration
     * @return Collection<int, array{user: User, from: string, to: string, rule: ?string, kept: bool}>
     */
    public function roleChanges(array $configuration): Collection
    {
        return User::query()->where('auth_source', 'oidc')->where('enabled', true)->whereNotNull('email')->orderBy('name')->get()
            ->map(function (User $user) use ($configuration): ?array {
                $result = $this->explainRole(['email' => (string) $user->email, 'groups' => $user->sso_groups ?? []], $configuration);
                if ($result['role'] === null || $result['role'] === $user->role) {
                    return null;
                }

                return ['user' => $user, 'from' => $user->role, 'to' => $result['role'], 'rule' => $result['rule'], 'kept' => $user->isIt() && ! in_array($result['role'], ['it', 'admin'], true)];
            })->filter()->values();
    }

    /**
     * Apply the rules to existing single sign-on accounts now.
     *
     * @param  array<string, mixed>  $configuration
     */
    public function applyRoles(array $configuration, User $actor): int
    {
        $changed = 0;
        foreach ($this->roleChanges($configuration)->reject(fn (array $change) => $change['kept']) as $change) {
            $change['user']->forceFill(['role' => $change['to']])->save();
            $this->audit->log('user.role_synced', $change['user'], ['from' => $change['from'], 'to' => $change['to'], 'rule' => $change['rule'], 'by' => $actor->id]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Every name a group can be matched by, lower case: the value as sent,
     * the CN of a directory path ("CN=ITStaff,OU=User Groups,DC=…" → "itstaff")
     * and the part before @ of a group email ("itstaff@school.example" →
     * "itstaff"). So a rule can say group:itstaff however the provider sends it.
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    public static function groupNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            $value = strtolower(trim((string) $group));
            if ($value === '') {
                continue;
            }
            $names[] = $value;
            if (preg_match('/^cn=([^,]+)/', $value, $match)) {
                $names[] = trim($match[1]);
            }
            if (str_contains($value, '@') && ! str_contains($value, ',')) {
                $names[] = strstr($value, '@', true);
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '')));
    }

    /** A short label for a group: its CN, or the name before @, or the value. */
    public static function groupLabel(string $group): string
    {
        $value = trim($group);
        if (preg_match('/^cn=([^,]+)/i', $value, $match)) {
            return trim($match[1]);
        }

        return str_contains($value, '@') && ! str_contains($value, ',') ? (string) strstr($value, '@', true) : $value;
    }

    /**
     * Network types (every matching rule adds its types) and department
     * (first match) from the sign-in claims.
     *
     * @param  array{email: string, groups?: list<string>}  $claims
     * @param  array<string, mixed>  $configuration
     * @return array{types: list<string>, department: ?string}
     */
    public function identityFor(array $claims, array $configuration): array
    {
        $domain = str($claims['email'])->afterLast('@')->lower()->toString();
        $groups = self::groupNames($claims['groups'] ?? []);
        $matches = fn (array $rule) => match ($rule['type'] ?? null) {
            'email_domain' => $domain === $rule['value'] || str_ends_with($domain, '.'.$rule['value']),
            'group' => in_array($rule['value'], $groups, true),
            default => false,
        };

        $types = [];
        foreach ($configuration['type_rules'] ?? [] as $rule) {
            if ($matches($rule)) {
                $types = [...$types, ...array_map('trim', explode(',', (string) $rule['target']))];
            }
        }
        $department = collect($configuration['department_rules'] ?? [])->first(fn (array $rule) => $matches($rule))['target'] ?? null;

        return ['types' => array_values(array_unique(array_filter($types))), 'department' => $department];
    }

    /**
     * Parse "group:year-9=student" (network types, comma separated) or
     * "group:science-faculty=Science" (department) lines.
     *
     * @return list<array{type: string, value: string, target: string}>
     */
    public static function parseMappingRules(string $text, string $field): array
    {
        $rules = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^(email_domain|domain|group):\s*([^=]+?)\s*=\s*(.+)$/i', $line, $matches)) {
                throw ValidationException::withMessages([$field => "Invalid rule: {$line}"]);
            }
            $rules[] = ['type' => strtolower($matches[1]) === 'group' ? 'group' : 'email_domain', 'value' => strtolower(trim($matches[2])), 'target' => trim($matches[3])];
        }

        return $rules;
    }

    /** @param list<array{type: string, value: string, target: string}> $rules */
    public static function formatMappingRules(array $rules): string
    {
        return collect($rules)->map(fn (array $rule) => "{$rule['type']}:{$rule['value']}={$rule['target']}")->implode("\n");
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
