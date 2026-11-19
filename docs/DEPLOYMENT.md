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
