<?php

namespace App\Support;

use App\Models\NetworkType;
use InvalidArgumentException;

/**
 * Which networks a request or approval applies to: every network
 * (site-wide), or one or more network types. Stored as "site" or a
 * comma-separated list of type keys, for example "staff,student".
 */
final class Reach
{
    public const SITE = 'site';

    /** @param list<string> $types */
    private function __construct(public readonly bool $siteWide, public readonly array $types) {}

    public static function siteWide(): self
    {
        return new self(true, []);
    }

    /** @param list<string>|string $types */
    public static function types(array|string ...$types): self
    {
        $keys = collect($types)->flatten()->map(fn ($key) => strtolower(trim((string) $key)))->filter()->unique()->sort()->values()->all();
        if ($keys === []) {
            throw new InvalidArgumentException('Choose at least one network type.');
        }

        return new self(false, $keys);
    }

    /** Parse a stored value; also understands the old fixed scopes. */
    public static function from(string $value): self
    {
        return match ($value) {
            self::SITE => self::siteWide(),
            'staff_students' => self::types('staff', 'student'),
            'students' => self::types('student'),
            default => self::types(explode(',', $value)),
        };
    }

    public static function tryFrom(?string $value): ?self
    {
        try {
            return $value === null || $value === '' ? null : self::from($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** From a form: "site", or an array of type keys. */
    public static function fromInput(mixed $input): self
    {
        if ($input === self::SITE || (is_array($input) && in_array(self::SITE, $input, true))) {
            return self::siteWide();
        }

        return self::types(array_values(array_filter((array) $input, 'is_string')));
    }

    public function value(): string
    {
        return $this->siteWide ? self::SITE : implode(',', $this->types);
    }

    public function label(): string
    {
        return $this->siteWide ? 'Site-wide (all networks)' : collect($this->types)->map(fn (string $key) => NetworkType::labelFor($key))->join(', ', ' and ');
    }

    public function includes(string $type): bool
    {
        return $this->siteWide || in_array($type, $this->types, true);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->value() === $this->value();
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
