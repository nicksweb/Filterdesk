# Changelog

All notable changes to this project are documented here. From 2026.11.0, versions are calendar versions `YYYY.MM.N`: the year and month of the release, then a number that counts releases within that month (`2026.11.0`, `2026.11.1`, then `2026.12.0`). Earlier releases used `0.x`.

## 2026.12.12

### Fixed
- **Internet check no longer fails because one host is intercepted**: the probe tries four unrelated endpoints (gstatic, Cloudflare, example.com, Google) and passes if any loads. A certificate error is reported with its issuer as its own warning, for example *HTTPS to www.google.com is intercepted by the gateway (certificate from UniFi SSL Certificate Authority)*, pointing at the content filter, SafeSearch, ad blocking or a traffic rule. Needs the new probe image.
- **"No probe" removes the profile** Guided setup made for that network (the built-in Students and Staff profiles are switched off instead), so its checklist no longer asks for a container. A profile with no probe in the generated file says so instead of *container not running*.
- **Importing again never makes a second policy of the same name**: an unlinked policy with the filter's name (from the installer or an earlier import) is linked instead, even if its network type differs. This caused `-2` policies, probe names and unlinked profiles.

### Added
- **Merge into another policy** (policy page): moves a duplicate's entries (without doubling), lists, networks, layer, UniFi link and probe profile into the policy that stays, then deletes it. Guided setup lists policies that share a name.
- compose.vlan.yml: each `docker network create` skips a network that already exists, and the file lists networks an earlier file created that are no longer used, with the `docker network rm` command.

## 2026.12.11

### Added
- **No probe for a network**: Guided setup, step 3, lets you leave any planned probe out (a VPN, another site, a network the host's switch port doesn't carry). Its policy still works; it just isn't shown in website checks, and compose.vlan.yml no longer includes it.

## 2026.12.10

### Fixed
- The `COMPOSE_FILE` line in Guided setup adds `compose.proxy.yml` only when the page really came through a reverse proxy (it sent `X-Forwarded-For`), not just from a trusted address.

## 2026.12.9

### Added
- **Guided setup in four steps**: connect UniFi, match your filters, place the probes, switch on and check, with progress at the top and the terms explained. Each UniFi filter is one plain choice (*Students filtering*, *Extra blocklist layer on top of…*, *Leave it alone*); linking and creating policies happen behind it.
- **Baseline filtering**: a network with no UniFi content filter gets the Students filter (or a chosen baseline policy) at the next check in Active mode, and IT is emailed, unless an admin allows it to stay unfiltered with a reason. Networks already unfiltered when this starts wait for a decision (Networks & filters → *Baseline filtering*, and the setup checklist).
- **Paused filters**: a content filter disabled in UniFi is flagged on Networks & filters, its networks count as unfiltered, and IT is emailed once. They are not changed automatically, since pausing is usually deliberate.
- **Students only see student access**: a student's website check runs only on the probes for their network types (Students unless sign-in groups say otherwise); staff and IT still see every network.
- **Which network am I on?** The check page recognises the network from the visitor's address (UniFi subnets) and says what filtering it gets; staff see that network's result first.
- **Users**: correct a name (kept at later single sign-ons), change a local account's email, and delete accounts. A deleted user's requests stay under *Deleted user*.
- **Network profiles** can be deleted.
- README screenshots of checks, Insights and Protection.

### Changed
- **compose.vlan.yml holds everything**: each probe's DNS is set in the file, so nothing goes in `.env` but one `COMPOSE_FILE` line. Extra probes extend the student probe, and each probe's only way out is its VLAN (`networks: !override`, Docker Compose 2.24 or later), so it cannot fall back to the server's network on any Docker Engine.
- **One COMPOSE_FILE line instead of `-f`** everywhere: the generated file, the page and the docs give the line to put in `.env` (with `compose.proxy.yml` when Filterdesk is reached through a proxy). `-f` replaces `COMPOSE_FILE`, and leaving out `compose.proxy.yml` caused 502s.
- The generated file checks the macvlan networks exist before starting.
- The app waits for probes to start, not to be healthy, so a probe that cannot reach its VLAN no longer keeps the admin pages down.

### Fixed
- **No `eth0` guess for the trunk interface**: the app cannot see the host's interfaces. Until the trunk is saved, the commands contain `<trunk>` and the file says *NOT READY*; the page shows how to find it with `bin/probe-interfaces`.
- **Saving Settings → Network profiles failed** (since 2026.12.0) whenever a profile existed, so probes could not be switched on.

## 2026.12.8

### Fixed
- **Group and role claims are found automatically**: with *Claims holding groups or roles* left blank (or on the old default), every claim whose name ends in groups, role or roles is used, so CloudWork ID's `https://cloudwork.id/claims/groups` and `…/role` work without configuration. Before, CloudWork users matched no `group:` rule and got the default role.

## 2026.12.7

### Added
- **Roles from directory groups**: `group:` rules match a group however the provider sends it: the whole value, the CN of a directory path (`CN=ITStaff,OU=…` matches `group:itstaff`) or the name before @ of a group email. Group claims can be lists, delimited strings or objects.
- **Users** shows the groups each person's last single sign-on carried.
- **Try the rules** (Settings → Authentication): paste an email and groups to see the role, network types and department the saved rules give.

## 2026.12.6

### Fixed
- **Single sign-on with providers other than Entra ID** (for example CloudWork ID): the userinfo endpoint is optional, client authentication follows the provider (`client_secret_post` or `_basic`, or set it), PKCE is used when advertised, extra scopes can be added, and the claims that carry groups or roles are configurable. The display name falls back to given and family name.
- Sign-in calls to the identity provider now have timeouts.
- A wrong issuer shows a readable error instead of a server error; a pasted `/.well-known/openid-configuration` URL is accepted.
- Settings → Authentication → *Provider details* shows the provider's capabilities, the reason for the last failed sign-in, and the claims of the last sign-in (personal values hidden) for writing role rules. SETUP lists issuers and redirect URIs for Entra ID, CloudWork ID and Google.

## 2026.12.5

### Added
- **Add all your networks in the installer**: step 5 has rows for networks beyond Students and Staff (guest Wi-Fi, BYOD, CCTV, a lab), each with its network type (existing or new) and VLAN. Each becomes a network profile, switched off until its probe runs; a new type gets its own lists and filter policy, and Guided setup plans its probe on that VLAN. The review step lists them.

### Fixed
- After an upgrade, pages could be drawn from the previous version's templates: compiled views lived in the shared `data/storage`. They are now kept inside each container (`VIEW_COMPILED_PATH`), compiled from that container's own image.
- The probe planner now puts every profile that names its network on that network, not only the built-in Students and Staff probes (existing plans are unchanged).

## 2026.12.4

### Fixed
- **Reverse proxy setup**: the docs gave different answers for the upstream port and `TRUSTED_PROXIES`; they now all say `http://filterdesk-web:8080` (the port inside the container, not 8088) and the shared network's subnet. `compose.proxy.example.yml` gives the web container the fixed alias `filterdesk-web`, so the upstream no longer depends on the project or folder name or clashes with other stacks' `web` services. SETUP step 6 has three checks that each separate one cause.
- The installer's proxy check suggests the network subnet as well as the single address, and reminds you to use port 8080.
- `APP_BIND` lets the web port listen beyond this host, for a reverse proxy on another machine.
- Installer: completed steps showed their tick below the circle instead of inside it.

## 2026.12.3

### Changed
- **Many people at once**: the app serves 20 requests at once instead of PHP-FPM's default of 5 (`PHP_FPM_MAX_CHILDREN`), so a few people running checks no longer stall every page.
- **Probe queue**: each probe runs at most 6 checks at once (`PROBE_MAX_CONCURRENT`); extra checks wait their turn, first come first served, for up to 20 seconds (`PROBE_QUEUE_TIMEOUT_MS`), then that network shows *This probe is busy* while the others still answer. The probe's `/health` reports how many checks are running and waiting. Probes generated for extra networks get the same settings (regenerate `compose.vlan.yml`, or add the two variables to each probe).

## 2026.12.2

### Changed
- **Data in `./data` (bind mounts)**: the database (`data/postgres`) and storage (`data/storage`) live in the install folder instead of Docker volumes, so they are easy to see, back up and move. A one-off `storage-init` step prepares `data/storage` for the app's user. Existing installs move their data with `bin/move-to-bind-mounts` (docs/UPGRADING.md).
- **Install without git**: `compose.yml` runs entirely from the published images, so an install needs only `compose.yml`, `.env.example` and the `install` script (docs/SETUP.md, step 3, option B).
- Probe: Playwright 1.65.1, with a matching base image; CI fails if the two ever differ. Dependabot now watches the app and web base images. PHPUnit 13 and actions/checkout 7 (development only).

### Security
- `data/` is excluded from image builds and from git.

## 2026.12.1

### Changed
- **IT Requests is now Filterdesk** (github.com/nicksweb/Filterdesk). Existing installs keep their data: see docs/UPGRADING.md (set `COMPOSE_PROJECT_NAME=it-edu-selfservice`).
- **Published images**: each release is built for amd64 and arm64 and published to the GitHub Container Registry as `ghcr.io/nicksweb/filterdesk-app`, `-web` and `-probe` (with an SBOM and build provenance). `compose.yml` pulls them; `FILTERDESK_VERSION` in `.env` chooses the release. `compose.build.yml` builds from source instead.
- Commands are `filterdesk:upgrade` and `filterdesk:admin-password` (the `itrequests:` names still work). Webhook headers are `X-Filterdesk-Signature` and `X-Filterdesk-Event`; metrics start `filterdesk_`; new API tokens start `fd_`.

### Security
- The image build now leaves out environment files, override compose files, backups and dumps, docs, tests, host scripts and editor or assistant folders; CI fails if the app image contains any of them.

## 2026.12.0

The strategic roadmap: the app moves from pushing lists to managing policy.

### Added
- **Changes** (Policy → Changes): what is waiting for a second IT officer, what publishing would change in each controller filter and firewall IP list, a *Publish* button, and every published **version** with a rollback. Every controller write (manual, automatic, scheduled, feed refresh, rollback) belongs to a numbered version; versions that changed nothing are not kept.
- **Two-person approval**: approving site-wide access or a site in a sensitive category, and hand-made changes to site-wide lists, wait until a second IT officer confirms them; the first officer cannot confirm their own. Sensitive categories are configurable (Settings → Approval policy). Skipped, with a note, while only one IT officer is enabled.
- **Approving alone when no other IT officer is available**: where two officers are needed, an officer can tick *Approve without a second IT officer* and give a reason (or confirm their own site-wide list change). The other officers and the IT address are emailed to review it, it is audited, and Insights counts it. Can be turned off under Settings → Approval policy.
- **"Your access is on its way"**: staff whose request is approved straight away land on a page that explains DNS filtering can take a few minutes, with steps (close the browser, restart if needed); the steps also appear on approved requests and in the approval email. Wording editable, students optional (Settings → Approval policy).
- **Setup**: the wizard asks for two-person approval, sensitive categories and review periods; the admin home shows a *Finish setting up* checklist; a footer link to the open-source project can be turned off (Settings → School). docs/SETUP.md covers every step from an empty host, including Docker live restore and scheduled backups.
- **School calendar** (Settings → School calendar): terms, holidays, exam periods and bell times. Recurring requests can follow the calendar (chosen terms, weekdays, all day or chosen periods, skipping holidays). **Exam mode** per network type (off, on, during exam periods) with its own block list, pushed automatically when exam periods start and end.
- **Access explorer** (Policies → Access explorer): the steps that decide whether a network type reaches a site at a time (filter, category, every entry with schedules, exam mode and proposals, the outcome, what the controller has now, firewall IP lists and the latest probe check). **What-if** for blocking or unblocking a category. **Why is this blocked?** for everyone, with an *Ask for access* button and a bookmarklet. Request pages show where each site stands now.
- A **policy page per network type**: its filters, blocked categories, sync, exam mode and the lists that apply.
- **Exception reviews** (Inbox → Reviews): entries made by IT or from requests have an owner and a review date (12 months by default). `exceptions:review` (daily) emails owners and IT when they fall due and removes those not kept 14 days later. Entries not seen in a check or request for a year are flagged.
- **Insights**: a termly report (requests, median decision time, decline reasons, versions, reviews, coverage gaps, departments) with CSV export and print, and suggestions drawn from requests.
- **Health**: a coverage score (networks filtered, filters in sync, zones covered by IP lists, probes, publishes, reviews), each part linked to its fix. Home pages by role.
- **Identity mapping**: sign-in rules set a person's network types (request defaults) and department.
- **Integrations** (Settings → Integrations): API v1 with hashed bearer tokens, webhooks (signed JSON or Microsoft Teams cards with Approve and Decline buttons), and signed one-click decision links in IT emails. See [docs/API.md](docs/API.md).
- `GET /api/v1/metrics` (Prometheus), `php artisan unifi:compat` (read-only controller compatibility check) and `bin/backup`.
- Navigation by job: Home, Inbox, Check, Policies, Changes, Insights, Health, Protection, Admin.

### Fixed
- A site-wide access request was approved automatically when auto-approval was on (its network-type check passed with no types listed).

## 2026.11.0

The release previously planned as 0.2.0, shipped under the new calendar versioning.

### Added
- Blocklists & firewall: subscribe to hagezi presets or any URL (hosts, adblock, wildcard, plain); domain feeds fill their own allow/block list, IP feeds and manual addresses keep a UniFi IP group in step through the official API, with firewall-rule usage shown; hourly refresh (`blocklists:refresh`).
- Allow/block precedence: the most specific list wins, then the most recent entry; feed entries never override IT or approvals. Overridden additions are reported.
- "Include www. and the bare domain" when adding hostnames.
- Timed access: IT can approve a different window from the one requested, or change it later (or make it permanent). Timed allow and block entries go live 5 minutes early and are removed 5 minutes late (configurable), and a once-a-minute scheduler pushes only the filters whose windows open or close.
- Sign-in protection: account lockout after 5 failures in 15 minutes, per-IP limit, audit of failures, lockout emails, and a per-account password sign-in switch; the recovery command re-enables it.
- Requester emails: received confirmation, scheduled approval, expiry reminder for longer access, and access ended; clearer subjects ("approved and scheduled", "access is live").
- Networks & filters: live UniFi content filters and VLANs, link filters to policies, map each VLAN to a staff, student or guest policy, mismatch warnings.
- Push policy changes to linked UniFi content filters (Active mode): change sets only, hand-made entries kept, read-back verification, push history and rollback, push all policies at once, optional automatic pushing on approval, revocation, expiry and schedule (`policy:push`), and a no-change write check.
- Guided setup from the controller: create or link a policy per content filter, import its entries into policy-owned lists, map its networks, and get per-policy probe container recommendations (macvlan command, DNS, compose snippet) with one-click probe profiles.
- The controller is the source of truth (default): entries added in UniFi are imported into the policy's own lists, managed entries removed in UniFi are excluded from that filter and never pushed back until restored, UniFi blocks win for that filter, and category edits are accepted.
- Sync checks every 15 minutes: in sync, changes to push, changed in controller (entries, categories, network attachments) or cannot check; email alert on drift, dashboard banner, accept-controller-changes action.
- Networks attached to more than one content filter are detected and reported.
- Manage a linked filter's blocked content categories (Active mode).
- Fetch a policy's current lists straight from the controller, and import entries added in the controller by hand (allow and block) into a managed list.
- Domain scanner: hostnames a page loads on each network plus optional certificate-transparency subdomains, added to any allow or block list in one step.
- Administrators can view the app as a staff member or student (or built-in preview accounts), with a banner to return and the real actor recorded in the audit log.
- Adding hostnames reports where they are already listed and whether a controller filter blocks them.
- Audience-aware filter policies (Staff, Student, Guest, Other) with site-wide, audience and per-policy allow/block lists.
- Per-policy reconciliation: paste the controller lists, review add/remove/review sets, copy or download the desired lists, mark in sync.
- Manual hostname entries with optional start and expiry. Site-wide entries reach every policy, including students.
- DNS-aware approval recommendations: registrable domain (Public Suffix List), www alias, and subdomains and third-party hosts observed during checks.
- Probe reports every host a page loads and which failed; recognises DNS sinkhole answers.
- Fresh Chromium per check run, DoH disabled, optional uncached direct DNS (`*_PROBE_DNS_SERVERS`), and a re-run button.
- IT action panel: assignee dropdown of enabled IT staff, assign to me, approve with selected hostnames and scope override, revoke, internal notes.
- Request queue tabs, assignment filter and search.
- Email to IT on new requests, replies and assignment; daily reconciliation digest; per-user opt-in.
- Admin settings for school, authentication, email (with test send), network provider (test and discovery), network profiles and approval policy.
- OIDC role rules by email domain or group/role claim, default role or deny, optional sync at sign-in.
- Audit log viewer, diagnostics page and `/health` endpoint.
- UniFi Site Manager (unifi.ui.com) keys via Ubiquiti's cloud connector, with console discovery; clear guidance when a Site Manager key is used locally. Short console IDs from the web UI are resolved to the full connector ID.
- `itrequests:upgrade`, `itrequests:admin-password` and `policy:digest` commands; versioned upgrades.

### Fixed
- Push safety: pushes are refused when a filter list would exceed a configurable size (default 100,000, UniFi's practical ceiling), and automatic pushes with more than a configurable number of changes (default 1,000) wait until someone pushes them. The policy page warns above 5,000 entries that the UniFi web interface will be slow to open the filter.
- Automatic pushes after an approval, revocation or window change run in the background queue, so the page answers at once (several filters through the cloud connector could exceed the 30 s gateway timeout and show a 504). The web timeout is now 120 s and the queue worker allows 300 s.
- Firewall blocking rules: the Blocklists page shows the networks in each zone and lists the exact rules before they are created (one per IP group and zone, because a UniFi rule has one source zone, one destination zone and one group). Incoming blocks now cover every ticked zone and the Gateway, not only Internal. **Put these rules first** moves the app's rules above your own rules for the same zones, and the page warns when one of your Allow rules is checked first. Rules deleted in UniFi are recreated, and creating rules without an External zone is refused clearly instead of making a zone-to-itself rule.
- Resilience: an unreachable UniFi controller is reported as a normal error everywhere (it crashed the hourly blocklist refresh and returned a 500 on firewall pushes); a test loads every page and runs every scheduled job with UniFi, all probes and the category service offline.
- **Recurring requests**: access or blocks on chosen weekdays, all day (on at 1 am) or set hours (an hour's margin either side, configurable), every 1–4 weeks, for up to 6 months; pushed on and off automatically.
- Branding: change the application name (for example "uConnect"), the header initials and upload a browser/header icon under Settings → School.
- Speed: pages show the last known UniFi state immediately and refresh it in the background; IP groups, zones and firewall rules are cached for 5 minutes. Manual pushes (policy, push all, firewall lists and rules) run in the background with an in-progress note.
- Checks: screenshots wait for the page to finish loading (PROBE_TIMEOUT_MS default 8000, PROBE_SETTLE_MS 1500); a probe whose setup checklist fails shows "probe not working" instead of a misleading DNS result.
- Firewall IP lists: lists over 20,000 addresses are split across several UniFi IP groups; **Create blocking rules in UniFi** creates and maintains a Block rule per group and zone (optionally incoming too); unchanged lists are not re-pushed after scheduled refreshes; deleting a list removes the rules it created.
- Category guard: if a category becomes blocked after a smart approval, the affected approved sites get an allow entry automatically (stored categories, no extra lookups; daily refresh of only those sites). Category lookups are capped at 300 an hour.
- **Smart approval**: with website categories on, approving access removes a block IT added instead of adding an allow entry when the site's category is not blocked, adds nothing when nothing blocks it, and only adds allow entries to override a blocked category, a feed or an import. The approval panel shows the plan per hostname.
- **Email templates**: every email's subject, greeting, body and button are editable under Settings → Email templates, with placeholders, a preview and reset to default.
- Request form: the reason field's title and hint are configurable; it can be optional for staff and required for students, and students get their own category list (Settings → Approval policy).
- Email: *None* encryption now means plain SMTP that is never upgraded to TLS (internal relays on port 25), and certificate verification can be turned off for an internal server reached by IP. The installer asks for the encryption too.
- **Website categories** (optional, off by default): Cloudflare Radar or Intel categorises checked and requested domains; checks, the request form and request pages show the categories, the matching UniFi content category and which linked filters block it. Categories are saved on each request when it is submitted.
- Probe addresses: set a probe's IP by hand (subnet, gateway and DNS still come from UniFi), reserve it for the probe's MAC in UniFi in one click, or move it to a free address outside the DHCP pool; DHCP relay networks are recognised. Checklist warnings can be marked reviewed, and collapsed checklists stay collapsed.
- **Probe setup checklist**: each probe tests itself (container, VLAN, gateway, DNS, internet) and Settings → Network profiles turns the result into a step-by-step checklist with fixes, including switch-port and Proxmox/VMware/Hyper-V advice; reruns every 10 minutes (`probes:check`), with reminders on the dashboard and in Guided setup.
- **Network types**: the fixed Staff / Student / Guest / Other audiences are now types you define under Settings → Network types (for example CCTV or Exam rooms), each with its own shared allow and block lists, a *requestable* and an *auto-approve* switch. Requests and approvals choose any set of types or site-wide instead of the fixed *Staff only / Staff and students* choices. Existing data is converted on upgrade.
- Docs: full setup guide with every `.env` variable (`docs/SETUP.md`) and a security hardening checklist including post-quantum TLS (`docs/SECURITY_HARDENING.md`).
- Installer: the first page warns when the reverse proxy is not trusted (pages would load unstyled) and names the address to put in `TRUSTED_PROXIES`.
- Probe identity: probes report their VLAN address, MAC, gateway and DNS (`/identity`), shown under Settings → Network profiles with a warning when a probe is off its VLAN; generated probes get a fixed address and MAC.
- Guided setup: **Bring everything in** imports every UniFi content filter in one step (reusing the default policies, recognising layers, importing all entries in bulk, mapping networks, preparing probe profiles); the dashboard points new installs at it.
- Probes: one saved trunk interface with each probe on `<trunk>.<VLAN>` (tagged), an optional per-probe interface for hosts with a NIC per network, and `bin/probe-interfaces` to list host interfaces and confirm which VLAN tags reach the trunk. The setup page generates the whole `compose.vlan.yml` (also `php artisan probes:compose`) with the host commands, and plans extra probes for any network, such as guest Wi-Fi or BYOD, beyond Staff and Students.
- Precedence: an allow added by IT or an approved request now beats a block from a feed or a controller import at any level (before, a feed or import in a policy's own list beat an audience-wide approval). Adding such an allow says which blocks it overrides.
- Layers: a second UniFi content filter on the same networks as a policy, created from the policy page, carries that policy's blocklist feeds. Filters on one network add up and a block wins, so the app leaves anything the policy allows out of the layer. Layers are pushed before their parent and copy its categories.
- Entries the app wrote to the controller and later dropped (deleted entries, feeds switched off) are removed from the controller instead of being imported back as hand-made.
- Pagination uses a compact pager instead of unstyled framework markup.
- A block for one audience was silently ignored when the hostname was also allowed site-wide or by an approval ("allow always wins").
- Policy comparisons scale to feed-sized lists (set lookups instead of nested scans; plain rows instead of models).
- Docker build: the Composer stage ignores only upper-bound PHP constraints, so builds keep working when the `composer:2` image moves to a newer PHP than the 8.5 runtime.
- Settings were cached as Eloquent objects and silently fell back to defaults (school name, URL, timezone, Schoolbox origin, local login flag).
- Temporary windows were stored as wall-clock time in a UTC column; times displayed in UTC.
- New OIDC users were created with a null `enabled` attribute and refused at first sign-in.
- Assignment accepted any user ID; it is now restricted to enabled IT staff.
- Disabled accounts kept working until their session ended.

### Security
- OIDC accounts match on the immutable subject; email matching is used only for pre-staged accounts that have no subject.
- The installer no longer keeps the administrator's plain-text password in the session.
- At least one enabled administrator must remain.

## 0.1.0

- Initial release.
