# Deployment and operations

## Reverse proxy

When Nginx Proxy Manager runs in Docker, copy `compose.proxy.example.yml` to
`compose.proxy.yml`, set `REVERSE_PROXY_NETWORK` if its shared network is not
named `frontend`, and include the override whenever the stack is started:

```bash
docker compose -f compose.yml -f compose.proxy.yml up -d --build
```

Configure the proxy upstream as `http://it-edu-selfservice-web-1:8080`. The
override keeps the shared network attachment across container recreations while
the PHP upstream remains isolated behind the unique `itrequests-php` alias.

## Schoolbox embedding

Set the exact Schoolbox origin during installation, for example
`https://schoolbox.example.edu.au`, then add the IT Requests URL as an embedded
external page in Schoolbox. IT Requests permits only that configured origin in
its Content Security Policy. When an origin is configured, session cookies are
automatically secured with `SameSite=None` so authenticated cross-site iframe
sessions can function.

Schoolbox embedding does not bypass authentication. Users still sign in through
the configured OIDC provider or an existing IT Requests session. Some identity
providers do not permit their login page inside an iframe; in that case users
must complete sign-in in a top-level browser tab before opening the Schoolbox
embed.

Set `APP_URL=https://itrequests.example.edu.au`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` to only the proxy container address or subnet. Never use `*` on a port reachable by untrusted clients.

Nginx example:

```nginx
location / {
    proxy_pass http://127.0.0.1:8088;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
}
```

For Nginx Proxy Manager, create a Proxy Host to the Docker host on port 8088, enable Websockets, request a certificate, force SSL, and put the NPM Docker subnet in `TRUSTED_PROXIES`. Caddy and Traefik work with their normal reverse-proxy configuration if the same headers are supplied.

The app emits HSTS for HTTPS requests, CSP, `nosniff`, a restrictive Permissions Policy and a Schoolbox-aware `frame-ancestors`. Enter the exact Schoolbox origin in the installer if embedding is required.

## OIDC / Entra ID

Register a confidential web application and use this redirect URI exactly:

```text
https://itrequests.example.edu.au/auth/oidc/callback
```

For Entra ID, the issuer is normally `https://login.microsoftonline.com/<tenant-id>/v2.0`. Grant `openid profile email`. The app discovers endpoints from the issuer and obtains identity from the TLS-protected userinfo endpoint. Restrict allowed domains in the installer. OIDC users begin as `teacher`; an administrator assigns IT/admin roles.

## Schoolbox

Use the app hostname as a Schoolbox tile/link. If embedding in an iframe, configure the exact Schoolbox origin in step 2. Prefer launching into a new tab unless both applications share the same registrable domain.

## Backups

Retain `.env` (especially `APP_KEY`) separately:

```bash
docker compose exec -T db pg_dump -U itrequests -d itrequests -Fc > itrequests.dump
docker run --rm -v it-edu-selfservice_app_storage:/data -v "$PWD:/backup" alpine \
  tar -czf /backup/itrequests-storage.tgz -C /data .
```

Restore into a clean deployment:

```bash
docker compose stop app queue scheduler
docker compose exec -T db pg_restore -U itrequests -d itrequests --clean --if-exists < itrequests.dump
docker compose up -d
```

Without the original `APP_KEY`, encrypted credentials cannot be recovered.

## Health and troubleshooting

- *Settings → Diagnostics* checks the database, version, scheduler heartbeat, queue backlog, email, SSO, network provider and each probe.
- `GET /health` returns `{"status":"ok","database":"ok","version":"…"}` for uptime monitors, with no configuration details.
- A check shows *DNS blocked* hosts that should be allowed: the probe is probably resolving through the Docker host's DNS rather than the VLAN gateway. Set `*_PROBE_DNS_SERVERS` or attach the probe to the VLAN.
- A re-run still shows the old result: check Diagnostics reports *fresh browser per run* and that `PROBE_REUSE_BROWSER` is not `true`. Remember UniFi may take a few seconds to push a filter change.

- `docker compose ps` should show `db`, `web`, and probes healthy.
- `curl -I http://127.0.0.1:8088/up` checks the web/app path.
- `docker compose logs app queue scheduler probe-student probe-staff` shows operational errors.
- `probe_error` for a private address means the hostname must be deliberately added to the relevant allowlist variable.
- `Action required` is expected when the official UniFi API lacks a domain allowlist operation.

## Supported matrix

| Item | Supported baseline |
| --- | --- |
| Docker / Compose | Engine 27+, Compose v2.30+ |
| CPU architecture | amd64; arm64 depends on the Playwright image |
| Database | PostgreSQL 17 |
| Runtime | PHP 8.5, Laravel 13 |
| Browsers | Current Chrome, Edge, Firefox and Safari |
| Identity | OIDC discovery + confidential authorization code flow |
| Reverse proxies | Nginx, Nginx Proxy Manager, Traefik, Caddy |
| UniFi | Official Network integration API; capability varies by version |

## Upgrade rollback

Database migrations are forward-only. Before an upgrade, retain the previous Git tag/image and a database backup. Rollback means restoring that backup and starting the prior images. Do not run an older image against a newer schema without a release-specific statement that it is safe.

## Sign-in security

Password sign-in is for emergencies; day-to-day sign-in should be single sign-on.

- **Brute-force protection.** 5 failed attempts for one email address within 15 minutes lock password sign-in for that address for 15 minutes, from any IP. 20 failures from one IP within 15 minutes block that IP. The route is also limited to 5 attempts a minute. Every failure, lockout and block is in the audit log, and a lockout emails the account owner and the IT address.
- **Turn off the installer's admin account.** Once an SSO administrator can sign in, untick **Password** for the local admin under **Users**, or turn off **Emergency local sign-in** under Settings → Authentication. You cannot turn off your own password sign-in while SSO is disabled.
- **Break glass.** From the Docker host: `docker compose exec app php artisan itrequests:admin-password admin@example.org --enable-local-login`. This resets the password, re-enables password sign-in for that account and clears its lockout.
- Restrict `/login` at the reverse proxy to staff networks where possible.

## Emails

| To | When | Can be turned off |
|---|---|---|
| IT address and opted-in IT staff | New request | Settings → Email |
| Assignee (or IT) | Requester replied | Settings → Email |
| Assignee | Request assigned to them | Settings → Email |
| IT address and opted-in IT staff | A content filter changed outside the app or cannot be read | Settings → Email |
| IT address | Daily reconciliation digest (only when there is something to do) | Settings → Email |
| Account owner and IT address | Password sign-in locked after failed attempts | No |
| Requester | Request received | Settings → Email |
| Requester | Approved (or approved and scheduled), declined, more information needed, access live, access window changed, revoked | No |
| Requester | Temporary access longer than a day ends within 24 hours | Settings → Email |
| Requester | Temporary access ended | Settings → Email |
