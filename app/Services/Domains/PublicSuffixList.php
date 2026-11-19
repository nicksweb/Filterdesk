<?php

namespace App\Services\Domains;

/**
 * Minimal Public Suffix List matcher (ICANN section, bundled in
 * resources/data). Used to find a hostname's registrable domain, for example
 * "learning.example.edu.au" → "example.edu.au", without a network lookup.
 */
class PublicSuffixList
{
    /** @var array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>}|null */
    private static ?array $rules = null;

    public function registrableDomain(string $host): ?string
    {
        $host = strtolower(trim($host, '.'));
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return null;
        }

        $suffixLength = $this->publicSuffixLength($labels);
        if ($suffixLength >= count($labels)) {
            return null;
        }

        return implode('.', array_slice($labels, -($suffixLength + 1)));
    }

    public function isPublicSuffix(string $host): bool
    {
        $labels = explode('.', strtolower(trim($host, '.')));

        return $this->publicSuffixLength($labels) === count($labels);
    }

    /** @param list<string> $labels */
    private function publicSuffixLength(array $labels): int
    {
        $rules = $this->rules();
        $count = count($labels);
        $best = 1;

        for ($length = 1; $length <= $count; $length++) {
            $candidate = implode('.', array_slice($labels, -$length));
            if (isset($rules['exceptions'][$candidate])) {
                return $length - 1;
            }
            if (isset($rules['rules'][$candidate])) {
                $best = $length;
            }
            if ($length < $count) {
                $parent = implode('.', array_slice($labels, -$length));
                if (isset($rules['wildcards'][$parent])) {
                    $best = max($best, $length + 1);
                }
            }
        }

        return $best;
    }

    /** @return array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>} */
    private function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $rules = ['rules' => [], 'wildcards' => [], 'exceptions' => []];
        $lines = @file(resource_path('data/public_suffix_icann.dat'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $line = $this->ascii(strtolower($line));
            if (str_starts_with($line, '!')) {
                $rules['exceptions'][substr($line, 1)] = true;
            } elseif (str_starts_with($line, '*.')) {
                $rules['wildcards'][substr($line, 2)] = true;
            } else {
                $rules['rules'][$line] = true;
            }
        }

        return self::$rules = $rules;
    }

    private function ascii(string $value): string
    {
        if (! function_exists('idn_to_ascii') || preg_match('/^[\x00-\x7F]*$/', $value)) {
            return $value;
        }

        return idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
    }
}
