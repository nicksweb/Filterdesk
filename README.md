# IT Requests

IT Requests is an open-source, Docker-first self-service portal for schools. Staff can compare website access from Staff and Student network paths, capture a screenshot, and request that one or more domains be allowed. IT receives an auditable workflow for questions, reassignment, approval, manual/provider action, notifications, and temporary-access expiry.

The application is school-neutral: branding, identity, network profiles, policy and notifications are configured through the first-run wizard. UniFi Network is the first provider, behind a vendor-neutral interface.

![IT Requests installation wizard](docs/screenshots/installer.png)

## Current capabilities

- OpenID Connect sign-in (including Microsoft Entra ID) with just-in-time users
- Student, teacher, IT and administrator roles
- Parallel Student/Staff network checks with a target of 1–3 seconds
- Optional Chromium screenshots and configurable block-page detection
- Multi-domain staff-only or staff-and-student requests
- Approval, rejection, reassignment, information requests and manual completion
- Temporary access windows with scheduled expiry
- Email decision notifications through a background queue
- Encrypted OIDC, SMTP and UniFi credentials
- UniFi official API connection/capability testing, defaulting to read-only
- First-run installation wizard which locks after completion
- Audit records, health checks, isolated containers and explicit proxy trust

## Quick start

Requirements: Docker Engine 27+ with Compose v2, a dedicated hostname/reverse proxy, and 4 GB free RAM if screenshots are enabled.

```bash
git clone https://github.com/nicksweb/it-edu-selfservice.git itrequests
cd itrequests
chmod +x bin/install
./bin/install
```

Edit `.env` and set at least `APP_URL` and `TRUSTED_PROXIES`. Then:

```bash
docker compose up -d --build
docker compose ps
```

Open `http://127.0.0.1:8088` locally, or configure the reverse proxy first and open `APP_URL`. The installation wizard creates the initial administrator and configures the school, OIDC, UniFi, probes, email and approval defaults.

Do not expose PostgreSQL, PHP-FPM, or either probe to the internet. The supplied Compose file publishes only Nginx on loopback.

## Network probes and VLANs

The default probes use normal Docker egress, useful for evaluation but not a real two-VLAN comparison. Create one Docker macvlan/ipvlan network per access network, copy `compose.vlan.example.yml` to `compose.vlan.yml`, update `.env`, then start with:

```bash
docker compose -f compose.yml -f compose.vlan.yml up -d --build
```

Each probe must have the intended VLAN as its default route. If a UniFi block page resolves to a private hostname, explicitly list that hostname in `STUDENT_ALLOWED_PRIVATE_HOSTS` or `STAFF_ALLOWED_PRIVATE_HOSTS`; private targets are otherwise rejected to reduce SSRF exposure.

See [deployment and operations](docs/DEPLOYMENT.md), [architecture](docs/ARCHITECTURE.md), and [UniFi compatibility](docs/UNIFI.md).

## UniFi API key required

Use an **official UniFi API key**, not a personal UI password.

1. Sign in to UniFi Site Manager and open **Settings → API Keys**, or open the local Network application's **Control Plane → Integrations** page.
2. Create a dedicated key for IT Requests and copy it immediately; it is shown once.
3. Give the associated UniFi account only the sites/permissions required.
4. Enter the controller HTTPS URL and key in the wizard. Leave the integration in **read-only** mode until testing succeeds.

The official API includes site/network discovery and firewall/ACL resources, but does not currently provide a portable content-filter *domain allowlist* operation across supported Network versions. IT Requests therefore reports that capability as unavailable and places an approved request into **Action required** for manual completion. It does not call undocumented controller endpoints. See [docs/UNIFI.md](docs/UNIFI.md).

## Updating

Configuration is stored in PostgreSQL and named volumes, outside the image. Back up first, then:

```bash
git pull --ff-only
docker compose build --pull
docker compose up -d
```

The one-shot `migrate` service applies migrations before workers start. Read [CHANGELOG.md](CHANGELOG.md) before each upgrade.

## Development

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -v "$PWD:/app" -w /app php:8.5-cli php artisan test
```

Laravel Pint is the formatter. Provider integrations implement `App\Services\Network\NetworkProvider`; workflow and UI code do not depend on UniFi-specific response shapes.

## Security

Do not open public issues containing vulnerabilities, credentials, school names, internal domains, screenshots or topology. Follow [SECURITY.md](SECURITY.md). This software can be authorised to change network policy; validate it in read-only mode and apply least privilege.

Released under the [MIT License](LICENSE).
