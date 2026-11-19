# UniFi integration and compatibility

## Credential

IT Requests expects an official UniFi API key in the `X-API-Key` header. Generate it in UniFi Site Manager **Settings → API Keys** or the local Network application's **Control Plane → Integrations** area. Use a dedicated least-privilege identity. Do not supply a UI password, session cookie or owner/super-admin key.

For a local UniFi OS console, the default Network integration prefix is:

```text
/proxy/network/integration/v1
```

The app initially calls `GET /sites`. Keep TLS verification enabled and install your internal CA in the container for private PKI; disabling verification is only a temporary diagnostic.

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

Cloud Connector use is a future adapter option and should not be assumed equivalent to the local integration URL.
