<?php

namespace App\Services\Network;

use App\Services\SettingsRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The public CA certificate a gateway uses for HTTPS inspection (for example
 * UniFi's "UniFi SSL Certificate Authority"), so devices on an inspected
 * network can trust it. Only the public certificate is ever accepted: a
 * private key is refused. It is offered on a public download page.
 */
class InspectionCertificate
{
    private const KEY = 'inspection.certificate';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{pem: string, subject: string, issuer: string, fingerprint: string, valid_from: string, valid_to: string, uploaded_at: string}|null */
    public function current(): ?array
    {
        $stored = $this->settings->get(self::KEY);

        return is_array($stored) && filled($stored['pem'] ?? null) ? $stored : null;
    }

    /**
     * Store a certificate given as PEM text or DER bytes.
     *
     * @return array<string, string>
     */
    public function store(string $contents): array
    {
        if (preg_match('/PRIVATE KEY/i', $contents)) {
            throw new InvalidArgumentException('That file contains a private key. Upload only the public CA certificate (.crt or .pem), and keep the key on the gateway.');
        }
        $pem = str_contains($contents, '-----BEGIN CERTIFICATE-----')
            ? trim(Str::between($contents, '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'))
            : base64_encode($contents);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n")."-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);
        if (! $parsed) {
            throw new InvalidArgumentException('That is not a certificate Filterdesk can read. Export the CA certificate as .crt (PEM or DER).');
        }
        $name = fn (array $parts): string => collect([$parts['CN'] ?? null, $parts['O'] ?? null])->filter()->map(fn ($value) => is_array($value) ? implode(' ', $value) : $value)->join(', ');
        $info = [
            'pem' => $pem,
            'subject' => $name($parsed['subject'] ?? []) ?: 'Unnamed certificate',
            'issuer' => $name($parsed['issuer'] ?? []),
            'fingerprint' => implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($pem, 'sha256')), 2)),
            'valid_from' => date('Y-m-d', (int) ($parsed['validFrom_time_t'] ?? 0)),
            'valid_to' => date('Y-m-d', (int) ($parsed['validTo_time_t'] ?? 0)),
            'uploaded_at' => now()->toDateString(),
        ];
        $this->settings->set(self::KEY, $info);

        return $info;
    }

    public function remove(): void
    {
        $this->settings->set(self::KEY, null);
    }

    /** The certificate as DER bytes, for a .crt download most devices open directly. */
    public function der(): ?string
    {
        $current = $this->current();

        return $current ? base64_decode(trim(Str::between($current['pem'], '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'))) : null;
    }

    /** Whether an issuer reported by a probe ("UniFi SSL Certificate Authority, Ubiquiti Inc.") is this certificate. */
    public function matches(?string $issuer): bool
    {
        $current = $this->current();
        if (! $current || blank($issuer)) {
            return false;
        }
        $common = Str::lower(trim(explode(',', $current['subject'])[0]));

        return $common !== '' && str_contains(Str::lower($issuer), $common);
    }
}
