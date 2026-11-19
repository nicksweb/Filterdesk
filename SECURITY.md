# Security policy

Report suspected vulnerabilities privately through GitHub Security Advisories. Include the version, impact, reproduction and a minimal redacted proof. Do not include live credentials, student data, internal domains or school topology.

Maintainers will acknowledge a complete report within five business days, assess severity, coordinate a fix and publish an advisory when users can update safely.

Security fixes target the latest minor release. Rebuild regularly for patched PHP, Alpine, Chromium and dependency layers.

The database, PHP-FPM and probes must remain private; only Nginx is proxied. Administrators retain `APP_KEY`, backups and API keys securely. UniFi starts read-only. Changing these assumptions requires a local threat review.
