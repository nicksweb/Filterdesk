# Upgrading

Configuration lives in PostgreSQL and the `app_storage` volume, outside the
image, so upgrades keep settings and data.

## Standard upgrade

```bash
# 1. Back up (database + .env, which holds APP_KEY)
docker compose exec -T db sh -c 'pg_dump -U $POSTGRES_USER -d $POSTGRES_DB -Fc' > itrequests-$(date +%F).dump
cp .env .env.backup

# 2. Update and rebuild (include any override files you normally use)
git pull --ff-only
docker compose -f compose.yml -f compose.proxy.yml up -d --build

# 3. Check the upgrade output
docker compose logs migrate
```

The one-shot `migrate` service runs `php artisan itrequests:upgrade`, which:

1. refuses versions too old to upgrade directly;
2. runs migrations;
3. backfills defaults added by the release (filter policies, lists, entries for already-approved requests);
4. clears compiled caches;
5. restarts queue workers;
6. records the installed version (see *Settings → Diagnostics*).

`app`, `queue` and `scheduler` start only after it succeeds. It is safe to re-run:

```bash
docker compose run --rm migrate
```

## 0.1.x → 0.2.0

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
docker compose exec -T db sh -c 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB --clean --if-exists' < itrequests-YYYY-MM-DD.dump
git checkout <previous-tag> && docker compose up -d --build
```

## Emergency administrator access

```bash
docker compose exec app php artisan itrequests:admin-password admin@example.edu.au --enable-local-login
```
