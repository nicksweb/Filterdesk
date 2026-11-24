# Architecture

## Components

| Component | Responsibility | Network access |
| --- | --- | --- |
| `web` | Nginx static files and FastCGI gateway | Loopback host port; app network |
| `app` | Laravel requests, policy, OIDC, provider adapters | Database plus controlled outbound |
| `queue` | Email notifications and queued work | Database plus controlled outbound |
| `scheduler` | Temporary access expiry | Database plus controlled outbound |
| `db` | PostgreSQL durable state | Internal app network only |
| `probe-student` | Chromium/DNS/HTTP check from student path | Internal control network plus student egress |
| `probe-staff` | Chromium/DNS/HTTP check from staff path | Internal control network plus staff egress |

The application calls both probes concurrently over the private `probe_control`
network. Only the application service joins that network; probes use their
separate egress network or configured VLAN for browser traffic. Each creates a
fresh browser context but reuses its Chromium process. A default 2.8-second
navigation ceiling keeps the common result within the requested 1–3 second
window; DNS, overloaded hosts and cold startup can exceed that target.

## Security boundaries

- The browser never calls probes directly. Probe APIs require a constant-time checked shared key and are not published.
- Checks accept only HTTP(S) domain URLs with no embedded credentials or IP literals.
- Probe requests are revalidated, including subresources and redirects. Private/special-use DNS targets require an explicit hostname allowlist.
- Integration credentials use Laravel's encrypted database cast and the deployment's `APP_KEY`.
- Roles are checked server-side on every IT/admin action (`role:it,admin`, `role:admin`). Request ownership is checked separately. See [SECURITY_HARDENING.md → Who can change filtering](SECURITY_HARDENING.md#who-can-change-filtering).
- Two-factor sign-in (TOTP, RFC 6238) is held between the password and the session: no session exists until the code is checked. Accounts that must use it and haven't set it up can only reach the set-up page (`RequireTwoFactor`).
- The installer is guarded by a database completion flag and returns 404 after completion.
- Forwarded headers are trusted only from `TRUSTED_PROXIES`.
- Provider writes require both `mode=active` and an adapter-reported capability.

## Provider abstraction

`NetworkProvider` defines `capabilities`, `test`, `apply` and `revoke`. Approval remains vendor-independent. If a provider cannot safely automate an approved operation, the workflow moves to `action_required`, preserving IT ownership and an audit trail.

Future Palo Alto, Fortinet or filtering-provider adapters should translate the domain request at this boundary, return opaque provider state for reversal, and never leak vendor objects into controllers or views.

## Data

Requests retain domains, scope, use case, time window, decision and opaque provider state. `request_events` form the human timeline; `audit_events` record privileged actions. Probe screenshots are returned to the current response but intentionally not persisted. Stored check records omit image bytes.
