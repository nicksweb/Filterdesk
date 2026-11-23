# Security hardening

Filterdesk can change what students and staff can reach, and it holds a UniFi API key that can change network policy. Treat it like any other network management tool. This checklist goes in priority order. Do the **Essential** items before going live, and the **Recommended** ones soon after.

To report a vulnerability, see [SECURITY.md](../SECURITY.md).

## What is exposed by default

| Component | Reachable from | Notes |
| --- | --- | --- |
| `web` (Nginx) | `127.0.0.1:8088` on the host, plus the proxy network with `compose.proxy.yml` | The only published port. Put it behind an HTTPS reverse proxy. |
| `app`, `queue`, `scheduler` (PHP) | Internal Docker networks only | Outbound internet for UniFi, OIDC, SMTP and blocklist downloads. |
| `db` (PostgreSQL) | The internal `app` network only | No published port. |
| Probes | The internal `probe_control` network, plus their VLAN (macvlan) | They accept checks only with `PROBE_SHARED_KEY`, and refuse private addresses unless listed. |

The app sends a strict Content Security Policy, HSTS on HTTPS, `nosniff`, a restrictive Permissions Policy and `frame-ancestors` limited to the configured Schoolbox origin. Credentials for UniFi, OIDC and SMTP are encrypted in the database with `APP_KEY`.

## Essential

### Host and Docker

- [ ] Use a dedicated VM or host for this stack, and keep the OS patched (for example `unattended-upgrades`).
- [ ] Allow SSH only from IT networks, with keys, not passwords. Firewall everything else inbound except 443 to the reverse proxy (`ufw` or your perimeter firewall).
- [ ] Only trusted admins should be in the `docker` group. Docker access is root access to the host and can read `.env`.
- [ ] Never publish `db`, `app` or the probe ports. Leave `compose.yml`'s `127.0.0.1:` binding on `web`.

### Secrets

- [ ] `.env` is `chmod 600` and owned by the service account (`bin/install` does this). Never commit it. It is ignored by git.
- [ ] `APP_KEY`, `DB_PASSWORD` and `PROBE_SHARED_KEY` are the random values from `bin/install`, not copied from another install or an example.
- [ ] `APP_DEBUG=false` and `APP_ENV=production`.
- [ ] Keep a copy of `.env`, specifically `APP_KEY`, somewhere safe and separate from the database backups, such as a password manager or vault. Anyone holding both can decrypt the stored UniFi key.

### HTTPS and the reverse proxy

- [ ] Serve only over HTTPS, with **Force SSL** and **HSTS** on at the proxy.
- [ ] `TRUSTED_PROXIES` is exactly the proxy's address. Never use `*` while port 8088 is reachable by anything else, or clients can spoof their IP and defeat the sign-in rate limits.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] If the app is staff-only, restrict it at the proxy to school networks or VPN (an access list in Nginx Proxy Manager). At minimum, restrict `/login`, which is password sign-in, to IT networks.

### Sign-in

- [ ] Use single sign-on (OIDC, for example Entra ID) for everyone, with **allowed email domains** set, and require MFA in your identity provider (Conditional Access in Entra ID).
- [ ] Use role rules so only the IT group gets *IT* and only a small admin group gets *admin*. Set the default role to *teacher* or *deny*, not *admin*.
- [ ] Once an SSO admin can sign in, **turn off password sign-in for the installer's local admin** (Users → untick *Password*), or turn off *Emergency local sign-in* under Settings → Authentication. Keep the break-glass command documented instead:
  `docker compose exec app php artisan filterdesk:admin-password <email> --enable-local-login`
- [ ] Any remaining local password is 14+ characters, unique and stored in your password manager. Built-in protection locks an account after 5 failures in 15 minutes and blocks an IP after 20. Lockouts are emailed and audited.

### UniFi

- [ ] Create the API key under a **dedicated UniFi admin account** for this app (for example `filterdesk-service`), not a person's account. Then it can be revoked on its own and its changes are attributable in UniFi.
- [ ] Give that account only the **Network** application, with the lowest role that works: a view-only role while in Read-only mode, an admin role only once you switch to Active. Do not give it UniFi OS owner rights, Protect, Access or other apps.
- [ ] Prefer a **local console key** over a Site Manager key when the host can reach the console. A Site Manager key reaches every console its owner owns, through the cloud.
- [ ] Stay in **Read-only** mode until testing is complete. In Active mode keep **Largest list per content filter** and **Largest automatic push** at their defaults unless you have a reason. They stop a bad feed or a mistake from rewriting a filter wholesale.
- [ ] With **controller is the source of truth** on (the default), changes made by hand in UniFi are kept, never overwritten. Leave it on unless you have a reason to change it.
- [ ] Rotate the key if anyone who saw it leaves, or if the host is rebuilt from an untrusted backup: create a new key, enter it under Settings → Network provider, then delete the old one in UniFi.

### Backups

- [ ] Back up the database and storage volume daily, encrypted, and off the host ([DEPLOYMENT.md → Backups](DEPLOYMENT.md#backups)). Keep `.env` separately, as above.
- [ ] Test a restore at least once a term.

## Recommended

### Network placement

- [ ] Put the Docker host on a server or management VLAN, not a student network.
- [ ] Probe VLAN interfaces carry only the probes' own browsing traffic. In UniFi, consider a firewall rule so the probes' fixed addresses cannot reach internal subnets on those VLANs. They already refuse private targets themselves.
- [ ] Name each probe client in UniFi (by its fixed MAC) so unexpected traffic from those addresses stands out.

### Monitoring and audit

- [ ] Check **Health → Diagnostics** after every change, and watch the dashboard for *Web filters need attention* (drift from UniFi).
- [ ] Review the **Audit log** regularly. It records sign-ins, lockouts, approvals, every push and rollback, setting changes and imports from UniFi.
- [ ] Forward container logs to your log system (`docker compose logs`, or a Docker logging driver), and alert on repeated `controller_push_refused` or sign-in lockouts.
- [ ] Make sure the IT notification address is a monitored shared mailbox, since sync alerts and lockouts go there.

### Updates

- [ ] Apply releases promptly. Rebuild at least monthly even without one (`docker compose … build --pull && docker compose … up -d`) to pick up PHP, Alpine, Chromium and PostgreSQL patches.
- [ ] Watch the repository's releases and security advisories.

### Data

- [ ] Requests contain staff names, emails and the reasons given. Set a retention rule that matches your school's privacy policy, and limit who has *IT* and *admin* roles.
- [ ] **Website categories** (Settings → Website categories) send every checked or requested domain, without user details, to Cloudflare. It is off by default; turn it on only if your privacy policy allows it. Give the token read access only (Radar or Intel), nothing else.
- [ ] Screenshots of checked sites are stored in the app's storage volume. Include them in backups and in your retention rule.
- [ ] Before a Schoolbox embed, set the exact Schoolbox origin. Without it, the app cannot be framed at all, which is the safe default.

### Blocklists

- [ ] Subscribe only to lists from publishers you trust. Lists are downloaded at run time, and a compromised list could block legitimate sites, but cannot allow anything: allows always come from IT or approvals.
- [ ] Keep large domain feeds in a **layer** and keep the list limits in place.

## Preparing for quantum computers

A large quantum computer would break today's **public-key** cryptography (RSA and elliptic curves: TLS key exchange, certificates, SSH keys). It would not meaningfully break **symmetric** encryption with 256-bit keys or modern password hashing. The practical risk now is *harvest now, decrypt later*: someone records encrypted traffic today and decrypts it once such a computer exists. Session cookies, OIDC tokens, the UniFi API key and request data all cross the network inside TLS, so the work is mostly about TLS key exchange. Hybrid post-quantum key exchange (`X25519MLKEM768`, ML-KEM per NIST FIPS 203, combined with X25519) is already standard in current browsers, OpenSSL 3.5+, Go and Cloudflare. It is safe to enable, because the connection stays as strong as X25519 even if ML-KEM were ever broken.

| Part | Status | What to do |
| --- | --- | --- |
| Browser → reverse proxy | Depends on the proxy's TLS library | **Most important.** Use a proxy built on OpenSSL 3.5+ (or BoringSSL or Go) and offer `X25519MLKEM768` first. Caddy 2.10+ and Traefik builds on Go 1.24+ do it by default. For Nginx with OpenSSL 3.5+: `ssl_ecdh_curve X25519MLKEM768:X25519:prime256v1;`. Nginx Proxy Manager images built on OpenSSL 3.0 cannot do it yet; check with `docker exec <npm> openssl version` and upgrade, or put Cloudflare (or a tunnel) in front, since Cloudflare negotiates post-quantum TLS with browsers. Confirm in Chrome: DevTools → Security shows `X25519MLKEM768`. |
| Proxy → app | Plain HTTP inside Docker or on `127.0.0.1` | No network exposure. Keep it on the host or internal Docker network. |
| App → UniFi, OIDC, SMTP, blocklists | The app image uses OpenSSL 3.5, which offers `X25519MLKEM768` automatically | Nothing to configure. It is used wherever the far end supports it. At the time of writing, UniFi's cloud connector (`api.ui.com`) negotiates X25519 only. A **local console key** keeps that traffic on your own network instead of the internet, which limits what can be harvested. |
| Stored credentials (`APP_KEY`) | AES-256-CBC with HMAC-SHA256 | Already quantum-resistant (256-bit symmetric). Keep `APP_KEY` secret and backed up. |
| Passwords | bcrypt | Fine. Hashing is not broken by quantum computers in any practical way. Prefer SSO anyway. |
| Probe shared key, sessions | Random secrets, HMAC | Fine. |
| Database | Internal Docker network only, never on the wire | Fine. If you move PostgreSQL to another host, use TLS with OpenSSL 3.5+ on both ends. |
| Backups | Whatever you encrypt them with | Encrypt with a **symmetric** key or passphrase (for example `gpg --symmetric --cipher-algo AES256`, or restic or Borg with a strong password). Avoid backup encryption that relies only on RSA or classic elliptic-curve recipient keys for long-lived archives, because backups are exactly what gets harvested. |
| SSH to the host | OpenSSH 9.0+ uses `sntrup761x25519` by default, 9.9+ also `mlkem768x25519` | Keep OpenSSH current, and check `ssh -Q kex` lists one of them. Don't pin old key exchange algorithms in `sshd_config`. |
| VPN to the host | Depends | For WireGuard, add a **preshared key** per peer, which gives it a symmetric layer of post-quantum protection. |
| Certificates and signatures (TLS certs, OIDC token signing) | Classical (RSA or ECDSA) | No action yet. Signatures can't be harvested and broken later to impersonate past sessions, and post-quantum certificates are not yet in the public CA system. Revisit when your CA and identity provider offer them. |

In short:

1. Enable hybrid post-quantum TLS at the reverse proxy.
2. Prefer a local UniFi key.
3. Encrypt backups symmetrically.
4. Keep OpenSSH and the images current.

The app itself needs no changes.

## Before you hand it to someone else

- [ ] Remove test accounts and preview users you no longer need (Users).
- [ ] Revoke test API keys in UniFi.
- [ ] Rotate any credential that was shared in chat, email or tickets during setup.
