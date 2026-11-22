<?php

namespace App\Services\Policy;

use Illuminate\Validation\ValidationException;

/**
 * Turns pasted list text into provider-safe hostname entries.
 *
 * UniFi DNS filtering accepts bare hostnames only, so schemes, paths, ports,
 * escaped dots (www\.example.com), wildcard prefixes (*.example.com) and
 * leading dots are removed. Anything still not a hostname is rejected.
 */
class PolicyListParser
{
    /** @return list<string> */
    public function parse(string $input, string $field = 'domains'): array
    {
        return $this->parseDetailed($input, $field)['entries'];
    }

    /** @return array{entries: list<string>, notes: list<string>} */
    public function parseDetailed(string $input, string $field = 'domains'): array
    {
        $values = preg_split('/[\s,;]+/', $input) ?: [];
        $entries = [];
        $invalid = [];
        $wildcards = [];

        foreach ($values as $value) {
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($value === '' || str_starts_with($value, '#')) {
                continue;
            }

            $original = $value;
            $value = $this->normalise($value);

            if (str_starts_with(ltrim($original, '\\'), '*.') || str_starts_with($original, '.')) {
                $wildcards[] = $value;
            }

            if (! $this->valid($value)) {
                $invalid[] = $original;

                continue;
            }

            $entries[] = $value;
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                $field => 'These entries are not valid hostnames: '.implode(', ', array_slice($invalid, 0, 5)).(count($invalid) > 5 ? ' …' : ''),
            ]);
        }

        $entries = array_values(array_unique($entries));
        sort($entries);
        $notes = $wildcards === [] ? [] : [
            'Wildcard prefixes are not supported by DNS filtering and were removed from: '.implode(', ', array_slice(array_unique($wildcards), 0, 5)).'.',
        ];

        return ['entries' => $entries, 'notes' => $notes];
    }

    public function normalise(string $value): string
    {
        $value = strtolower(str_replace('\\.', '.', trim($value)));
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        } else {
            $value = explode('/', $value, 2)[0];
            $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        }

        $value = preg_replace('/^(\*\.|\.)+/', '', $value) ?? $value;
        $value = rtrim($value, '.');

        if ($value !== '' && preg_match('/[^\x00-\x7F]/', $value) && function_exists('idn_to_ascii')) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }

        return $value;
    }

    public function valid(string $value): bool
    {
        return strlen($value) <= 253
            && filter_var($value, FILTER_VALIDATE_IP) === false
            && (bool) preg_match('/^(?:_?[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,62}$/', $value);
    }
}
