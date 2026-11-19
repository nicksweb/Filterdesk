<?php

namespace App\Services\Domains;

use Illuminate\Validation\ValidationException;

class DomainNormalizer
{
    /** @return list<string> */
    public function many(string $input): array
    {
        $values = preg_split('/[\s,;]+/', trim($input)) ?: [];
        $domains = [];

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            $domains[] = $this->one($value);
        }

        $domains = array_values(array_unique($domains));

        if ($domains === [] || count($domains) > 25) {
            throw ValidationException::withMessages(['domains' => 'Enter between 1 and 25 valid domain names.']);
        }

        return $domains;
    }

    public function one(string $value): string
    {
        $candidate = str_contains($value, '://') ? $value : "https://{$value}";
        $host = strtolower(rtrim((string) parse_url($candidate, PHP_URL_HOST), '.'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages(['domains' => "{$value} is not a valid domain name."]);
        }

        if (! str_contains($host, '.')) {
            throw ValidationException::withMessages(['domains' => "{$value} must be a fully qualified domain name."]);
        }

        return $host;
    }
}
