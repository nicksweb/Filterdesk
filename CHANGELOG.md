# Changelog

All notable changes to this project are documented here. Versions follow [Semantic Versioning](https://semver.org/).

## 0.2.0

### Added
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
- UniFi Site Manager (unifi.ui.com) keys via Ubiquiti's cloud connector, with console discovery; clear guidance when a Site Manager key is used locally.
- `itrequests:upgrade`, `itrequests:admin-password` and `policy:digest` commands; versioned upgrades.

### Fixed
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
