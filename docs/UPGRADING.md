# Upgrading

Configuration lives in PostgreSQL and the `app_storage` volume, outside the
image, so upgrades keep settings and data.

## Standard upgrade

```bash
# 1. Back up (database + .env, which holds APP_KEY)
docker compose exec -T db sh -c 'pg_dump -U $POSTGRES_USER -d $POSTGRES_DB -Fc' > filterdesk-$(date +%F).dump
cp .env .env.backup

# 2. Choose the release: set FILTERDESK_VERSION in .env (for example 2026.12.1),
#    then pull it (include any override files you normally use)
git pull --ff-only          # for the latest compose.yml, docs and bin/ scripts
docker compose -f compose.yml -f compose.proxy.yml pull
docker compose -f compose.yml -f compose.proxy.yml up -d

# Building from source instead: FILTERDESK_VERSION=local, add -f compose.build.yml,
# build app, web and probe-student one at a time, then up -d.

# 3. Check the upgrade output
docker compose logs migrate
```

The one-shot `migrate` service runs `php artisan filterdesk:upgrade`, which:

1. refuses versions too old to upgrade directly;
2. runs migrations;
3. backfills defaults added by the release (filter policies, lists, entries for already-approved requests);
4. clears compiled caches;
5. restarts queue workers;
6. records the installed version (see *Health → Diagnostics*).

`app`, `queue` and `scheduler` start only after it succeeds. It is safe to re-run:

```bash
docker compose run --rm migrate
```

## Version numbers

From 2026.11.0, releases use calendar versions `YYYY.MM.N` (year, month, then the release number within that month). They compare correctly with the earlier `0.x` versions, so upgrading from 0.1.x works as before.

## 2026.12.0 → 2026.12.1: the rename to Filterdesk

IT Requests is now **Filterdesk**, and releases are published as container images, so upgrades pull instead of build.

1. **Keep your project name** (your database volume and containers are named after it). Add to `.env`:

   ```bash
   COMPOSE_PROJECT_NAME=it-edu-selfservice
   FILTERDESK_VERSION=2026.12.1      # or local, to keep building from source
   ```

   Without the first line Docker Compose would start a new, empty install called `filterdesk`.
2. Replace `APP_IMAGE_TAG` in `.env`, if present, with `FILTERDESK_VERSION`.
3. If you generated `compose.vlan.yml`, regenerate it (`docker compose exec -T app php artisan probes:compose > compose.vlan.yml`) or change each probe's `image:` to `${FILTERDESK_IMAGE_PREFIX:-ghcr.io/nicksweb/filterdesk}-probe:${FILTERDESK_VERSION:-latest}`.
4. Pull and start as in *Standard upgrade*. With `COMPOSE_PROJECT_NAME` kept, your reverse proxy still reaches `it-edu-selfservice-web-1`.
5. Changed names: commands are `filterdesk:upgrade` and `filterdesk:admin-password` (the old `itrequests:` names still work); webhook headers are `X-Filterdesk-Signature` and `X-Filterdesk-Event` (update any receiver that checks the signature); metrics start `filterdesk_`; new API tokens start `fd_` (existing tokens keep working); `bin/backup` writes `filterdesk-*.dump`.

## 2026.11.0 → 2026.12.0

- **Two-person approval is on by default.** With two or more enabled IT officers, approving site-wide access or a sensitive category, and adding or removing entries in a site-wide list by hand, waits for a second officer (Policy → Changes). Turn it off, or change the sensitive categories, under Settings → Approval policy.
- **Exception reviews.** Existing entries made by IT or from requests get an owner and a review date a year after they were made, but never sooner than four weeks after the upgrade, so nothing is removed because of the upgrade. Owners are emailed when entries fall due; unreviewed ones are removed 14 days after their review date. Change both periods under Settings → Approval policy.
- **Versions.** Every write to the controller now appears under Policy → Changes as a numbered version. Push history on each policy page still works.
- Optional: add the school calendar (Settings → School calendar), identity rules (Settings → Authentication) and integrations (Settings → Integrations).
- Back up with `bin/backup` before upgrading.

## 0.1.x → 2026.11.0 (formerly 0.2.0)

- **Policy model.** The single master allow/block lists become *Site-wide allow/block*. Requests already approved are placed into the Staff/Student allow lists according to their scope. Paste each UniFi content filter into its policy page to start reconciling.
- **Timezone.** Temporary windows saved by 0.1.x were stored as the school's wall-clock time. They are converted to UTC once. Dates now display in the school's timezone (*Settings → School*).
- **Settings cache fix.** Settings such as school name, public URL and Schoolbox origin were silently ignored after being cached. They now apply. If a Schoolbox origin is set, session cookies become `SameSite=None; Secure`, so access the app over HTTPS.
- **OIDC roles.** New SSO users get the role from *Settings → Authentication → Role rules* (default: teacher). Add a rule such as `email_domain:students.example.edu.au=student` so students are not created as teachers.
- **Probes.** Rebuild the probe image (`--build`). Each run uses a fresh browser with DoH disabled. Set `*_PROBE_DNS_SERVERS` for uncached lookups against each VLAN's resolver.
- **Admin pages.** *Integrations* moved to *Settings → Network provider*. *Audit log* and *Diagnostics* are new.

## Rollback

Migrations are forward-only. To roll back, restore the pre-upgrade dump and
start the previous tag:

```bash
docker compose stop app queue scheduler
docker compose exec -T db sh -c 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB --clean --if-exists' < filterdesk-YYYY-MM-DD.dump
# set FILTERDESK_VERSION back to the previous release, then
docker compose pull && docker compose up -d
```

## Emergency administrator access

```bash
docker compose exec app php artisan filterdesk:admin-password admin@example.edu.au --enable-local-login
```
