# Changelog

All notable changes to this project are documented here. Versions follow [Semantic Versioning](https://semver.org/).

## 0.2.0

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
- Push safety: pushes are refused when a filter list would exceed a configurable size (default 2,000; 16,000 entries made the UniFi web interface unusable), and automatic pushes stop above 200 changes until someone pushes them.
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
