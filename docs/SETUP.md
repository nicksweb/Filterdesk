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
| Hostname | For example `filterdesk.example.edu.au`, with a DNS record pointing at your reverse proxy. |
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

Turn on Docker's **live restore**, so containers keep running if the Docker daemon restarts or crashes (otherwise every container on the host stops with it):

```bash
echo '{ "live-restore": true }' | sudo tee /etc/docker/daemon.json
sudo systemctl reload docker
docker info --format '{{.LiveRestoreEnabled}}'   # true
```

If the host already has a `/etc/docker/daemon.json`, add the `"live-restore": true` line to it instead.

**Check:** `docker run --rm hello-world` prints its greeting.

## 3. Get Filterdesk and create .env

**Option A: clone the repository** (recommended; you also get `bin/backup`, `bin/probe-interfaces` and the docs):

```bash
git clone https://github.com/nicksweb/Filterdesk.git filterdesk
cd filterdesk
./bin/install
```

**Option B: just the files needed to run it** (no git; the published images contain everything else):

```bash
mkdir filterdesk && cd filterdesk
base=https://raw.githubusercontent.com/nicksweb/Filterdesk/main
curl -fsSLO $base/compose.yml -O $base/.env.example -O $base/compose.proxy.example.yml
curl -fsSL $base/bin/install -o install && curl -fsSL $base/bin/backup -o backup && chmod +x install backup
./install
```

Read a script before running it if you prefer: both are short shell scripts.

Either way, Filterdesk keeps its data in the install folder: `data/postgres` (the database) and `data/storage` (uploads, screenshots and logs). Back up that folder and `.env`; `bin/backup` does both.

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
| `APP_NAME` | `Filterdesk` | Shown in the browser title and emails. |
| `APP_ENV` | `production` | Leave as `production`. |
| `APP_KEY` | *generated* | Encrypts sessions and every stored credential (UniFi key, OIDC secret, SMTP password). **Back it up with the database.** Without it, those credentials cannot be recovered. Never change it on a running install. |
| `APP_DEBUG` | `false` | Must stay `false` in production; `true` shows stack traces to users. |
| `APP_URL` | `https://filterdesk.example.edu.au` | The exact public URL, including `https://`. It is used in emails and links. For a local trial use `http://127.0.0.1:8088`. |
| `APP_TIMEZONE` | `Australia/Brisbane` | Your timezone. Access windows, expiries and digests use it. |
| `APP_LOCALE` | `en` | Interface language. |
| `TRUSTED_PROXIES` | `127.0.0.1` | The IP address (or comma-separated list, or CIDR) of the reverse proxy *as the app sees it*. If wrong, the app thinks every request is plain HTTP: **pages load without styling** and secure cookies fail. The wizard's first page tells you the address to use. Never use `*` if the port is reachable by anyone other than the proxy. |
| `APP_PORT` | `8088` | Port the web container publishes, on `127.0.0.1` only. Change it if 8088 is taken. |
| `FILTERDESK_VERSION` | `latest` | Which published release to run: `latest`, a month such as `2026.12`, or an exact release such as `2026.12.1` (recommended, so upgrades happen when you choose). `local` when building from source with `compose.build.yml`. |
| `FILTERDESK_DATA` | `./data` | Where the database and storage are kept (bind mounts). |
| `COMPOSE_PROJECT_NAME` | — | Only for installs from before the rename that keep their old container names: `it-edu-selfservice`. |

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
| `DB_DATABASE` / `DB_USERNAME` | `filterdesk` | Leave. |
| `DB_PASSWORD` | *generated* | Set once, before the first start. The database in `data/postgres` keeps the password it was created with, so changing it later locks the app out. |

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
| `PROBE_TIMEOUT_MS` | `8000` | How long a check waits for a page to load. |
| `BLOCK_PAGE_PATTERN` | `blocked\|access denied\|…` | Regular expression for text on your block page, used to recognise a blocked result. |
| `BLOCK_PAGE_ADDRESSES` | *empty* | Extra comma-separated IPs that mean "blocked" in a DNS answer. UniFi's answer (`203.0.113.250`, `2001:db8:1000::fa`), `0.0.0.0`, `::` and the documentation ranges always count, so UniFi needs nothing here. The probe checklist offers to save any other block answer it sees (Settings → Network profiles → Block answers), which works without editing `.env`. |
| `STUDENT_PROBE_DNS_SERVERS` / `STAFF_PROBE_DNS_SERVERS` | *empty* | Leave empty. The generated `compose.vlan.yml` sets each probe's DNS (its VLAN gateway) itself; these are only for running the two built-in probes without it. |
| `STUDENT_ALLOWED_PRIVATE_HOSTS` / `STAFF_ALLOWED_PRIVATE_HOSTS` | *empty* | Hostnames on private addresses that a probe may load (for example an internal block page). Everything else private is refused, to stop the probes being used to reach internal systems. |
| `PROBE_REUSE_BROWSER` | `false` | Keep `false`, so every check starts with an empty DNS cache. |

### Capacity (several people at once)

A website check runs while the person waits: the app asks every probe at once, and each probe opens a browser. On a typical host (6 cores) one probe handled 20 checks at once in 5–8 seconds each. The defaults below suit a school; raise them on a bigger host, lower them on a small one.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PHP_FPM_MAX_CHILDREN` | `20` | How many requests the app serves at once (about 40 MB each). A check holds one for its whole run, so this is roughly how many people can check at the same moment without anyone's pages waiting. |
| `PROBE_MAX_CONCURRENT` | `6` | Checks each probe runs at once (one browser each, about 120 MB and most of a core while loading). Extra checks wait their turn. |
| `PROBE_QUEUE_TIMEOUT_MS` | `20000` | How long a check waits for a turn before that network shows *This probe is busy*. Keep it, plus `PROBE_TIMEOUT_MS`, under the app's 45-second limit. |

### Reverse proxy override

| Variable | Default | What to set |
| --- | --- | --- |
| `REVERSE_PROXY_NETWORK` | `frontend` | Only with `compose.proxy.yml`: the Docker network your proxy container is on. |

### Which compose files (`COMPOSE_FILE`)

| Variable | Default | What to set |
| --- | --- | --- |
| `COMPOSE_FILE` | `compose.yml` | Every compose file the install uses, separated by `:`. Add `compose.proxy.yml` for a reverse proxy in Docker (step 5) and `compose.vlan.yml` once the probes are set up (step 9), for example `compose.yml:compose.proxy.yml:compose.vlan.yml`. |

Every `docker compose` command (`up`, `pull`, `logs`, `ps`, `exec`) then uses all of them, so this guide never uses `-f`. Don't add `-f` yourself either: it replaces `COMPOSE_FILE` for that command, and leaving out `compose.proxy.yml` takes the web container off the proxy's network (*502 Bad Gateway*).

## 5. Start the stack

Choose one:

- **Proxy on the host, or no proxy (trial):**

  ```bash
  docker compose pull
  docker compose up -d
  ```

- **Proxy in another Docker container** (for example Nginx Proxy Manager):

  ```bash
  cp compose.proxy.example.yml compose.proxy.yml
  nano .env        # set COMPOSE_FILE=compose.yml:compose.proxy.yml
  docker compose pull
  docker compose up -d
  ```

This pulls the published images from the GitHub Container Registry (`ghcr.io/nicksweb/filterdesk-app`, `-web` and `-probe`); the probe image is large (Chromium), so the first pull takes a few minutes. Set `FILTERDESK_VERSION` in `.env` to an exact release (for example `2026.12.1`) so the install only changes when you upgrade.

To **build from source** instead (for development, or to change the code), set `FILTERDESK_VERSION=local`, add `compose.build.yml` to `COMPOSE_FILE` (for example `compose.yml:compose.build.yml`), and build one image at a time:

```bash
docker compose build app
docker compose build web
docker compose build probe-student
docker compose up -d
```

 The one-off `migrate` container sets up the database, then the rest start.

**Check:** `docker compose ps` shows `db`, `web`, `probe-student` and `probe-staff` as `healthy`, `app`, `queue` and `scheduler` as running, and `migrate` exited with code 0. Also `curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/up` prints `200`.

If something fails, `docker compose logs migrate app web` usually shows why.

## 6. Set up the reverse proxy

Point the proxy at the app:

| Proxy | Upstream |
| --- | --- |
| On the host (Nginx, Caddy) | `http://127.0.0.1:8088` |
| Nginx Proxy Manager or Traefik in Docker, with `compose.proxy.yml` | `http://filterdesk-web:8080` |
| A proxy on **another machine** (for example an existing Nginx Proxy Manager or NPMplus host) | `http://<this host's address>:8088`, after setting `APP_BIND=0.0.0.0` in `.env` and running `docker compose up -d`. Don't use `compose.proxy.yml`. Firewall port 8088 so only the proxy can reach it. |

> **8080, not 8088.** `8088` (`APP_PORT`) is published only on the host's loopback, for a proxy running on the host. A proxy in another container must use port `8080`. Use 8088 there and you get *502 Bad Gateway* (*connection refused* in the proxy's error log).
> Use the full name `filterdesk-web`, not `web`: other stacks on a shared network often have a `web` service too, and the proxy may reach the wrong one.
> `COMPOSE_FILE` in `.env` must include `compose.proxy.yml` (step 5), so every `docker compose` command keeps the web container on the proxy's network.

In Nginx Proxy Manager: add a proxy host for your hostname with that upstream (scheme `http`), request a certificate, and turn on **Force SSL**, **HTTP/2** and **HSTS**. The proxy must send `X-Forwarded-Proto` and `X-Forwarded-For`, which all of the proxies above do by default. A plain Nginx example is in [DEPLOYMENT.md](DEPLOYMENT.md#reverse-proxy).

Then set `TRUSTED_PROXIES` to the proxy's address:

- Proxy on the host: `127.0.0.1` (the default).
- Proxy on another machine: that machine's address, for example `10.0.0.5`.
- Proxy in Docker: the shared network's subnet, for example `172.18.0.0/16`, from
  `docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}' <shared-network>`.
  Use the subnet rather than the proxy's own address: a proxy on several networks has several addresses, and Docker can give it a new one when it is recreated; pages then load without styles.
  Trusting the subnet means any container on that network could send forwarded headers. That is acceptable because the app's port is published only on 127.0.0.1 and the network holds only containers you run, but keep other stacks off it if you don't trust them.

Apply it with `docker compose … up -d --force-recreate`.

**Check:** opening `https://<hostname>` shows a styled installer page. If not, these three checks each separate one cause (`<proxy>` is the proxy container, for example `npmplus`):

| Check | Command | Expected |
| --- | --- | --- |
| The proxy can reach the app | `docker exec <proxy> wget -qO- -S http://filterdesk-web:8080/up 2>&1 \| head -1` (or, where the proxy has `curl` instead: `docker exec <proxy> curl -s -o /dev/null -w '%{http_code}' http://filterdesk-web:8080/up`) | `HTTP/1.1 200 OK` (or `200`). *Connection refused* = wrong port. *Bad address* = wrong name, or the stack was started without `compose.proxy.yml`. |
| The proxy host uses the right upstream (Nginx Proxy Manager) | `docker exec <proxy> grep -rA3 '^upstream\|proxy_pass\|set \$server\|set \$port' /data/nginx/proxy_host/ \| grep -i filterdesk` | `filterdesk-web` and `8080` |
| The app trusts the proxy | `curl -s https://<hostname>/install/1 \| grep -o 'href="[^"]*\.css"'` | Starts with `https://`. If it shows `http://`, `TRUSTED_PROXIES` is wrong; the installer's first page names the address and subnet it sees. |

## 7. Run the installation wizard

Open `https://<hostname>`. The wizard has eight steps and locks itself once finished.

| Step | Enter | Tips |
| --- | --- | --- |
| 1. System checks | Nothing | All four checks should be ✓. A `!` against *Proxy* means `TRUSTED_PROXIES` is wrong. |
| 2. School | Name, timezone, the URL (same as `APP_URL`), Schoolbox origin | Fill in the Schoolbox origin (for example `https://schoolbox.example.edu.au`) only if the app will be embedded in Schoolbox. |
| 3. Administrator and sign-in | The first local admin's name, email and password (14+ characters). Optionally OIDC: issuer, client ID, secret, allowed email domains, role rules, default role, *Re-apply rules at every sign-in* (on), and under **Provider-specific options** any extra scopes and the claims that hold groups. | For Entra ID the issuer is `https://login.microsoftonline.com/<tenant-id>/v2.0`. Role rules map groups or emails to *student*, *teacher*, *IT* and *admin*. For CloudWork ID, the profile scope is added for you when a rule uses `group:`. Press **Test sign-in** before continuing (see below). Leave **Emergency local sign-in** on until SSO is proven. |
| 4. UniFi | Connection type, the console URL (local key) or a Site Manager key, **Test connection**, then the console to manage and its site, **Read-only** mode, entry matching *Explicit* | **Test connection** checks the key without saving anything. With a Site Manager key it lists the consoles the key can see: pick the one to manage and test again to see its networks and content filters, so you know it's the right one. Start read-only. All of this can be changed later under Settings → Network provider. |
| 5. Network access profiles | Keep `http://probe-student:3000` and `http://probe-staff:3000`; optionally the Student and Staff VLAN numbers (for example 20 and 10). Under **Other networks**, add every other network to filter and test (guest Wi-Fi, BYOD, CCTV, a lab): its name, its network type (an existing one, or *New type…* with a name) and its VLAN | The VLANs are matched to UniFi networks once connected, and Guided setup plans a probe on each (step 9). Extra networks start switched off until their probe container runs; a new type gets its own allow and block lists and filter policy. |
| 6. Email | SMTP host, port, username, password, from address, IT notification address | The IT address receives new requests, alerts and the daily digest. Settings → Email has a test button. |
| 7. Approval policy | Auto-approval for trusted networks, whether students may request, default access length, request categories, **two-person approval** (site-wide changes need two IT officers; on), approving alone when no other officer is available (on), **sensitive categories**, and how often exceptions are **reviewed** (12 months) and how long an unreviewed one stays (14 days) | Defaults are sensible. Two-person approval only takes effect once two IT officers are enabled. All of this can be changed later under Settings → Approval policy. |
| 8. Review | Check, then **Complete** | |

Sign in as the administrator. The home page shows **Finish setting up**: a checklist of what is left (Guided setup, probes on their VLANs, a second IT officer, the school calendar, website categories, Active mode and backups). Items tick themselves off as you complete them; mark the rest done by hand.

**Check:** Settings → Network provider → **Test connection** succeeds and redirects you to Guided setup. Health → Diagnostics is all green except probes you have not set up yet.

### Single sign-on providers

Any standard OpenID Connect provider works. The **redirect URI** to register is exactly `https://<hostname>/auth/oidc/callback` (shown under Settings → Authentication). Filterdesk reads the provider's discovery document and follows it: PKCE when advertised, the client authentication method it accepts, and the ID token when there is no userinfo endpoint.

| Provider | Issuer | Notes |
| --- | --- | --- |
| Microsoft Entra ID | `https://login.microsoftonline.com/<tenant-id>/v2.0` | Add a *groups* claim or app roles to use `group:` role rules. |
| CloudWork ID | `https://<school>-id.cloudworkengine.net`, as shown under Single Sign On → Manage OpenID Connect Apps → Identity Provider | Create the app under Single Sign On → Manage OpenID Connect Apps → Add New Service, with the redirect URI above (not `/auth/callback`). CloudWork sends `email`, `given_name`, `family_name` and `preferred_username`; for its custom claims (such as SIS ID) add the extra scope `https://scopes.cloudwork.id/profile` under Settings → Authentication → Provider details. |
| Google Workspace | `https://accounts.google.com` | Restrict sign-in with *Allowed email domains*. |

**Roles from groups.** Rules under Settings → Authentication give each person a role, network types and a department from their groups, at every sign-in when *Re-apply rules at every sign-in* is on. A `group:` rule matches the whole value, the CN of a directory path, or the name before @ of a group email, ignoring case, so all of these match `group:itstaff`: `ITStaff`, `CN=ITStaff,OU=User Groups,DC=school,DC=example` and `itstaff@school.example`. Put the most powerful rules first (first match wins):

```text
group:itstaff=admin
group:it-staff-security-group=it
group:admin-staff=teacher
email_domain:students.school.example=student
```

*Claims holding groups or roles* can stay blank: Filterdesk then uses every claim whose name ends in groups, role or roles (Entra's `groups` and `roles`, CloudWork ID's `https://cloudwork.id/claims/groups` and `https://cloudwork.id/claims/role`, whose value is for example `faculty`, so `group:faculty=teacher` works). Name the claims only if yours are called something else, such as `memberOf`. *Try the rules* on the same page shows what a set of groups would give someone, and Users shows the groups each person's last sign-in carried.

**Test sign-in.** On the wizard's sign-in step and under Settings → Authentication, **Test sign-in** sends you to the provider with the settings as they are and back to the normal redirect URI (nothing extra to register). It shows the claims that came back (personal values hidden), every group or role, and *With these rules this account would be: admin (matched `group:itstaff=admin`)*. Each group has buttons (*Admin*, *IT*, *Teacher*, *Student*) that add the rule for you. No account is created and nobody is signed in. If no groups come back, it says so and names the scope or setting the provider needs: CloudWork ID only sends groups and roles with the scope `https://scopes.cloudwork.id/profile`; Entra ID needs a groups claim or app roles on the app registration; Google sends none. To test another person's account, sign out of the provider first.

**Fixing roles after the fact.** With *Re-apply rules at every sign-in* on (the installer's default), a corrected rule fixes each account at its next sign-in. Settings → Authentication also lists **Accounts the rules would change**, worked out from the groups each person had at their last sign-in, with **Apply the rules to these accounts**. IT and admin access is never taken away by rules. An account created before its groups arrived (for example before the CloudWork scope was added) has no groups recorded: it is put right at its next sign-in.

If sign-in fails, Settings → Authentication → *Provider details* shows the provider's reason (for example a redirect URI that is not registered) and, after a successful sign-in, the claims it sends, for writing role rules.

## 8. Bring UniFi in

**Policies → Guided setup** (the dashboard links to it) takes you through four steps: connect UniFi, match your filters, place the probes, switch on and check. Step 2 lists each UniFi content filter with what it will become, in plain words (for example *Students → Students filtering*). Choose **Bring everything in**. It:

- creates a policy for each UniFi content filter, reusing the default *Students* and *Staff* policies where the network type fits;
- recognises a second filter on the same networks that is named or shaped like a blocklist as a *layer*;
- imports every allow and block entry, maps networks to policies and keeps categories as they are;
- prepares a disabled probe profile per policy.

**What Filterdesk learns.** It reads how your filters are organised and shows it as a table laid out like UniFi's Content Filter page (name, networks, categories, SafeSearch, schedule), with what Filterdesk will manage each filter as:

- **Site-wide layers:** filters on most networks (for example *Allow (Sitewide)* and *Block (Sitewide)*). They carry the site-wide lists. One of them *takes the site-wide blocks*: new site-wide blocks go only into it, not into every filter, which keeps each filter under UniFi's limit of about 100,000 entries. Allows still go into every filter on the networks concerned, because UniFi blocks a site if any filter on the network blocks it.
- **Groups:** one filter per type of user (Staff, Students, Guests…). The schedule is read from UniFi and shown in words, for example *Mon–Fri 08:30–15:10*.
- **School-hours layers:** a filter on a schedule beside an always-on filter for the same type (for example *Student - School Time* beside *Students - All Hours*). See [School-hours filters](#school-hours-filters) below.
- **Blocklist layers:** a second filter on exactly the same networks, for big feeds.
- **User and device networks:** from each network's name and filters. Device and infrastructure networks don't need a group filter, but adding Students filtering protects them if a student gets on; *Networks & filters → Network check* suggests it with a button.

**Networks & filters** then shows every VLAN as one row: the filters on it in UniFi, whether it is a user or device network, the policy it belongs to here, and a single status with the button that fixes it (add Students filtering, map it to the right policy, allow it unfiltered with a reason, or add and remove filters on it). The *Needs attention* tab lists only what to look at. Changes apply in place without reloading.

Stacked filters like these are normal and no longer show as *Changed in controller*: that status now only means someone edited a filter's lists or categories in UniFi. A network mapped to one policy but filtered differently in UniFi shows as *Networks differ*.

**Leftovers.** *Leave it alone* unlinks a policy that was managing that filter. *Not used by any filter* lists policies that manage no filter and hold only imported entries, and switched-off probe profiles nobody uses (or on a VPN with no VLAN), to delete in one go. Entries that look like two hostnames typed without a line break (`wistia.comfast.wistia.net`) are flagged on the policy page with *Split* or *Keep*.

Nothing changes in UniFi. If a suggestion is wrong, open **Change a choice** and pick what each filter is for: filtering for a type of network (Students, Staff, Guest…), an *extra blocklist layer* on top of another filter (optional; only for big blocklist feeds), or *leave it alone*. *Advanced* picks a particular policy or skips importing.

**Networks with no filter (baseline filtering).** The *No filter* tab on Networks & filters lists every network without a working UniFi content filter. Baseline filtering is a safety net with firm limits:

- **It starts after Guided setup**, once a Students policy is linked to a UniFi filter. Every network unfiltered at that moment waits for a decision: add Students filtering, or **Allow unfiltered** with a reason (a server, management or ISP network, for example). It starts again the same way if you connect another console or site.
- **After that, a new *user* network** (or one that loses its filter) gets the always-on Students filter at the next check (every 15 minutes), in Active mode only, and IT is emailed.
- **A new *device* network** (infrastructure, ISP or WAN hand-offs, cameras, phones…) is never filtered automatically. It is listed for a decision and IT is emailed. Whether a network is a user or device network is learned from its name; change it in the row's *Kind* column.
- **Paused filters are left alone.** A network whose filters are all paused in UniFi is flagged, never changed.
- **Never a scheduled filter on its own.** If the only Students filter is on a school-hours schedule, nothing is added automatically until you choose the baseline filter yourself.
- **Read-only waits.** In read-only mode nothing is changed. Networks found then wait for a decision, so switching Active mode on doesn't filter them all at once.
- **Buttons say what they do.** *Add …* names the UniFi filter, when it applies and its categories, and warns when the network is a device network.
- **It can be undone.** Every addition (automatic or from a button) shows at the top of Networks & filters (*Filterdesk added Students - All Hours to 3 networks on 24 Nov, by …*) with **Take it off again**, and each chip it added says *added 24 Nov*. Taking it off removes that filter from those networks and lists them for a decision; the guard does not add it back.

The dashboard reminds admins until every network has a decision.

### School-hours filters

Many schools run two filters for students: one always on, and a stricter one that UniFi switches on in school hours. Filterdesk manages the second as a **school-hours layer** of the first policy (*Students: all hours + school-hours layer*):

- It keeps its own allow and block lists and its UniFi schedule. Filterdesk never writes the schedule.
- Everything the policy allows also goes into it, so an approval for students works in school hours too. Without this, a site approved in *All Hours* could still be blocked by *School Time* during the day.
- Its own blocks stay in it and are not copied to the always-on filter, so they apply only in school hours.
- When approving a request for a network type with a school-hours layer, **When** offers *All hours* (the default: both filters) or *Outside school hours only* (left out of the school-hours filter, so it stays blocked there while it is on, if that filter blocks it).
- A network that has only the school-hours filter gets that type's filtering only in school hours. Networks & filters says so on its row (*Students filtering only in school hours…*) with **Add Students - All Hours**, and the policy's sync status lists those networks instead of reporting *Networks differ*.

Guided setup suggests this when it finds a scheduled filter beside an always-on one of the same type. If the scheduled filter was brought in earlier as the main policy (for example *Students*), converting it moves the probe profile to the all-hours policy and swaps the names, so the all-hours policy is *Students* and the layer is named after its UniFi filter; the two filters' lists are never merged. You can also set it under *Networks & filters → Content filters → Managed by → School-hours layer of …*. A filter that was brought in earlier as a policy of its own becomes the layer, keeping its lists, and its networks move to the main policy.

**How UniFi combines filters on one network.** Ubiquiti does not document how several content filters on the same network combine. Filterdesk assumes the cautious case: every filter that is on applies, and a site blocked by any of them is blocked, so an allow in one filter does not undo a block or blocked category in another. That is why allows go into every filter on a network, and why a school-hours block wins during its hours. To check this on your own console: put a test hostname in one filter's block list and another filter's allow list on the same VLAN, then run a website check for it from that VLAN's probe (or a device on it), during and outside the schedule.

Rename or add network types under **Settings → Network types** if Staff and Students don't describe your networks (for example CCTV or Exam rooms), and set each policy's type.

**Check:** each policy page says *In sync*, and its change set (to add, to remove) is empty. *Networks & filters* shows each network with the policy you expect.

## 9. Put the probes on the VLANs

A check is only meaningful if the probe resolves DNS from the same network as the people it represents. UniFi filters DNS at the gateway, per network. So each probe gets its own interface on its policy's VLAN (macvlan), a fixed address, and that VLAN's gateway as its DNS server. This is not host networking: the Docker host's own network settings are untouched.

Guided setup, step 3 (*Place the probes*), walks through this and shows the exact commands for your networks.

1. **Tag the VLANs on the host's switch port.** In UniFi, give the port a profile with the host's normal network untagged (native) and each probe VLAN tagged. On a VM, the hypervisor must pass the tags too (see [DEPLOYMENT.md](DEPLOYMENT.md)).
2. **Find the trunk interface.** Filterdesk runs in a container and can't see the host's interface names, so it never guesses. On the host, in the Filterdesk folder:

   ```bash
   ./bin/probe-interfaces                      # lists interfaces; ends with "Suggested trunk: ens18"
   sudo ./bin/probe-interfaces --listen ens18  # confirms which VLAN tags arrive on ens18
   ```

   Most current systems name it `ens18`, `eno1` or `enp3s0`, not `eth0`.
3. **Enter it** in Guided setup, step 3, tick any **extra probes** (guest Wi-Fi, BYOD, a lab), and **Save**. Use *Different interface* only if a network reaches the host on another NIC. Until the trunk is saved, the commands show `<trunk>` so they can't be run by mistake.
4. **Keep the probe addresses out of DHCP.** Each probe has a fixed address near the top of its subnet, shown on the page. Make sure the UniFi DHCP range ends below it, or reserve it (the probe's checklist can do that for you).
5. **On the host**, in the Filterdesk folder:

   ```bash
   docker compose exec -T app php artisan probes:compose > compose.vlan.yml
   head -40 compose.vlan.yml            # run the 'docker network create' commands listed there, once
   docker network ls --filter driver=macvlan   # must list every network the file names
   nano .env                            # COMPOSE_FILE=compose.yml:compose.proxy.yml:compose.vlan.yml
   docker compose up -d
   ```

   Leave out `compose.proxy.yml` if you don't use it (the page and the file say which applies). The file holds everything each probe needs, DNS included, so nothing else goes in `.env`. Each probe's only way out is its VLAN, so it can never fall back to the server's own network and report the wrong filtering. This needs Docker Compose 2.24 or later (`docker compose version`).
6. **Switch them on.** Under Settings → Network profiles, run each probe's checklist and tick *On* once it passes. Guided setup, step 4, shows where each one stands.

**Finish each probe with its checklist.** Settings → Network profiles runs a setup checklist for every probe: network chosen, container running, on its VLAN, gateway reachable, DNS through the gateway, internet access, the browser using the VLAN's DNS, blocked sites detected (it looks up hostnames on the network's filter block list and expects a block answer), network filtered, address outside DHCP, switched on. Each failed step says what to fix (for example "give the switch port a profile that carries VLAN 20 tagged" or "turn off the Proxmox MAC filter"). The checklist reruns every 10 minutes, the dashboard reminds admins while any probe is unfinished, and `docker compose exec app php artisan probes:check` prints the same list on the host.

If a probe can't start (its VLAN network is missing, say), the rest of Filterdesk still starts, so you can open its checklist and fix it.

**Website checks: what people see.** Under Settings → Network profiles → *Website checks*:
- **Page previews** (the screenshot and page title) are for IT only by default. Students and staff see whether a site is allowed or blocked on each network, but not the page itself, so a student checking an inappropriate site doesn't see it. You can open previews to staff, or to everyone.
- **Check method** can be set to *DNS only*. UniFi's content filter blocks at DNS, so a DNS answer settles most checks in about a second, and the probe never fetches the site. It can't see a block page, HTTPS inspection, or parts of a page that fail to load, and it shows no previews.

A probe's address may sit inside the DHCP pool if it is reserved for the probe's MAC: in UniFi (the checklist's *Reserve in UniFi* button does it), or on your own DHCP server behind a relay (reserve it there, then press *It's reserved on our DHCP server*).

**Check:** every checklist is green and each probe is switched on. Settings → Network profiles shows each probe's address on its VLAN, its MAC, gateway and DNS, with no warnings. Each probe appears as a client on the right network in UniFi (name it there). A website check of a site you know is blocked for students shows *Blocked* for Students and *Allowed* for Staff.

## 10. Test end to end

Still in read-only mode (two IT officers make step 6 possible):

1. **Check a website** from the dashboard and compare the Staff and Student results.
2. As a teacher (Users → *Preview as staff*, or a second account), **request access** to a blocked site.
3. As IT, **approve** it. The policy page shows the hostnames waiting to be added.
4. Try a **block request**, a **timed** request, and the **domain scanner** (Policies → Domain scanner).
5. Check the emails arrive and the **Audit log** (Admin → Audit log) records each step.
6. **Two-person approval:** approve a *site-wide* request as one IT officer; it waits under **Changes** until a second officer approves it. Try *No other IT officer is available?* with a reason, and check the other officer is emailed.
7. **Explain access:** Policies → Access explorer for a site you know is blocked; it names the step that decides. As a teacher, try **Why is this blocked?**.
8. **Access guide:** as a teacher, request a staff-only site with auto-approval on; you land on *Your access is on its way* (Settings → Approval policy changes its wording).

## 11. Go live

1. Settings → Network provider: set **Active**, save, and use **Verify write access** on a policy page (it saves the filter back unchanged).
2. Open **Changes**: it lists what publishing would write to each filter. **Publish** it, confirm the change in UniFi, then try **Roll back** on that version under *Versions*.
3. Turn on **Push automatically** if you want approvals, expiries and timed windows applied without a person. Large automatic changes still wait for someone (Settings → *Largest automatic push*).
4. Recommended (it can also be turned on in the installer, step 7): turn on **Settings → Website categories** (a free Cloudflare API token; add your account ID and the *Account → Intel → Read* permission so any site can be categorised, as Radar alone only knows popular sites). Sensitive-category approvals, smart approval and the category guard need it. Website checks and requests then show what kind of site each domain is, the UniFi category it probably falls in and which filters already block that category, which helps choose between a category block and a list of hostnames. It sends looked-up domains to Cloudflare; see [hardening](SECURITY_HARDENING.md#data).
5. Optional: add blocklists under Protection. Give a policy a layer first if you add large domain feeds.
6. Harden the install: [SECURITY_HARDENING.md](SECURITY_HARDENING.md).
7. Policy settings worth a look before go-live:
   - **Users:** add (or give the IT role to) a second IT officer, so two-person approval works.
   - **Settings → Approval policy:** two-person approval for site-wide changes (on by default), approving alone when no other officer is available, the sensitive categories that need two IT officers, how often exceptions are reviewed (12 months) and how long an unreviewed one stays (14 days), and the wording of *Your access is on its way*, the page staff see after access is approved (close the browser, restart if needed).
   - **Settings → School:** the application name, icon, and whether the footer links to the open-source project.
   - **Settings → School calendar:** terms, holidays, exam periods and bell times, for recurring requests that follow the calendar and for exam mode (Settings → Network types).
   - **Settings → Authentication:** network type and department rules from sign-in groups.
   - **Settings → HTTPS inspection:** if the gateway inspects HTTPS (UniFi Deep Inspection), upload its CA's public certificate. Filterdesk offers it at `/certificate` with install steps for Windows, macOS, iOS, Android and ChromeOS, and website checks that hit an inspected site link there. Never upload the private key.
   - **Settings → Integrations:** API tokens, and webhooks to a Teams channel or a service desk ([API.md](API.md)).
   - Publish waiting changes, and roll versions back, under **Changes**. Check **Health** for the coverage score.

## 12. Ongoing operation

Schedule backups first: `crontab -e` on the host, then add (with your own path and compose files):

```cron
30 2 * * * cd /opt/filterdesk && bin/backup >> backups/backup.log 2>&1
```

Copy `backups/` to another machine; it holds the database and `.env` (with `APP_KEY`), so protect it like a password store.

| Task | How |
| --- | --- |
| Back up | `bin/backup` (a `pg_dump` and a copy of `.env`, kept 14 days in `backups/`); schedule it daily with cron and copy `backups/` off the host. See [DEPLOYMENT.md](DEPLOYMENT.md#backups). Test a restore. |
| Update | `docker compose pull`, then `docker compose up -d` (from source: `git pull --ff-only`, then `docker compose build` one image at a time and `up -d`). Read [CHANGELOG.md](../CHANGELOG.md) and [UPGRADING.md](UPGRADING.md) first. Rebuild monthly even without a release, for PHP, Chromium and OS patches. |
| Health | **Health** (coverage score), Health → Diagnostics, `docker compose ps`; `GET /api/v1/metrics` for Prometheus. The dashboard warns when a filter drifts from UniFi. |
| Before a UniFi upgrade | `docker compose exec app php artisan unifi:compat` on a test console (or straight after upgrading): it checks, read-only, that every call the app uses still answers as expected. |
| Users | Users: correct a name (a corrected single sign-on name is kept at later sign-ins), change a local account's email, or **Delete** an account. A deleted user's requests stay under *Deleted user*, and the audit log keeps who they were. |
| Locked out | `docker compose exec app php artisan filterdesk:admin-password <email> --enable-local-login` |
| Start again | See [Starting again in the README](../README.md#starting-again). |
