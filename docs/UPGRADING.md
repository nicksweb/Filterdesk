# Upgrading

Configuration lives in PostgreSQL and the storage folder (`data/` from 2026.12.2), outside the
image, so upgrades keep settings and data.

## Standard upgrade

```bash
# 1. Back up (database + .env, which holds APP_KEY)
docker compose exec -T db sh -c 'pg_dump -U $POSTGRES_USER -d $POSTGRES_DB -Fc' > filterdesk-$(date +%F).dump
cp .env .env.backup

# 2. Choose the release: set FILTERDESK_VERSION in .env (for example 2026.12.1),
#    then pull it (include any override files you normally use)
git pull --ff-only          # for the latest compose.yml, docs and bin/ scripts
docker compose pull       # COMPOSE_FILE in .env lists your compose files
docker compose up -d

# Or all of the above in one go, also in a folder that isn't a git checkout:
bin/update

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

## 2026.12.30 → 2026.12.31

- Update as usual. Migrations add a new-parts note to firewall lists and a flag to requests sent with the short form.
- **Students get the short request form** from now on (websites and why). Choose who gets it under Settings → Approval policy → *Short request form for*.
- **Requester emails use new default wording**, unless you customised them, and end with your helpdesk contact. To send one email when access works instead of two, untick *It is approved, before it works on the network* under Settings → Email and notifications.
- **Firewall rules:** if Filterdesk already made firewall rules for you, it keeps managing them. Choose *IP lists only* under Settings → Network provider to manage the rules yourself; existing rules stay in UniFi.
- **Always protected:** your helpdesk's support article site and email domain, this app's address and the single sign-on server can't be blocked any more. Check Settings → Protected sites.

## 2026.12.29 → 2026.12.30

- Update as usual. No database changes; security updates to Symfony components.

## 2026.12.28 → 2026.12.29

- Update as usual. No database changes; a security update to the mailer library.

## 2026.12.27 → 2026.12.28

- Update as usual. A migration adds processing options and statistics to feeds.
- Existing IP feeds are processed from their next refresh: private and reserved addresses, and addresses on a firewall allow list, are dropped, and neighbouring addresses are merged. Feeds now read the whole list before applying their limit, so a capped list keeps the widest ranges rather than the first lines.

## 2026.12.26 → 2026.12.27

- Update as usual. A migration adds the allow rule options (source networks, placement) and the last check time.
- Allow rules already in UniFi are kept. Their invalid-traffic exclusion only applies to rules created from now on: to have it on existing ones, untick the zones and save, then tick them again, or delete the rules in UniFi and push.
- Check the probe checklist for the new *Firewall zone found* step.

## 2026.12.25 → 2026.12.26

- Update as usual, including the probe image. No database changes.

## 2026.12.24 → 2026.12.25

- Update as usual, **including the probe image** (`bin/update` pulls it). A migration adds firewall allow lists to the firewall lists table.
- The UniFi API key already used for firewall IP lists is enough: allow lists use the same IP list and firewall rule endpoints.
- Nothing changes in UniFi until an admin creates an allow list and pushes it. Existing block lists keep their rules. When an allow list is pushed, its Allow rules go to the top of each zone's order, above the block lists' rules.

## 2026.12.23 → 2026.12.24

- Update as usual. No database changes.

## 2026.12.22 → 2026.12.23

- Update as usual. No database changes.
- After updating, check Policies & lists → Content filters for policies whose UniFi filter was deleted, and keep, relink or delete each one.

## 2026.12.21 → 2026.12.22

- Update as usual (`bin/update`). A migration adds the push hold to filter policies.
- **Protected sites are on from the start.** If one of your filters blocks a default protected site on purpose (google.com, bing.com, microsoft.com, apple.com…), the next push takes that block out. Check Settings → Protected sites first, remove any you need to block, and add your own (your country's Google, for example google.com.au, and your school's domains).
- When the controller is the source of truth (the default), the sync check now remaps networks whose filter changed in UniFi. Each change is in the audit log (`policy.network_mapping_followed`).
- Linking a filter on Networks & filters now puts that policy on hold. Push it from its page once you've checked the plan.

## 2026.12.20 → 2026.12.21

- Update as usual (`bin/update`). A migration adds the two-factor sign-in columns to users.
- **IT officers and admins who sign in with a password are asked to set up two-factor sign-in** at their next sign-in. They need an authenticator app. Single sign-on users are not asked unless you turn on *Also after single sign-on* (Settings → Authentication). To keep the old behaviour for now, set *Required for* to *Nobody*, or set `TWO_FACTOR_REQUIRED_FOR=off` in `.env` before upgrading.
- *Remember me* after single sign-on now lasts 30 days (`AUTH_REMEMBER_MINUTES`), so people sign in again once a month.
- The default wording people see changed (dashboard, sign-in, website checks). Change it under Settings → School → *Wording people see*, and add a sign-in logo there. Add your helpdesk and support article under Settings → Help and support.

## 2026.12.19 → 2026.12.20

- Update as usual. No database changes.
- If Filterdesk is embedded in a portal, users who sign in there now finish sign-in in a popup window. Make sure the portal's origin is set as the embedding origin (Settings → School → *Embedding origin*).

## 2026.12.18 → 2026.12.19

- Update as usual (`git pull --ff-only`, or `bin/update`). No database changes.
- Students and staff now see plain results in website checks. IT sees the same detail as before.
- To let teachers ask for blocks in school hours only, set Settings → Approval policy → *Block requests: when*. By default IT decides when approving.

## 2026.12.17 → 2026.12.18

- **This release changes `compose.yml`** (the queue and scheduler join `probe_control`). In a git checkout, `git pull --ff-only` as usual. In a plain folder (copied, not cloned), get the new `bin/update` once and run it. It backs up, pulls, copies the new `compose.yml`, example overrides and `bin/` scripts out of the image (keeping the old ones in `backups/`), and restarts:

  ```bash
  docker run --rm --entrypoint cat ghcr.io/nicksweb/filterdesk-app:2026.12.18 /var/www/html/bin/update > bin/update && chmod +x bin/update
  bin/update
  ```

  If you added a local override that puts `queue` and `scheduler` on `probe_control`, you can remove it.
- **Regenerate `compose.vlan.yml`** (Guided setup, step 3, or `docker compose exec -T app php artisan probes:compose > compose.vlan.yml`), then `docker compose up -d`. It now sets `dns:` for each probe. The new probe image already makes its browser use the VLAN's DNS without it.
- `BLOCK_PAGE_ADDRESSES=203.0.113.250,2001:db8:1000::fa` is no longer needed for UniFi (it is the default). Leaving it set does no harm.
- Run each probe's checklist (Settings → Network profiles → Re-run checks). The new steps *Browser uses the VLAN's DNS* and *Blocked sites are detected* should pass.
- **Page previews are now IT only.** Students and staff see the results without screenshots or page titles. Change it under Settings → Network profiles → Website checks, where you can also switch checks to DNS only.

## 2026.12.16 → 2026.12.17

- Pull and restart as usual.
- Networks & filters lists every filter Filterdesk added to networks for baseline filtering (including from 2026.12.13's *Add Students filtering* buttons) that is still on them, with **Take it off again**. If you already removed them in UniFi, nothing is listed.
- Guided setup now lists **Not used by any filter**: delete leftover policies and probe profiles from earlier runs there.
- If you converted a filter into a school-hours layer on 2026.12.15 and the names or probe stayed on the layer, fix them by hand once (policy page → Policy settings). Conversions from now on hand them over.

## 2026.12.15 → 2026.12.16

- Pull and restart as usual.
- **If baseline filtering added a filter to networks it shouldn't have** (for example infrastructure or ISP networks), open Networks & filters: each automatic addition is listed at the top with **Take it off again**. Those networks are then listed under *No filter* to decide one by one (add Students filtering, or allow them unfiltered with a reason). From this version, new device networks are never filtered automatically, a scheduled filter is never used on its own, and the guard starts again (asking, not filtering) when you change console or site.

## 2026.12.14 → 2026.12.15

- Pull and restart as usual. A migration adds school-hours layers and the *When* choice on approvals.
- **Scheduled filters:** if you have a school-hours filter beside an always-on one for the same type (for example *Student - School Time* and *Students - All Hours*), open Guided setup: it suggests *School-hours layer of Students*. Run **Bring everything in**, or set it under *Networks & filters → Content filters → Managed by*. Its allows and blocks are kept. Then check **Policies → Changes**: the school-hours filter gains the policy's allows (that is the point), and should lose nothing.
- **Single sign-on:** if accounts got the wrong role because no groups arrived, add the provider's scope under Settings → Authentication → Provider details (CloudWork ID: `https://scopes.cloudwork.id/profile`), turn on *Re-apply rules at every sign-in*, and use **Test sign-in** to check. Accounts are put right at their next sign-in, or straight away from *Accounts the rules would change*.

## 2026.12.12 → 2026.12.13

- Pull and restart as usual; the probe image changed too.
- Open Guided setup: step 2 shows what Filterdesk learned from UniFi (site-wide layers, groups, user and device networks) and a preview laid out like UniFi's Content Filter page. Run **Bring everything in** again, or set each filter under *Change a choice*, so filters on every network (such as *Allow (Sitewide)*) become site-wide layers. Nothing changes in UniFi.
- Then look at **Policies → Changes** before publishing: with a site-wide layer that takes the site-wide blocks, site-wide blocks leave the group filters, so their change sets may show removals of entries that the site-wide filter now carries.

## 2026.12.8 → 2026.12.9 (and 2026.12.10)

- Pull and restart as usual (migrations add baseline filtering and kept names).
- **Move to one `COMPOSE_FILE` line.** In `.env`, list every compose file you use once, for example `COMPOSE_FILE=compose.yml:compose.proxy.yml:compose.vlan.yml`, then use plain `docker compose …` without `-f`. Remove any duplicate `COMPOSE_FILE` lines.
- **Regenerate compose.vlan.yml** (Guided setup, step 3, or `docker compose exec -T app php artisan probes:compose > compose.vlan.yml`), then `docker compose up -d`. Check the trunk interface on that page first: the old `eth0` default is gone. The `*_DOCKER_NETWORK` and `*_PROBE_DNS_SERVERS` lines in `.env` are no longer needed and can be removed. Your macvlan networks keep their names, so they don't need recreating.
- **Baseline filtering** starts at the first sync check after the upgrade. Networks that are unfiltered then are only listed (Networks & filters → *Baseline filtering*) for you to filter or allow; nothing is added to a filter until you decide. Networks that lose their filter later are filtered automatically in Active mode.

## 2026.12.2 → 2026.12.3

- Pull and restart as usual. The app now serves 20 requests at once and each probe queues checks beyond 6; adjust with `PHP_FPM_MAX_CHILDREN`, `PROBE_MAX_CONCURRENT` and `PROBE_QUEUE_TIMEOUT_MS` in `.env` (docs/SETUP.md, *Capacity*).
- Extra probes in `compose.vlan.yml` only get the queue settings if you regenerate the file (`docker compose exec -T app php artisan probes:compose > compose.vlan.yml`) or add `PROBE_MAX_CONCURRENT: ${PROBE_MAX_CONCURRENT:-6}` and `PROBE_QUEUE_TIMEOUT_MS: ${PROBE_QUEUE_TIMEOUT_MS:-20000}` to each probe's `environment`. Without them a probe still uses the same defaults.

## 2026.12.1 → 2026.12.2: data in ./data (bind mounts)

Data now lives in the install folder: `data/postgres` and `data/storage`, instead of Docker volumes. Existing installs must move their data **before** starting 2026.12.2, or it starts as a new, empty install (the old volumes are not touched, so nothing is lost, but you would see the installer).

```bash
bin/backup                                        # 1. back up
docker compose … down                             # 2. stop (keeps the volumes)
git pull --ff-only                                # 3. get compose.yml and bin/move-to-bind-mounts
bin/move-to-bind-mounts                           # 4. copies <project>_db_data and _app_storage into ./data
docker compose … pull && docker compose … up -d   # 5. start
```

`bin/move-to-bind-mounts` finds the volumes of a `filterdesk` or `it-edu-selfservice` project (or name the project as its argument), refuses to run while the database is up or if `data/postgres` already has data, and leaves the volumes in place. Once everything is there, remove them with `docker volume rm <project>_db_data <project>_app_storage`.

The `data/` folder is owned by the containers' users (PostgreSQL: 70, the app: 82); a one-off `storage-init` step sets up `data/storage` on every start. Use `sudo` to read it from the host.

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
5. Optional, **moving to the new container names** (`filterdesk-*`): back up (`bin/backup`), stop the old project (`docker compose … down`, which keeps its volumes), copy each volume into a new one, remove `COMPOSE_PROJECT_NAME` from `.env`, and start again:

   ```bash
   for v in db_data app_storage; do
     docker volume create --label com.docker.compose.project=filterdesk --label com.docker.compose.volume=$v filterdesk_$v
     docker run --rm -v it-edu-selfservice_$v:/from:ro -v filterdesk_$v:/to alpine cp -a /from/. /to/
   done
   ```

   Point your reverse proxy at `filterdesk-web:8080` (with the alias from `compose.proxy.example.yml`), or keep it unchanged by giving the web container the old name as an alias in `compose.proxy.yml` (`networks: reverse_proxy: aliases: [it-edu-selfservice-web-1]`). Remove the old `it-edu-selfservice_*` volumes once you are sure everything is there.
6. Changed names: commands are `filterdesk:upgrade` and `filterdesk:admin-password` (the old `itrequests:` names still work); webhook headers are `X-Filterdesk-Signature` and `X-Filterdesk-Event` (update any receiver that checks the signature); metrics start `filterdesk_`; new API tokens start `fd_` (existing tokens keep working); `bin/backup` writes `filterdesk-*.dump`.

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
