# UniFi integration and compatibility

## Credential and connection

Filterdesk sends an official API key in the `X-API-Key` header. Choose the connection that matches the key:

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

With them, **Policies → Networks & filters** shows each controller filter and the VLANs it is attached to, lets an admin link a filter to a filter policy and map each VLAN to a policy (and so to staff, student or guest filtering), and flags VLANs whose controller filter differs from the mapping. Each policy gets **Fetch from controller**, and entries added in the controller by hand are listed as *Only in the controller* with an option to import them into a managed list.

## Allow and block precedence

When a hostname is in both an allow list and a block list that apply to a filter, an entry added on purpose (by IT or an approved request) beats a bulk entry (a blocklist feed or an import from the controller) wherever each sits, so allowing a host that a feed or an import already blocks works even when nobody knew it was there. Between two entries of the same kind the more specific list wins: a list for one policy beats an audience list, which beats a site-wide list. At the same level the most recently added entry wins. A hostname newly blocked by hand in UniFi still wins over an older allow, because the import turns that allow off for the filter (see below). The policy page lists every such hostname and which way it went, and adding an entry that is overridden says so.

## Blocklists and firewall IP lists

**Protection** (blocklists and firewall IP lists) (admins) subscribes to third-party lists, with presets for [hagezi/dns-blocklists](https://github.com/hagezi/dns-blocklists) (DNS bypass, encrypted DNS, threat intelligence, social, gambling and more) or any https URL in hosts, adblock, wildcard or plain format.

- **Domain lists** fill their own list (site-wide, a network type or one policy, allow or block), refreshed on a schedule, and reach content filters through the normal push. Content filters are not built for very large lists: start with the bypass list (about 17,000 domains) or smaller, and check the push reports no shortfall. Lists of 100,000+ domains belong on a DNS resolver.
- **IP lists** (and your own addresses) keep a UniFi IP group (traffic matching list) up to date through the official API. Create the group from the app or link an existing one, then let the app create the blocking rules (below) or add your own in UniFi, for example *Block · Internal → External · destination: the group*. The app shows which firewall rules use each group.

`blocklists:refresh` runs hourly and downloads feeds that are due; with automatic pushing on, firewall IP groups are updated straight after.

### Firewall IP lists in UniFi

IP blocklists go into UniFi IP groups (traffic matching lists). UniFi rejects a group above about **20,000** addresses (a 30,000-address push returns HTTP 400), so a longer list is split automatically into groups named "List (1 of 3)", "List (2 of 3)" and so on; when it shrinks, surplus groups and their rules are removed. The per-group limit can be changed with `max_ip_group_entries` in the network provider configuration if a UniFi release allows more.

**Create blocking rules in UniFi** (on the Blocklists page) creates and maintains the rules for you. Each zone shows the networks in it, and the page lists the exact rules before you create them.

How the rules are built, and why there are several:

- A UniFi zone-based firewall rule has **one source zone, one destination zone and one IP group**. So the app creates **one rule per IP group for each zone you tick**: *Block · &lt;zone&gt; → External · destination in the group*. A list split into 2 groups with the Internal and a custom Staff zone ticked needs 4 rules: groups × zones.
- **Tick every zone that holds client networks.** A network moved into its own zone (Staff, CCTV, Exam rooms) is no longer in Internal, so an Internal-only rule does not cover it. VPN clients are in the Vpn zone, and guest hotspots are in Hotspot. External and Gateway are never sources.
- **Incoming** (optional) adds *Block · External → &lt;zone&gt; · source in the group* for each ticked zone and for the **Gateway** zone (VPN servers and other services on the gateway). UniFi already blocks unsolicited incoming traffic, so this matters for port forwards, a DMZ and VPN servers.
- **Order:** UniFi uses the first matching rule within a zone pair. If one of your Allow rules for the same zones sits above the block, it can let listed addresses through, and the page warns about it. **Put these rules first** (on by default for new lists) moves the app's rules to the top of each zone pair through the official ordering API. Your other rules keep their order, and system rules are untouched.
- **Drift:** a rule deleted in UniFi is recreated on the next push or hourly refresh. A rule you only disable stays disabled, and the page shows it as disabled. The app deletes its rules when you untick the zones or delete the list. Rules you made yourself are listed but never changed.
- **Content filters are separate.** They apply per network and keep working as before; firewall IP lists add address-level blocking on top.

### Firewall allow lists

**Policies → Firewall allow lists** (admins) lets addresses through the firewall instead, for a site whose address one of your block rules (or a feed's) blocks. A content filter allow can't open those.

- Each list keeps a UniFi IP list and one rule per zone: *Allow · &lt;zone&gt; → External · destination in the list*. The app moves these to the **top of each zone pair**, ahead of every block rule. Its own block lists with *Put these rules first* come straight after.
- **Zones:** *Every zone* covers all zones except External and Gateway (Internal, Hotspot, Vpn and any you created). It follows UniFi: after you create a zone and move networks into it, the next push or hourly refresh adds its Allow rule. *Only these zones* covers the ticked ones; the page warns about zones that have block rules but no Allow rule from the list.
- **Entries:** addresses, subnets (/16 or narrower; wider than /24 needs a reason) and domains, each with a note, request and end date. Domains are resolved through the probes, so on each network's own DNS, every hour; an address a domain stops using is dropped after 7 days. Cloudflare and Fastly addresses need a reason, because allowing one lets every site behind it through.
- **Take over a UniFi list:** link an existing IP list and bring its addresses in as entries. From then on the app replaces its contents on each push.
- **Plain-text URL:** optional, with a secret token, one address or subnet per line, for a firewall that loads IP lists from a URL. We couldn't confirm the format UniFi's own URL lists expect, so check it loads.
- **Checks:** Quick allow, Quick block, request approval, website checks and Access explorer evaluate your firewall rules (enabled ones, in order, for the network's zone to External) against the site's addresses. They name the rule that decides. Rules that match by region, domain or application can't be checked against an address and are listed as skipped; threat management (IPS) and region blocking are outside these rules.

Feeds refresh on their schedule (hourly check); with *Push automatically* on, each linked list is pushed after a refresh, but a list whose addresses, groups and rules are unchanged is skipped, so UniFi only receives real changes.

## Guided setup

After the connection test succeeds (and on the dashboard until it is done), the app sends admins to **Policies → Guided setup**. **Bring everything in** reads every content filter and sets the tool up to match it in one step:

- each filter becomes a policy with a network type guessed from its name and networks; the default *Students* and *Staff* policies are linked rather than duplicated;
- a filter that covers the same networks as exactly one other filter and is named like a blocklist (or has no allows and more blocks than the other) becomes that policy's [layer](#layers);
- every allow and block entry is imported into lists owned by that policy (a layer takes blocks only), as imported entries, so a later allow by IT or an approval still wins over them;
- networks are mapped to their policy, categories are kept as they are, and a disabled probe profile is prepared per policy.

Nothing is changed in UniFi and the first push changes nothing. Running it again leaves linked filters alone. The table below the button shows the same suggestions to review or change filter by filter.

The page then plans one probe container per policy (plus any extra networks ticked) and generates `compose.vlan.yml`; see [probe containers on the VLANs](DEPLOYMENT.md#probe-containers-on-the-vlans).

## Networks in more than one filter

A network can be attached to several content filters, and UniFi applies all of them. Tested on UniFi Network with two filters on one network:

- a block in either filter blocks the hostname (filters add up);
- a block in one filter wins over an allow for the same hostname in the other;
- an allowed subdomain in one filter (`www.example.com`) kept the bare domain (`example.com`) resolving even though the other filter blocked it, while the blocked `www.` stayed blocked. Do not rely on mixing allows and blocks for one site across filters;
- every save reloads the gateway's DNS for a few seconds; a blocked hostname resolved once during that window.

The Networks page marks networks in a filter that is not managed as a layer, and the sync check reports them as drift for the affected policies.

## Layers

A layer is a second content filter on the same networks as a policy, created from the policy page (**Add a layer for blocklist feeds**). It holds the policy's block feeds (every blocklist feed that applies to the policy) plus the layer's own block lists, so the policy's own filter keeps only the entries IT and requests add and stays quick to edit in the UniFi web interface.

Because a block in any filter wins, the app works out precedence before pushing: anything the policy allows (allow lists, approved requests) is left out of the layer. A layer never pushes allow entries. Its content categories copy the parent's and follow it when categories are changed here, because UniFi will not save a filter without at least one category (`MALWARE` is used if the parent has none).

The layer is created with `POST /proxy/network/v2/api/site/<site>/content-filtering/create`, the call the Network settings page makes. **Move the feeds now** pushes the layer first and then removes the feeds from the parent, so nothing goes unblocked in between; scheduled pushes keep that order. If the layer filter is taken off a network in UniFi, the Networks page and the sync check say so.

## List size

UniFi stores up to about 100,000 entries per list, and filtering is not slowed by long lists. Its Content Filtering page loads every entry, though, so a filter with a few thousand or more is slow to open and edit in the browser. The policy page warns above 5,000. Pushes are refused above **Settings → Network provider → Largest list per content filter** (default 100,000), and automatic pushes with more than **Largest automatic push** changes (default 1,000) wait for someone to push them by hand.

## The controller is the source of truth

By default (Settings → Network provider → *The controller is the source of truth*), UniFi wins. On every sync check and before every push the app reads each linked filter and:

- imports entries added in UniFi into that policy's own allow or block list, so they are kept and managed from then on;
- keeps managed entries that were removed in UniFi out of that policy (an exclusion), so no push, manual or automatic, puts them back. Other filters are not affected. IT can **Restore** them on the policy page; if someone re-adds one in UniFi, the exclusion clears itself;
- treats a hostname newly blocked in UniFi as blocked for that filter even if a shared allow list allows it;
- accepts category changes made in UniFi;
- adds an internal note to any approved request whose hostname was removed in UniFi.

Changes this app still owes the controller, such as removing an expired approval, are still pushed. If the app is unavailable, filtering carries on in UniFi, and edits made there are picked up at the next check. Turn the setting off to only report UniFi edits as *Changed in controller* for review.

## Sync checks and drift

`policy:check-sync` runs every 15 minutes (and on **Check sync now**). For each linked policy it records:

- **In sync**: the filter matches the policy.
- **Changes to push**: this app has additions or removals not yet pushed.
- **Changed in controller**: a mapped network uses another or an extra filter, or, with the source-of-truth setting off, allow/block entries or categories were edited directly in UniFi since the last push (or since IT clicked **Accept controller changes**).
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

UniFi content filtering is DNS based. Filterdesk therefore:

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
- UniFi answers a blocked name with `203.0.113.250` / `2001:db8:1000::fa`. The probe treats those, `0.0.0.0`, `::` and any documentation-range address as blocked, and results show *Blocked by DNS*. If a gateway answers with another address, the probe checklist's *Blocked sites are detected* step shows it and offers to save it (or list it in `BLOCK_PAGE_ADDRESSES`).
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

Approval records the decision, adds the hostnames to the managed policy lists and moves the request to `Action required`. IT copies the change from **Policies** into each UniFi content filter and clicks **Mark applied**. This is deliberate. Automatic writes should be enabled only when Ubiquiti publishes a supported reversible resource for the relevant release.

## Compatibility procedure

`php artisan unifi:compat` checks, read-only, that the controller still answers every call this app uses in the shape it expects: networks, content filters and categories (outside the official API), IP groups, firewall zones and firewall rules. It exits non-zero if any fails. Run it on a test console before upgrading UniFi Network, or straight after an upgrade; `--json` prints the result for monitoring.

Release 2026.12.0 passed every check against the live console it was tested on. If you run it on another Network version, note the result in an issue so this page can list it.

UniFi local API documentation is version-specific. Open **Network → Control Plane → Integrations** on the target controller, compare its resources with this adapter, then use the read-only connection test. Test all write behavior in a non-production site before active mode.

The cloud connector exposes the same integration API as the local endpoint. It does not add content-filter access.
