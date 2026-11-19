<?php

namespace App\Services\Policy;

use Illuminate\Validation\ValidationException;

class PolicyListParser
{
    /** @return list<string> */
    public function parse(string $input, string $field = 'domains'): array
    {
        $values = preg_split('/[\r\n,;]+/', $input) ?: [];
        $entries = [];
        $invalid = [];

        foreach ($values as $value) {
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($value === '' || str_starts_with($value, '#')) {
                continue;
            }

            $value = strtolower(str_replace('\\.', '.', $value));
            if (str_contains($value, '://')) {
                $value = (string) parse_url($value, PHP_URL_HOST);
            } else {
                $value = explode('/', $value, 2)[0];
            }

            $value = rtrim($value, '.');
            if (! preg_match('/^(?:\*\.)?(?:_?[a-z0-9][a-z0-9_-]*\.)+[a-z0-9][a-z0-9-]*$/i', $value)) {
                $invalid[] = $value;

                continue;
            }

            $entries[] = $value;
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                $field => 'These entries are not valid domain policy entries: '.implode(', ', array_slice($invalid, 0, 5)),
            ]);
        }

        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }
}
