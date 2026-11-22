# Setup guide

This guide goes from an empty Linux host to a working install that keeps UniFi's content filters in step. Allow about an hour, plus time to set up the switch port for the probes. Work through it in order. Each step ends with a check, so you know it worked before you move on.

For hardening once it runs, see [SECURITY_HARDENING.md](SECURITY_HARDENING.md).

## Contents

1. [Before you start](#1-before-you-start)
2. [Prepare the host](#2-prepare-the-host)
3. [Get the code and create .env](#3-get-the-code-and-create-env)
4. [Environment variable reference](#4-environment-variable-reference)
5. [Start the stack](#5-start-the-stack)
6. [Set up the reverse proxy](#6-set-up-the-reverse-proxy)
7. [Run the installation wizard](#7-run-the-installation-wizard)
8. [Bring UniFi in](#8-bring-unifi-in)
9. [Put the probes on the VLANs](#9-put-the-probes-on-the-vlans)
10. [Test end to end](#10-test-end-to-end)
11. [Go live](#11-go-live)
12. [Ongoing operation](#12-ongoing-operation)

## 1. Before you start

Have these ready:

| Item | Notes |
| --- | --- |
| Linux host | Ubuntu 22.04+ or Debian 12+ recommended; 2 CPU, 4 GB RAM free, 20 GB disk. A VM is fine. It must reach the UniFi console (local key) or the internet (Site Manager key). |
| Hostname | For example `itrequests.example.edu.au`, with a DNS record pointing at your reverse proxy. |
| HTTPS reverse proxy | Nginx Proxy Manager, Caddy, Traefik or Nginx, with a certificate for that hostname. |
| UniFi API key | See [the API key section of the README](../README.md#unifi-api-key). Create it for a dedicated admin account; see [hardening](SECURITY_HARDENING.md#unifi). |
| Single sign-on (recommended) | An OIDC app registration, for example in Entra ID. The redirect URI is `https://<hostname>/auth/oidc/callback`. You need the issuer URL, client ID and client secret. |
| SMTP (recommended) | Host, port, username, password and a from address, plus the IT team's notification address. |
| Switch access | Needed for step 9, to tag the probe VLANs on the Docker host's switch port. |

## 2. Prepare the host

Install Docker Engine 28+ and the Compose plugin 2.33+ from Docker's own repository (the distribution packages are often too old):

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker "$USER"     # log out and back in
docker version --format '{{.Server.Version}}'   # 28 or newer
docker compose version --short                  # 2.33 or newer
```

Also install `git`, `openssl` and, for checking VLAN tags later, `tcpdump`:

```bash
sudo apt install -y git openssl tcpdump
```

**Check:** `docker run --rm hello-world` prints its greeting.

## 3. Get the code and create .env

```bash
git clone https://github.com/nicksweb/it-edu-selfservice.git itrequests
cd itrequests
./bin/install
```

`bin/install` copies `.env.example` to `.env`, fills in a random `APP_KEY`, database password and probe key, and makes the file readable only by you (`chmod 600`). It never overwrites values you have already set, so it is safe to run again.

Now open `.env` and set at least:

- `APP_URL`: the address people will use.
- `TRUSTED_PROXIES`: your reverse proxy's IP address (see [step 6](#6-set-up-the-reverse-proxy)).
- `APP_TIMEZONE`.

The next section explains every variable.

**Check:** `grep -E '^(APP_KEY|DB_PASSWORD|PROBE_SHARED_KEY)=.' .env` prints three lines.

## 4. Environment variable reference

Most settings (school, sign-in, UniFi, email, approvals) are entered in the wizard and stored in the database, with credentials encrypted. `.env` only holds what the containers need before the database is available. After changing `.env`, apply it with `docker compose up -d --force-recreate` (add your override files).

### Application

| Variable | Default | What to set |
| --- | --- | --- |
| `APP_NAME` | `IT Requests` | Shown in the browser title and emails. |
| `APP_ENV` | `production` | Leave as `production`. |
| `APP_KEY` | *generated* | Encrypts sessions and every stored credential (UniFi key, OIDC secret, SMTP password). **Back it up with the database.** Without it, those credentials cannot be recovered. Never change it on a running install. |
| `APP_DEBUG` | `false` | Must stay `false` in production; `true` shows stack traces to users. |
| `APP_URL` | `https://itrequests.example.edu.au` | The exact public URL, including `https://`. It is used in emails and links. For a local trial use `http://127.0.0.1:8088`. |
| `APP_TIMEZONE` | `Australia/Brisbane` | Your timezone. Access windows, expiries and digests use it. |
| `APP_LOCALE` | `en` | Interface language. |
| `TRUSTED_PROXIES` | `127.0.0.1` | The IP address (or comma-separated list, or CIDR) of the reverse proxy *as the app sees it*. If wrong, the app thinks every request is plain HTTP: **pages load without styling** and secure cookies fail. The wizard's first page tells you the address to use. Never use `*` if the port is reachable by anyone other than the proxy. |
| `APP_PORT` | `8088` | Port the web container publishes, on `127.0.0.1` only. Change it if 8088 is taken. |
| `APP_IMAGE_TAG` | `local` | Tag for the locally built images. Leave as is. |

### Logging

| Variable | Default | What to set |
| --- | --- | --- |
| `LOG_CHANNEL` | `stack` | Leave. |
| `LOG_STACK` | `stderr` | Logs go to `docker compose logs`. |
| `LOG_LEVEL` | `info` | `debug` temporarily when troubleshooting; `warning` to reduce noise. |

### Database

| Variable | Default | What to set |
| --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | Leave. |
| `DB_HOST` / `DB_PORT` | `db` / `5432` | The bundled PostgreSQL container. Change only for an external server. |
| `DB_DATABASE` / `DB_USERNAME` | `itrequests` | Leave. |
| `DB_PASSWORD` | *generated* | Set once, before the first start. The database volume keeps the password it was created with, so changing it later locks the app out. |

### Sessions, queue and cache

| Variable | Default | What to set |
| --- | --- | --- |
| `SESSION_DRIVER` | `database` | Leave. |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before sign-out. |
| `SESSION_ENCRYPT` | `true` | Leave `true`. |
| `SESSION_SECURE_COOKIE` | `true` | `true` behind HTTPS. Set `false` only for a plain `http://` trial, or sign-in will not stick. |
| `SESSION_SAME_SITE` | `lax` | Leave. It becomes `none` automatically when a Schoolbox origin is configured for embedding. |
| `QUEUE_CONNECTION` / `CACHE_STORE` | `database` | Leave. Emails and pushes run in the `queue` container. |

### Email fallback

SMTP is normally set in the wizard (Settings → Email). These are only used before that is configured.

| Variable | Default | What to set |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | `log` writes emails to the log instead of sending them. |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | example values | Your sending address and display name. |

### Probes

| Variable | Default | What to set |
| --- | --- | --- |
| `PROBE_SHARED_KEY` | *generated* | Secret the app sends to the probes; at least 24 characters. Keep it random. |
| `PROBE_TIMEOUT_MS` | `2800` | How long a check waits per page (maximum 5000). |
| `BLOCK_PAGE_PATTERN` | `blocked\|access denied\|…` | Regular expression for text on your block page, used to recognise a blocked result. |
| `BLOCK_PAGE_ADDRESSES` | *empty* | Comma-separated IPs UniFi answers with for blocked names (its block page). `0.0.0.0` always counts as blocked. A check of a blocked site shows the address to use. |
| `STUDENT_PROBE_DNS_SERVERS` / `STAFF_PROBE_DNS_SERVERS` | *empty* | The VLAN gateway for each built-in probe, so lookups go uncached to the resolver UniFi filters on. The generated `compose.vlan.yml` lists these. Extra probes have theirs inside the file. |
| `STUDENT_ALLOWED_PRIVATE_HOSTS` / `STAFF_ALLOWED_PRIVATE_HOSTS` | *empty* | Hostnames on private addresses that a probe may load (for example an internal block page). Everything else private is refused, to stop the probes being used to reach internal systems. |
| `STUDENT_DOCKER_NETWORK` / `STAFF_DOCKER_NETWORK` | example names | The macvlan network each built-in probe joins. The generated file tells you what to put here. |
| `PROBE_REUSE_BROWSER` | `false` | Keep `false`, so every check starts with an empty DNS cache. |

### Reverse proxy override

| Variable | Default | What to set |
| --- | --- | --- |
| `REVERSE_PROXY_NETWORK` | `frontend` | Only with `compose.proxy.yml`: the Docker network your proxy container is on. |

## 5. Start the stack

Choose one:

- **Proxy on the host, or no proxy (trial):**

  ```bash
  docker compose up -d --build
  ```

- **Proxy in another Docker container** (for example Nginx Proxy Manager):

  ```bash
  cp compose.proxy.example.yml compose.proxy.yml
  docker compose -f compose.yml -f compose.proxy.yml up -d --build
  ```

  Use the same `-f` files for every later `docker compose` command.

The first build takes several minutes. The one-off `migrate` container sets up the database, then the rest start.

**Check:** `docker compose ps` shows `db`, `web`, `probe-student` and `probe-staff` as `healthy`, `app`, `queue` and `scheduler` as running, and `migrate` exited with code 0. Also `curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/up` prints `200`.

If something fails, `docker compose logs migrate app web` usually shows why.

## 6. Set up the reverse proxy

Point the proxy at the app:

| Proxy | Upstream |
| --- | --- |
| On the host (Nginx, Caddy) | `http://127.0.0.1:8088` |
| Nginx Proxy Manager or Traefik in Docker, with `compose.proxy.yml` | `http://it-edu-selfservice-web-1:8080` |

In Nginx Proxy Manager: add a proxy host for your hostname with that upstream, request a certificate, and turn on **Force SSL**, **HTTP/2** and **HSTS**. The proxy must send `X-Forwarded-Proto` and `X-Forwarded-For`, which all of the proxies above do by default. A plain Nginx example is in [DEPLOYMENT.md](DEPLOYMENT.md#reverse-proxy).

Then set `TRUSTED_PROXIES` to the proxy's address:

- Proxy on the host: `127.0.0.1` (the default).
- Proxy in Docker: its IP on the shared network, from
  `docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' <proxy-container>`.

Apply it with `docker compose … up -d --force-recreate`.

**Check:** opening `https://<hostname>` shows a styled installer page. If it is unstyled, `TRUSTED_PROXIES` is wrong; the installer's first page names the address it sees.

## 7. Run the installation wizard

Open `https://<hostname>`. The wizard has eight steps and locks itself once finished.

| Step | Enter | Tips |
| --- | --- | --- |
| 1. System checks | Nothing | All four checks should be ✓. A `!` against *Proxy* means `TRUSTED_PROXIES` is wrong. |
| 2. School | Name, timezone, the URL (same as `APP_URL`), Schoolbox origin | Fill in the Schoolbox origin (for example `https://schoolbox.example.edu.au`) only if the app will be embedded in Schoolbox. |
| 3. Administrator and sign-in | The first local admin's name, email and password (14+ characters). Optionally OIDC: issuer, client ID, secret, allowed email domains, role rules, default role. | For Entra ID the issuer is `https://login.microsoftonline.com/<tenant-id>/v2.0`. Role rules map groups or emails to *student*, *teacher*, *IT* and *admin*. Leave **Emergency local sign-in** on until SSO is proven. |
| 4. UniFi | Connection type, then the console URL (local key) or console ID (Site Manager key), the API key and site, **Read-only** mode, entry matching *Explicit* | Start read-only. You can test and change all of this later under Settings → Network provider. |
| 5. Network access profiles | Keep `http://probe-student:3000` and `http://probe-staff:3000` | Network IDs are filled in later by Guided setup. |
| 6. Email | SMTP host, port, username, password, from address, IT notification address | The IT address receives new requests, alerts and the daily digest. Settings → Email has a test button. |
| 7. Approval policy | Auto-approval for trusted networks, whether students may request, default access length, request categories | Defaults are sensible for a trial. |
| 8. Review | Check, then **Complete** | |

Sign in as the administrator.

**Check:** Settings → Network provider → **Test connection** succeeds and redirects you to Guided setup. Settings → Diagnostics is all green except probes you have not set up yet.

## 8. Bring UniFi in

On **Policy lists → Guided setup** (the dashboard links to it), choose **Bring everything in**. It:

- creates a policy for each UniFi content filter, reusing the default *Students* and *Staff* policies where the network type fits;
- recognises a second filter on the same networks that is named or shaped like a blocklist as a *layer*;
- imports every allow and block entry, maps networks to policies and keeps categories as they are;
- prepares a disabled probe profile per policy.

Nothing changes in UniFi. If a guess is wrong, use the per-filter table below the button instead, or fix the mapping afterwards under *Networks & filters*.

Rename or add network types under **Settings → Network types** if Staff and Students don't describe your networks (for example CCTV or Exam rooms), and set each policy's type.

**Check:** each policy page says *In sync*, and its change set (to add, to remove) is empty. *Networks & filters* shows each network with the policy you expect.

## 9. Put the probes on the VLANs

A check is only meaningful if the probe resolves DNS from the same network as the people it represents. UniFi filters DNS at the gateway, per network. So each probe gets its own interface on its policy's VLAN (macvlan), a fixed address, and that VLAN's gateway as its DNS server. This is not host networking: the Docker host's own network settings are untouched.

1. **Tag the VLANs on the host's switch port.** In UniFi, give the port a profile with the host's normal network untagged (native) and each probe VLAN tagged.
2. **Find the trunk interface.** On the host:

   ```bash
   bin/probe-interfaces                       # lists interfaces and suggests the trunk
   sudo bin/probe-interfaces --listen eno1    # confirms which VLAN tags arrive on eno1
   ```

3. **Plan.** On Guided setup → *Probe containers*, enter the trunk and tick any **extra probes** (guest Wi-Fi, BYOD, a lab). Use *Different interface* only if a network reaches the host on another NIC. Then **Save probe interfaces**.
4. **Keep the probe addresses out of DHCP.** Each probe has a fixed address in a small reserved range at the top of its subnet, shown on the page. Make sure the UniFi DHCP range for that network ends below it.
5. **Write and apply the file** on the host:

   ```bash
   docker compose exec -T app php artisan probes:compose > compose.vlan.yml
   head -30 compose.vlan.yml      # run the 'docker network create' commands listed there, once
   nano .env                      # add the *_DOCKER_NETWORK and *_PROBE_DNS_SERVERS lines it lists
   docker compose -f compose.yml -f compose.proxy.yml -f compose.vlan.yml up -d
   ```

6. **Enable the probes.** Use **Create and link probe profiles**, then tick *On* for each profile under Settings → Network profiles.

**Finish each probe with its checklist.** Settings → Network profiles runs a setup checklist for every probe: network chosen, container running, on its VLAN, gateway reachable, DNS through the gateway, internet access, network filtered, address outside DHCP, switched on. Each failed step says what to fix (for example "give the switch port a profile that carries VLAN 20 tagged" or "turn off the Proxmox MAC filter"). The checklist reruns every 10 minutes, the dashboard reminds admins while any probe is unfinished, and `docker compose exec app php artisan probes:check` prints the same list on the host.

**Check:** every checklist is green and each probe is switched on. Settings → Network profiles shows each probe's address on its VLAN, its MAC, gateway and DNS, with no warnings. Each probe appears as a client on the right network in UniFi (name it there). A website check of a site you know is blocked for students shows *Blocked* for Students and *Allowed* for Staff.

## 10. Test end to end

Still in read-only mode:

1. **Check a website** from the dashboard and compare the Staff and Student results.
2. As a teacher (Users → *Preview as staff*, or a second account), **request access** to a blocked site.
3. As IT, **approve** it. The policy page shows the hostnames waiting to be added.
4. Try a **block request**, a **timed** request, and the **domain scanner** (Policy lists → Domain scanner).
5. Check the emails arrive and the **Audit log** (in the admin menu) records each step.

## 11. Go live

1. Settings → Network provider: set **Active**, save, and use **Verify write access** on a policy page (it saves the filter back unchanged).
2. On a policy, **Push** one change and confirm it in UniFi. If anything looks wrong, use **Roll back** in the push history.
3. Turn on **Push automatically** if you want approvals, expiries and timed windows applied without a person. Large automatic changes still wait for someone (Settings → *Largest automatic push*).
4. Optional: turn on **Settings → Website categories** (a free Cloudflare API token with Radar read access). Website checks and requests then show what kind of site each domain is, the UniFi category it probably falls in and which filters already block that category, which helps choose between a category block and a list of hostnames. It sends looked-up domains to Cloudflare; see [hardening](SECURITY_HARDENING.md#data).
5. Optional: add blocklists under Policy lists → Blocklists & firewall. Give a policy a layer first if you add large domain feeds.
6. Harden the install: [SECURITY_HARDENING.md](SECURITY_HARDENING.md).

## 12. Ongoing operation

| Task | How |
| --- | --- |
| Back up | Daily `pg_dump` and the storage volume, plus `.env`, kept separately; see [DEPLOYMENT.md](DEPLOYMENT.md#backups). Test a restore. |
| Update | `git pull --ff-only`, then `docker compose … build --pull` and `docker compose … up -d`. Read [CHANGELOG.md](../CHANGELOG.md) and [UPGRADING.md](UPGRADING.md) first. Rebuild monthly even without a release, for PHP, Chromium and OS patches. |
| Health | Settings → Diagnostics; `docker compose ps`; the dashboard warns when a filter drifts from UniFi. |
| Locked out | `docker compose exec app php artisan itrequests:admin-password <email> --enable-local-login` |
| Start again | See [Starting again in the README](../README.md#starting-again). |
