# UniFi integration and compatibility

## Credential and connection

IT Requests sends an official API key in the `X-API-Key` header. Choose the connection that matches the key:

- **Local console key** (Network → Settings → Control Plane → Integrations). Called directly at `https://<console>/proxy/network/integration/v1`. Keep TLS verification on and install your internal CA for private PKI.
- **Site Manager key** (unifi.ui.com → API). Called through Ubiquiti's cloud connector at `https://api.ui.com/v1/connector/consoles/<consoleId>/proxy/network/integration/v1`. The key's owner must be an owner of the console (otherwise `403 forbidden`), and the console must be online (otherwise `404 device_offline`). Use **Discover** to list consoles.

Site Manager keys do not work against the local endpoint (HTTP 401), and local keys do not work against `api.ui.com`. Use a dedicated key, not a personal session or password.

## What the official API covers

Checked against UniFi Network 11.0: sites, networks/VLANs, Wi-Fi broadcasts, clients, firewall zones and policies, ACL rules, traffic matching lists and DNS policies (local records and forwarding) are available. **There is no content-filtering endpoint**, so the app cannot read or write content-filter allow/block lists. That is why reconciliation is copy-and-paste.

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
- domain allowlisting: unavailable;
- scheduled domain allowlisting: unavailable.

Approval records the decision, adds the hostnames to the managed policy lists and moves the request to `Action required`. IT copies the change from **Policy lists** into each UniFi content filter and clicks **Mark applied**. This is deliberate. Automatic writes should be enabled only when Ubiquiti publishes a supported reversible resource for the relevant release.

## Compatibility procedure

UniFi local API documentation is version-specific. Open **Network → Control Plane → Integrations** on the target controller, compare its resources with this adapter, then use the read-only connection test. Test all write behavior in a non-production site before active mode.

The cloud connector exposes the same integration API as the local endpoint. It does not add content-filter access.
