# UniFi integration and compatibility

## Credential and connection

IT Requests sends an official API key in the `X-API-Key` header. Choose the connection that matches the key:

- **Local console key** (Network → Settings → Control Plane → Integrations). Called directly at `https://<console>/proxy/network/integration/v1`. Keep TLS verification on and install your internal CA for private PKI.
- **Site Manager key** (unifi.ui.com → API). Called through Ubiquiti's cloud connector at `https://api.ui.com/v1/connector/consoles/<consoleId>/proxy/network/integration/v1`. The key's owner must be an owner of the console (otherwise `403 forbidden`), and the console must be online (otherwise `404 device_offline`). Use **Discover** to list consoles. The connector needs the full console ID including the `:<number>` suffix; the UniFi web UI shows it without the suffix, so a shortened ID is resolved automatically when saved.

Site Manager keys do not work against the local endpoint (HTTP 401), and local keys do not work against `api.ui.com`. Use a dedicated key, not a personal session or password.

## What the official API covers

Checked against UniFi Network 11.0: sites, networks/VLANs, Wi-Fi broadcasts, clients, firewall zones and policies, ACL rules, traffic matching lists and DNS policies (local records and forwarding) are available. **The official integration API has no content-filtering endpoint.**

## Reading content filters

The app **reads** (never writes) two endpoints of the Network application itself, which accept the same key through either connection:

- `GET /proxy/network/v2/api/site/<site>/content-filtering`: each content filter's name, allow list, block list and attached network IDs.
- `GET /proxy/network/api/s/<site>/rest/networkconf`: networks and VLAN IDs.

`<site>` is the site's short name (`default` unless a different site is configured). These are not part of Ubiquiti's published API and may change; if a release changes them, the app says so and the lists can still be pasted by hand.

With them, **Policy lists → Networks & filters** shows each controller filter and the VLANs it is attached to, lets an admin link a filter to a filter policy and map each VLAN to a policy (and so to staff, student or guest filtering), and flags VLANs whose controller filter differs from the mapping. Each policy gets **Fetch from controller**, and entries added in the controller by hand are listed as *Only in the controller* with an option to import them into a managed list.

## Guided setup

After the connection test succeeds, **Policy lists → Networks & filters → Guided setup** lists every content filter with the networks it applies to. For each one, create a policy (with a suggested audience) or link an existing one, and optionally import its current entries into lists owned by that policy, so the first push changes nothing. The page then recommends one probe container per policy: a macvlan network on one of the policy's VLANs (`docker network create … -o parent=<trunk>.<vlan>`), the VLAN gateway as the probe's DNS server, and the `compose.vlan.yml` snippet. **Create and link probe profiles** adds disabled network profiles to enable once the containers run.

## Networks in more than one filter

A network can be attached to several content filters. Clients there get every filter, so a hostname allowed in one can still be blocked by another. The Networks page marks these networks, the guided setup lists them, and the sync check reports them as drift for the affected policies.

## Sync checks and drift

`policy:check-sync` runs every 15 minutes (and on **Check sync now**). For each linked policy it records:

- **In sync**: the filter matches the policy.
- **Changes to push**: this app has additions or removals not yet pushed.
- **Changed in controller**: allow/block entries or categories were edited directly in UniFi since the last push (or since IT clicked **Accept controller changes**), or a mapped network uses another or an extra filter.
- **Cannot check**: the filter was deleted or the controller could not be read.

IT is emailed when a policy becomes *changed* or *cannot check* (Settings → General → notifications). The dashboard and policy pages show the status and the exact entries changed.

## Content categories

In Active mode, admins can choose which content categories a linked filter blocks from its policy page. The change is written with the rest of the filter unchanged, read back, audited, and becomes the new baseline for drift checks.

## Pushing changes (Active mode)

With *Mode: Active*, IT can push each policy's change set to its linked content filter (`PUT …/content-filtering/<id>` with the full record read just before, only `allow_list` and `block_list` changed). Each push:

- re-reads the live filter, adds managed additions, removes managed removals, and keeps every entry added in UniFi by hand exactly as written;
- never changes categories, Safe Search, schedules or the networks the filter applies to;
- is locked per filter, read back and compared, and recorded with the lists before and after;
- can be rolled back from the policy page while the filter is unchanged since.

Each filter policy links to one controller filter, so a school with several filters (students, staff, guests, boarding, exams…) pushes each separately or all together (**Networks & filters → Push all linked policies**). A request is marked applied automatically once every filter it targets allows its hostnames.

*Push automatically* pushes on approval and revocation straight away, and every five minutes (`policy:push`) for expiries, scheduled starts and list edits.

Before the first push, use **Verify write access** on a policy: it saves the linked filter back unchanged and confirms the key may write. Creating filters is not supported (the endpoint answers HTTP 405); create them in UniFi and link them here.

## Discovery

*Settings → Network provider → Discover sites and networks* calls `GET /sites`
and `GET /sites/{siteId}/networks`. Copy network IDs into *Network profiles*.
Controllers that do not list networks through the official API report that, and
IDs can be entered by hand.

## DNS filtering rules

UniFi content filtering is DNS based. IT Requests therefore:

- outputs bare hostnames only (no scheme, path, port or `*.` wildcard);
- recommends the registrable domain plus the subdomains and third-party hosts a page loads;
- lets you choose whether entries are listed explicitly (default, safe everywhere) or collapsed when the controller treats an entry as covering its subdomains.

See [policy reconciliation](POLICY_RECONCILIATION.md).

## Probe DNS and freshness

For checks to reflect UniFi's filtering, each probe must resolve DNS through
the gateway of its own VLAN, with nothing cached between runs:

- Set `STUDENT_PROBE_DNS_SERVERS` / `STAFF_PROBE_DNS_SERVERS` to the gateway (or UniFi DNS) address for each network. The probe then queries those servers directly with an uncached resolver.
- Leave `PROBE_REUSE_BROWSER=false` (default). Every check run starts a fresh Chromium process, so its host cache is empty and a re-run after a filter change shows the new result.
- Chromium's Secure DNS (DNS-over-HTTPS) is disabled in the probe. DoH would bypass the content filter.
- If the filter answers blocked names with a block-page address, list it in `BLOCK_PAGE_ADDRESSES`. `0.0.0.0` and `::` are always treated as blocked. Results then show *Blocked at the DNS level*.
- Diagnostics shows each probe's DNS mode and whether browsers are fresh per run.

Without a VLAN override the probes use the Docker host's resolver. A
Pi-hole or other filter on the host will then show up as "DNS blocked" hosts.

## Domain allowlisting caveat

The official Network API documents sites, networks, clients, firewall policies, ACL rules and traffic matching lists. These do not equal a stable content-filter domain exception. DNS answers change, CDNs share addresses, and an IP firewall rule is not a safe substitute for a domain allowlist.

The built-in provider currently reports:

- site/network discovery: available;
- firewall policy resources: available;
- content-filter reading: available (unofficial endpoint);
- content-filter list updates: available in Active mode (unofficial endpoint, verified and recorded);
- domain allowlisting: unavailable;
- scheduled domain allowlisting: unavailable.

Approval records the decision, adds the hostnames to the managed policy lists and moves the request to `Action required`. IT copies the change from **Policy lists** into each UniFi content filter and clicks **Mark applied**. This is deliberate. Automatic writes should be enabled only when Ubiquiti publishes a supported reversible resource for the relevant release.

## Compatibility procedure

UniFi local API documentation is version-specific. Open **Network → Control Plane → Integrations** on the target controller, compare its resources with this adapter, then use the read-only connection test. Test all write behavior in a non-production site before active mode.

The cloud connector exposes the same integration API as the local endpoint. It does not add content-filter access.
