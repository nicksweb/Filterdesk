# Changelog

All notable changes to this project are documented here. From 2026.11.0, versions are calendar versions `YYYY.MM.N`: the year and month of the release, then a number that counts releases within that month (`2026.11.0`, `2026.11.1`, then `2026.12.0`). Earlier releases used `0.x`.

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
