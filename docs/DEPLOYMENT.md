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

## Probe containers on the VLANs

Website checks run in small probe containers (headless browsers). A check is only meaningful if the probe sees the same filtering as the people it stands for, and UniFi content filtering is applied per network at the gateway's DNS: a lookup is filtered by whichever filter is attached to the network it comes from. A probe that uses the Docker host's own network sees that network's filtering, or none. So each probe must:

1. **Sit on a VLAN its policy covers.** Give it a macvlan (or ipvlan) interface on that VLAN, made the probe's default route (`gw_priority: 1`).
2. **Use that VLAN's gateway for DNS** (`PROBE_DNS_SERVERS`), so lookups go uncached to the resolver UniFi filters on. With the host's resolver instead, checks show *DNS blocked* or *allowed* for the wrong network.
3. **Have a fixed address outside the DHCP pool.** The suggested `/29` at the top of each subnet is for that; shrink the UniFi DHCP range or reserve it.

### One trunk, VLANs tagged

The simplest setup is one host interface, the **trunk**, whose switch port carries every probe VLAN tagged (in UniFi, a port profile with the native network untagged and the probe VLANs tagged). Set it once under *Guided setup → Trunk interface*. Each probe then gets `<trunk>.<VLAN>` as its macvlan parent, which Docker creates as a tagged sub-interface; a probe for the native (untagged) network uses the trunk itself. Nothing needs configuring on the host beyond the cable.

To find the trunk and confirm the port carries the tags, run on the Docker host:

```bash
bin/probe-interfaces                   # interfaces, kinds, VLAN ids, addresses; suggests the trunk
sudo bin/probe-interfaces --listen eno1  # reports which VLAN tags arrive on eno1 (needs tcpdump)
```

If a network reaches the host some other way (a separate NIC on an access port, or a VLAN interface or bridge already defined in netplan), open *Different interface* next to that probe and enter it; leave it blank to use the trunk. The interfaces and extra probes are saved, and the download and `probes:compose` use them.

### Generating the file

After **Policy lists → Guided setup → Bring everything in**, the *Probe containers* section plans one probe per policy and writes the whole `compose.vlan.yml`:

- **Download compose.vlan.yml** on that page, or on the Docker host:

  ```bash
  docker compose exec -T app php artisan probes:compose > compose.vlan.yml
  ```

- The top of the file lists the `docker network create -d macvlan …` command for each probe and the `.env` lines for the built-in `probe-student` and `probe-staff`. Run the commands once on the host, then start the stack with the override:

  ```bash
  docker compose -f compose.yml -f compose.proxy.yml -f compose.vlan.yml up -d
  ```

- **Create and link probe profiles** adds a disabled network profile per probe. Enable each one under *Settings → Network profiles* once its container is healthy, then check a hostname you know is blocked on that network. *Settings → Diagnostics* checks every enabled probe.

### More than staff and students

Every policy mapped to a network gets its own probe. Policies other than Students and Staff (a guest filter, or one per year level) get a full service definition in the generated file, named `probe-<policy>`. To test from a network that has no policy of its own, or a second VLAN worth checking separately (guest Wi-Fi, BYOD, a lab), tick it under **Extra probes** on the same page, and save (or pass `--network=<controller network id>` to `probes:compose`); it becomes `probe-net-<name>` with its own profile. Checks run on every enabled profile, so the results show each network side by side. Layers share their parent's networks and need no probe of their own.

Keep probes off the internet-facing side: they only talk to the app over the internal `probe_control` network and reject private targets unless listed in `*_ALLOWED_PRIVATE_HOSTS`.

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
