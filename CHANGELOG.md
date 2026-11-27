# Changelog

All notable changes to this project are documented here. From 2026.11.0, versions are calendar versions `YYYY.MM.N`: the year and month of the release, then a number that counts releases within that month (`2026.11.0`, `2026.11.1`, then `2026.12.0`). Earlier releases used `0.x`.

## 2026.12.31

### Added
- **Website checks without signing in** (Settings → Network profiles → Website checks, off by default), at `/check/public`.
  - Up to 3 websites, with one plain answer per network type: allowed, blocked or couldn't check. A blocked or unchecked site gets *Sign in to request access*.
  - Results are shared for 10 minutes and limited to 5 checks a minute per address and 60 in all. Each check is in the audit log.
  - `/check/embed` is the same without navigation, for an iframe on the sites you list (`frame-ancestors`).
- **A short request form for students**, or students and staff (Settings → Approval policy → *Short request form for*): just the websites and why. The networks, category and period are left to IT, and the approval page says so. Students no longer see *Request a block*.
- **Never IT or admin** (Settings → Authentication): email addresses or `group:` names that never get IT or admin from the role rules. It's checked at every single sign-on, so someone loses IT at their next sign-in, and *Test a sign-in* shows when it applies.
- **Firewall rules: IP lists only** (Settings → Network provider). Filterdesk keeps its UniFi IP lists up to date and never creates, changes, reorders or deletes a firewall rule. A guard in the provider enforces it for every path.
  - In this mode the zone options are hidden, and rules Filterdesk made before are left in place and listed as yours to manage.
  - When a list grows into a new part (*Name (3 of 3)*), the list, the push result and an email to IT say so until you confirm it's in your rules.
  - A part that shrinks away but is still used by a rule is kept, emptied.
  - New installs default to *IP lists only*. Installs that already have rules made by Filterdesk keep managing them until you change it.
- **Addresses per UniFi IP list** (Settings → Network provider), 20,000 by default and up to 100,000. Saving the provider settings no longer resets it. The Protection page shows how many UniFi IP lists and addresses Filterdesk keeps. To try larger lists on your gateway: `php artisan filterdesk:test-ip-list-size`, which uses temporary lists that no rule uses.
- **The helpdesk can never be blocked by accident.** These are always protected, on top of Settings → Protected sites, and shown there:
  - the support article's site;
  - the helpdesk email's domain (at the top level);
  - Filterdesk's own address;
  - the single sign-on server.

### Changed
- **Plainer emails to requesters.**
  - New default wording, for example "IT has reviewed your request for … It's now allowed until Friday 12 December". Times show without a timezone.
  - Every requester email ends with "For anything else, contact … at …" from Settings → Help and support.
  - New switches for the *approved, before it works* email (untick it to send one email, when it works), *approved for a later start* and *access removed*, next to the existing *request received* switch.
  - Customised templates keep their wording. New placeholders: `{note}`, `{outcome}`, `{until}`, `{starts}`, `{ends}` and `{tips}`.
- **Plain website check results for students and staff.** Each network says *Allowed*, *Blocked* or *Couldn't check*, with no certificate, DNS or probe details. IT-only notes moved out of their view, and IT's "fresh browser" hint is now a small line.

## 2026.12.30

### Security
- **symfony/http-kernel and symfony/routing 8.1.11 → 8.1.12**, with the matching symfony/http-foundation and symfony/var-dumper. Fixes CVE-2026-133743 (forwarded headers could change the URLs `asset()` and `absolute_url()` build) and the two routing advisories. `composer audit` is clean again.

## 2026.12.29

### Security
- **symfony/mailer 8.1.10 → 8.1.12.** Fixes CVE-2026-133751 and CVE-2026-133755: a man in the middle could bypass *require TLS*, or inject replies right after STARTTLS, on the connection to your SMTP server.

## 2026.12.28

### Added
- **AbuseIPDB preset** (Protection → Add a list → *AbuseIPDB: most reported IPs, 30 days*), from [borestad/blocklist-abuseipdb](https://github.com/borestad/blocklist-abuseipdb). The addresses most reported to AbuseIPDB over the last 30 days, the publisher's recommended period, refreshed daily into a firewall IP list.
- **IP feeds are processed to fit UniFi.** The 30-day list has about 160,000 addresses, and a UniFi IP list holds 20,000. IP feeds are now read in full, then:
  - private, reserved and documentation addresses are dropped, and so are addresses a firewall allow list lets through;
  - optionally, a /24 with several listed addresses is taken whole (*Whole /24 at 3+* is the default for AbuseIPDB; *Keep single addresses* turns it off);
  - neighbouring addresses are merged into the fewest subnets, without changing what's covered;
  - the result is capped (40,000 entries by default for AbuseIPDB, two UniFi IP lists), keeping the widest ranges first and then single addresses spread evenly.

  Each feed's row says what processing did. On today's 30-day list, with 3+: 5,931 busy /24s taken whole, about 81,000 entries before the 40,000 cap.

### Fixed
- **Probe image builds retry Ubuntu's mirrors.** The 2026.12.27 probe image failed while Ubuntu's security mirror was mid-sync.

## 2026.12.27

### Fixed
- **The UniFi firewall check now runs.** It never found a network's firewall zone, because UniFi's two APIs give the same network different IDs. Networks are now matched by VLAN, then by name, so Quick allow, request approvals, website checks, Access explorer and allow lists check the rules.
  - When a zone still can't be found, the result says so ("UniFi's firewall rules weren't checked for Student Access") instead of saying no rule matches.
  - The probe checklist has a new step, *Firewall zone found*.
- **The firewall evaluation follows each rule's conditions**, as UniFi reads them:
  - Rules limited to some networks, devices or addresses don't decide for the whole network. They're listed separately, and a rule for the probe's own network does count.
  - Rules for other protocols (*Block QUIC* on UDP), other connection states (*Block Invalid Traffic*) or the other IP version are left out.
- **Allow rules never let invalid connections through.** They match new, related and established connections only.
- **Allow list entries for the same address are one row.**
  - Each address is listed once, with every domain and hand-added entry behind it, each with its own note, request and end date.
  - Removing one entry keeps an address another entry still needs.
  - `www.` that resolves to the same addresses as the bare domain becomes one entry: "example.org (and www.)".
  - Adding an entry whose address is already on the list says so.
- **Access explorer marks old evidence.** When the last saved check failed or is more than a day old, it says so and links to a fresh check.

### Added
- **Allow rule options** (allow list → Zones):
  - **Only these networks:** limit the Allow rules to chosen networks, so a network another rule keeps off the internet (servers, management) stays off.
  - **Just above the block rules that match these addresses:** place the rules there instead of at the top, so general rules and blocks for single networks or devices are still checked first.
  - The page warns which source-limited blocks a zone-wide Allow rule would open the address for.
- **See the firewall rules a list keeps in UniFi**, on allow lists and block lists. Each rule shows its zones, the networks it applies to, its place in the zone's order (with the rules just before and after) and whether it's on, disabled or missing.
- **Nightly check of allow list entries** (02:40, or *Check the entries now*). Each domain is resolved again and compared with the addresses it had when it was added. It is flagged when:
  - it has **moved** to new addresses;
  - it **no longer resolves**;
  - **no firewall rule blocks** its address any more, so the entry may not be needed.

  Flags show on the list's page until they clear, and new ones are emailed to IT with the filter sync alerts.

## 2026.12.26

### Fixed
- **A site is marked unreachable** when at least one of its addresses gets no reply and none answers. Before, an IPv6 address with no route (common on IPv4-only networks) stopped it, and the result fell back to a raw timeout.

## 2026.12.25

### Added
- **Firewall allow lists** (Policies → Firewall allow lists, admins). Addresses, subnets and domains are let through UniFi's firewall, for sites whose address a firewall rule blocks. A content filter allow can't open those.
  - Each list keeps a UniFi IP list and one **Allow rule per zone** (*Allow · zone → External · destination in the list*). Filterdesk keeps these at the top of each zone's order, above every block rule, its own block lists' "put first" rules included.
  - **Zones:** *Every zone* (the default) covers all zones except External and Gateway, and follows UniFi. A zone created later gets its rule at the next push. *Only these zones* limits it, for example to staff networks, and the page lists zones that have block rules but no Allow rule from the list.
  - **Entries** have a note, the request they came from, an optional end date and who added them. Domains are resolved through the probes, so on the school's own DNS, and again every hour. An address a domain stops using is dropped after 7 days.
  - **Safety checks:** shared addresses (Cloudflare and Fastly ranges) and subnets wider than /24 need a reason, and anything wider than /16 is refused. Each entry shows the UniFi block rules it exempts the address from.
  - **Take over a UniFi list** that already exists, bringing its addresses in, instead of making a second one.
  - An optional **plain-text URL** (secret, one address or subnet per line) for firewalls that load IP lists from a URL. UniFi's format for URL lists couldn't be confirmed, so check it loads.
- **Firewall checks before allowing or blocking.**
  - **Quick allow** says when a site's address is blocked by a firewall rule, and names the rule, the list and the matching range. It also says when the name doesn't resolve, or when nothing replies. It then offers *Also add it to the firewall allow list*.
  - **Quick block** says when the firewall already blocks the address, or when the address is on a firewall allow list, where a domain block can be bypassed by going to the address. It offers to take it off.
  - **Request approval** shows the same check and offers *Also allow its address at the firewall*, with the request number and the access end date.
- **The UniFi firewall in Access explorer and website checks.** Filterdesk now evaluates UniFi's own firewall rules and IP lists for the network's zone, in UniFi's order, against the site's addresses. Before, it only looked at its own lists.
  - Access explorer reports *Blocked by the UniFi firewall, not the content filter*, with the rule.
  - Rules that match by region, domain or app are noted as not checked.
  - The firewall allow list page also lists **UniFi IP lists that no rule uses**.
- **The probe tells "no connection" apart from a probe failure.**
  - When a page times out, the probe tries a plain connection to each address. If nothing replies, it reports **unreachable** ("No reply from 198.51.100.7 on port 443…") instead of a raw timeout.
  - IT sees the firewall rule that blocks the address. Students and staff see *Blocked by the school firewall*, or *Can't connect from the school network*.
  - The probe's name lookup can also test a connection, which the checks above use.

## 2026.12.24

### Fixed
- **Long lists no longer slow down the policy page.**
  - Push history, controller changes and pulled entries show the first 12 hostnames, then *and N more*, which opens up to 500 more in place.
  - The change set and desired lists show the first 1,000 lines, with *Download .txt* for the full list. Each part of the change set and the controller's current lists can now be downloaded too.
  - In *Paste lists manually*, a list of more than 1,000 entries isn't loaded into the box. Left blank, it keeps what the last fetch found.

## 2026.12.23

### Fixed
- **Policies whose UniFi filter was deleted can be found and dealt with.** They now have a row in Policies & lists → Content filters, saying the filter was deleted, with *Keep, unlinked*, *Link to another filter…* and *Delete policy*. Deleting warns how many entries the policy's own lists hold. The sync message names the deleted filter and offers the same choices.
- ***Push all* skips policies whose UniFi filter was deleted**, with a note, instead of reporting the whole run as unfinished.
- **Policy pages open by number too** (`/admin/policy/policies/4`), as well as by name.
- **A network's Policy drop-down can't be undone by a stale save.**
  - Each save sends the mapping the page showed, and a save from an out-of-date page is refused with what the mapping is now.
  - The drop-down is locked while its save runs, and a late response no longer redraws over a newer one.
  - The result names what was saved, for example "Guest Wi-Fi (VLAN 80) → Guests".

## 2026.12.22

### Fixed
- **Moving a filter link no longer happens silently.** Choosing a policy that's already linked to another filter, or relinking a filter that already has a policy, now shows what will change and waits for *Move the link*. Before, the drop-down moved the link straight away, and the next automatic push wrote that policy's lists over a different filter.
- **Linking a filter keeps what's in it.** Linking a filter to a policy, new or existing, first copies the filter's current allows and blocks into that policy's own lists, so the first plan removes nothing. Before, a new policy started empty and the next automatic push emptied the filter.
- **Nothing is pushed automatically after a link change.** The policy is put *On hold* until someone checks its change set and pushes it from the policy page. That clears the hold.
- **An automatic push never removes most of a filter.** If a push would remove 3 or more entries and at least half of the filter, it's refused and recorded, whatever the automatic change limit is. A person pushing that one policy from its page can still send it.
- **Push history follows the filter.** A policy's page lists every push to its filter, including ones made while another policy was linked, so *Roll back* is there after a link change.
- **Device networks with site-wide filtering** (cameras, phones, management networks) are no longer told to add Students filtering. They show *Site-wide filtering only: as expected*. Only a device network with no filter at all gets a suggestion.
- **Network mappings follow UniFi.** When UniFi no longer has a policy's filter on a network but other filters are there, the 15-minute check maps the network to the policy behind those filters, or clears the mapping, and logs it (when the controller is the source of truth). Rows that disagree have a *Follow UniFi* button, and the *Networks differ* notice says what to do.

### Added
- **Protected sites** (Settings → Protected sites): a safety net of sites that are never blocked, whatever a list, feed, website scan, request or UniFi says.
  - The defaults cover search, sign-in and updates: google.com, bing.com, microsoft.com, apple.com, office.com, live.com, icloud.com, accounts.google.com, \*.gstatic.com, \*.googleapis.com, \*.microsoftonline.com, \*.windowsupdate.com and others.
  - `google.com` protects the top level, so a subdomain such as gemini.google.com can still be blocked. `*.gstatic.com` protects the whole domain. A public suffix on its own (com, com.au) is always protected.
  - Protected sites are left out of every filter's blocks, and one blocked in UniFi is taken out on the next push. Adding one to a block list, a quick block or a block request is refused with a message. The domain scanner marks them and leaves third-party ones unticked.
- **Filtering map** (Policies → Filtering map). Pick a network to see the whole chain: every content filter UniFi puts on it and why it's there, the Filterdesk policy behind each one, its sync state, the categories it blocks, and the site-wide, network type and policy lists that fill it, with entry counts.
- **Copy content categories between filters.** On a policy's *Content categories*, *Copy the categories of another filter* takes another filter's selection, and *Also save this selection to* writes the same selection to other filters, for example Students and Guests.

## 2026.12.21

### Added
- **Two-factor sign-in** with any authenticator app (time-based codes), with 8 one-time recovery codes.
  - Required by default for IT officers and admins who sign in with a password, and optional for everyone (Profile → Two-factor sign-in).
  - Settings → Authentication chooses who must use it, and whether it's also asked after single sign-on.
  - Admins can reset someone's (Users → *Reset 2FA*); there's also a host command, `filterdesk:reset-two-factor`.
  - Wrong codes lock the second step for 15 minutes after 5 tries. Everything is audited.
- **Help for staff and students.**
  - A **How to** page: how to check a website, what each answer means, how to ask for access, and what to do when a site is still blocked after it was allowed (close the browser, try a private window, restart).
  - Settings → **Help and support**: the helpdesk's name, email, phone, hours and location, and a link to your own support article. They appear in a *Need help?* box on the dashboard, website checks and requests.
- **Your own wording and a sign-in logo** (Settings → School): the dashboard heading, the sign-in page and the website check page. The defaults are written for students and staff ("Web filtering", "Sign in with your school account…"), not IT.
- **The setup list can be shown again** after *Hide this list*, and a step marked done by hand can be undone.
- **How to get a Cloudflare token**, step by step with links to Cloudflare's guides, in Settings → Website categories and the installer.

### Fixed
- **A probe's internet check** no longer warns about hosts inspected with your school's uploaded HTTPS inspection certificate (often `www.google.com`, for SafeSearch). It counts them as expected and lets the other test sites decide. The probe also tests one more site outside Google.
- **Website check results with categories**: the category row and the plain results now have the card's padding.
- **Security review** (see [SECURITY_HARDENING.md](docs/SECURITY_HARDENING.md#security-review-20261221)):
  - *Remember me* after single sign-on lasted about five years; it now lasts 30 days (`AUTH_REMEMBER_MINUTES`).
  - Images upgrade their OS packages when built, and releases pull a fresh base image. This fixed 58 high or critical issues in the web image's older Alpine base.
  - CI audits PHP and probe dependencies and scans the images.
  - CI's image check now expects the files `bin/update` needs. It had failed since 2026.12.18; the images themselves were fine.

## 2026.12.20

### Fixed
- **Single sign-on from an embedded page.** In a portal iframe, *Continue with single sign-on* used to send the frame to the identity provider. The browser blocks the provider's cookies there, so it lost the request ("Missing required attribute 'response_type'"). It now opens the provider in a popup window. When sign-in is done, the popup closes and the embedded page opens the dashboard.
  - If the popup is blocked, the page offers a new tab.
  - Browsers that keep embedded pages' cookies separate (Safari, Firefox) get **Continue here**, which asks for storage access.
- `/login` sends someone who is already signed in to the dashboard.

## 2026.12.19

### Added
- **Quick block or allow** (Policies → *Quick block or allow*, and from website check results), for IT:
  - Enter hostnames, choose Block or Allow, tick the network types or *Everywhere*, and choose when.
  - A preview shows the lists it goes into, the UniFi filters that change, the UniFi categories that already decide it, and entries that say the opposite. Entries added by hand can be removed in the same step; entries from requests link to the request.
  - Then it makes the change, and publishes straight away when automatic pushing is on (otherwise there's **Publish now**).
  - *Everywhere* still waits for a second IT officer.
- **Blocks in school hours only.** For network types with a school-hours filter (such as *Student - School Time*), a block can go into that filter alone, so the site is open after hours and at weekends. This suits boarders on the same Wi-Fi as day students.
  - **IT chooses when approving** a block (*School hours only* or *All the time*), and is told when a UniFi category already blocks the site in school hours.
  - **The requester can suggest it** when Settings → Approval policy → *Block requests: when* allows it. IT can still change it.
  - The choice is shown on the request and in its history, and the list entry says *school hours only*.
  - Types without a school-hours filter get nothing for such a block, and the approval says so.
- **Plain website check results for students and staff**: one line per network, *Allowed*, *Blocked* or *Couldn't check right now*. It says *Works, but needs the school certificate* when the gateway's inspection certificate is the cause, and *in school hours (Mon–Fri 08:15–15:10)* when only the school-hours filter blocks it. No addresses, timings or probe messages. IT still sees everything, and blocks that only apply in school hours are labelled for IT too.
- The approval form names each type's UniFi filters under *Apply to*, so it's clear where a block lands before approving.

### Fixed
- **`bin/update` checks a git checkout before doing anything.**
  - Local edits to tracked files stop it with what to do, before backing up or pulling images.
  - An untracked file that the update brings in (a hand-copied `bin/update`) is removed when it's the same as the incoming one, and named otherwise.
  - If any step fails, it says which one, and that the stack is still running the previous version.
- A publish for one request now includes its type's school-hours filter.

## 2026.12.18

### Fixed
- **Blocked sites are no longer reported as allowed.** The probe now recognises UniFi's DNS block answer (`203.0.113.250`, `2001:db8:1000::fa`), and any answer in the documentation ranges (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`), as blocked. `BLOCK_PAGE_ADDRESSES` is no longer needed for UniFi.
- **The probe's browser uses the VLAN's DNS.** Chromium used to resolve names through Docker's DNS, so it loaded sites a device on the VLAN can't reach, and didn't see SafeSearch. Every page now loads through a small proxy inside the probe that resolves with the same VLAN resolver as the check. The generated `compose.vlan.yml` also sets `dns:` for each probe.
- **A slow screenshot no longer fails the whole check.** The screenshot gets 5 seconds, then a capture that doesn't wait for fonts (YouTube), then none. The result stands either way, and the page says why there is no screenshot.
- **The scheduler and queue can reach the probes** (`probe_control` in `compose.yml`). Before, the 10-minute `probes:check` marked every probe "not working" and website checks showed no results until the checklist was run again by hand. When no probe name resolves from the container it runs in, `probes:check` now says so and keeps the last results, instead of failing every probe.
- **An old "not working" checklist no longer hides answers.** When a probe answers a check (or its `/health` does), its checklist is run again first, and its result is shown if it passes.
- **Entries removed in UniFi aren't put back.**
  - Guided setup linking a filter again no longer resets what Filterdesk compares UniFi with, so a removal made just before still reads as a removal.
  - An entry that came from UniFi and was removed there is deleted from Filterdesk's lists, including shared lists unless another filter still has it. It is no longer just kept out of one policy, which a school-hours layer then put back. Entries added on purpose are still only kept out, as before.
- **An allow that only a paused filter provides is kept.** Publishing used to plan to remove such an allow from a filter (for example `copilot.com` from *Student - School Time*, because *Allow (Sitewide)* had it but is paused). It now stays, and the sync summary and policy page say why.

### Added
- **Probe checklist steps:**
  - **Browser uses the VLAN's DNS**, which also names probe images that are too old.
  - **Blocked sites are detected**: it looks up a few hostnames on the network's filter block list. If the answer isn't recognised as a block, **Save … as a block answer** adds it for every probe. The list is under Settings → Network profiles, and it is sent with every check.
- **Page previews are for IT only by default** (Settings → Network profiles → Website checks). Previews are the screenshot and the page title. Students and staff still see allowed or blocked on each network, but not the page, so a student who checks an inappropriate site doesn't see it. Previews can be opened to staff, or to everyone.
- **DNS-only checks** (same place, optional): the probes answer from the VLAN's DNS without loading the page. Checks are faster, and the probe never fetches the site. They can't see block pages, HTTPS inspection, or parts of a page that fail to load.
- **Website categories in the installer** (step 7, optional): Cloudflare token, source and account ID, with **Test lookup**.
- **`bin/update`**: back up, pull, bring `compose.yml`, the example overrides and the `bin/` scripts up to date, and restart. In a folder that isn't a git checkout, the files come from the new app image, and anything replaced is kept in `backups/`. Your own overrides and `.env` are never touched.
- DNS-blocked results show a *Blocked by DNS* card instead of an empty screenshot.

## 2026.12.17

### Fixed
- **Filters Filterdesk put on networks are marked, and can be undone.** This covers every baseline addition: *Filter it now*, the *Add Students filtering* buttons (including 2026.12.13's network check), and automatic ones. Each is listed at the top of Networks & filters with when, who and the networks, with **Take it off again**. Each filter chip it added says *added 24 Nov*. Undo previously only covered automatic additions and returned an error for button ones.
- **Add buttons say exactly what they do.** They name the UniFi filter, when it applies, its blocked-category count and SafeSearch, and warn when the network is a device network. Before, they said "Students" even when the filter was a school-hours one. There is no bulk add for device networks.
- **Networks found while read-only wait for a decision**, instead of all being filtered the moment Active mode is switched on.
- **Turning a filter that was brought in as the main policy into a school-hours layer** no longer depends on picking the right *Policy to use*:
  - That policy isn't offered to the other filter, and a pick that would merge the two filters' lists is ignored, with a message.
  - The probe profile moves to the all-hours policy.
  - When the layer had the general name ("Students"), the names swap: the all-hours policy becomes *Students*, and the layer is named after its UniFi filter.
- **"Leave it alone" lets go of a filter** that a policy was managing: it is unlinked and its networks unmapped.
- **No probe profile for VPN-only filters** (remote-user or WireGuard networks with no VLAN).

### Added
- **Clean-up in Guided setup**: policies that manage no filter and hold only imported entries, and switched-off probe profiles nobody uses, listed to delete in one go. Built-in profiles and policies with requests or entries added by hand are never listed.
- **Entries that look like two hostnames run together** (for example `wistia.comfast.wistia.net`) are flagged when imported and on the policy page, with **Split** (into `wistia.com` and `fast.wistia.net`; the old one is taken out of UniFi at the next publish) or **Keep**.

## 2026.12.16

### Fixed
- **Baseline filtering no longer fills infrastructure networks with a filter by surprise.**
  - It starts only once a Students policy is linked (after Guided setup), not on the first check after install.
  - It starts again, asking rather than filtering, when the console or site changes, so switching from the simulation to a real console doesn't make every network look new.
  - New *device* networks (infrastructure, ISP and WAN hand-offs, cameras…) are listed for a decision and IT is emailed; only new *user* networks are filtered automatically.
  - A scheduled Students filter (school hours only) is never used automatically unless an admin chose it as the baseline.
  - Networks whose filters are all paused stay untouched, as before.
- ISP, WAN, uplink and similar network names are learned as device networks.

### Added
- **Take it off again**: each automatic baseline addition is listed on Networks & filters, with one button to remove that filter from those networks. They are then listed for a decision, and the guard won't add it back. This also works for additions made by earlier versions.
- **Test connection in the installer's UniFi step**, without saving anything. With a Site Manager key it lists the consoles the key can see to pick the one to manage, then shows that console's sites, networks and content filters. The review step says whether the connection was tested.

## 2026.12.15

### Added
- **Test sign-in**, in the installer and under Settings → Authentication: sign in at the provider with the settings as they are and see the claims (personal values hidden), the groups, and the role the rules give ("would be: admin, matched `group:itstaff=admin`"). Each group has buttons that add a role rule. No account or session is created, and the normal redirect URI is used.
- **The installer's sign-in step has *Provider-specific options*** (extra scopes, claims holding groups) and *Re-apply rules at every sign-in*, on by default. For CloudWork ID, its profile scope is added automatically when a rule uses `group:`, because CloudWork only sends groups with it. A warning shows on the step and on the review when group rules cannot match (a missing scope, Google, or a provider that lists no groups claim).
- **Accounts the rules would change** (Settings → Authentication): SSO accounts whose role differs from what the rules give, from the groups at their last sign-in, with **Apply the rules to these accounts**. IT and admin access is never taken away.
- **School-hours layers**: a scheduled filter beside an always-on one for the same type (*Student - School Time* beside *Students - All Hours*) is learned and managed as a layer of that policy. It keeps its own allows and blocks and UniFi's schedule, and gets the policy's allows too, so approvals also work in school hours. Choose it in Guided setup or under *Managed by*. A filter brought in earlier as its own policy is converted, keeping its lists.
- **When** on approvals, for types with a school-hours layer: *All hours* (both filters) or *Outside school hours only*.
- **Schedules in words**: "Mon–Fri 08:30–15:10" instead of "Scheduled", on Networks & filters, Guided setup, the Policies table and the policy page.
- **Networks with only the school-hours filter** are explained on their row ("Students filtering only in school hours…") with a button to add the all-hours filter, and listed on the policy's sync status instead of *Networks differ*.

### Fixed
- A scheduled filter with its own allows, linked as an *extra blocklist layer*, was treated as blocks-only: publishing removed the allows that Filterdesk also knew from other lists, and flagged the rest for review. A school-hours layer keeps them. Blocklist layers are now only for feeds.

## 2026.12.14

### Changed
- **Networks & filters is one table, a row per VLAN**: the filters on it in UniFi (group, site-wide, layer and paused filters told apart), its kind (user or device), the policy it belongs to here, and one plain status with the button that fixes it. Tabs: *Needs attention*, *All*, *User networks*, *Device networks*, *No filter*. It replaces the separate Network check, Baseline filtering and Networks sections.
- **Changes happen in place**: adding Students filtering, mapping, allowing unfiltered and changing a network's kind or policy update the page without a reload, with the result message. Actions that change UniFi ask on the button itself ("… Confirm") instead of a browser dialog.
- **Content filters on that page use one "Managed by" control**: an existing policy, a new policy for a type (Staff, Students, Guests…), or a new site-wide layer, with what each means.
- **"+N" on networks and categories opens in place**, and *to add / to remove on publish* on the Policies table opens the actual hostnames.

### Added
- **Add or remove a filter on a VLAN** from its row (× on a filter, *+ Add filter*), in Active mode.

### Fixed
- A filter named for a type of user (for example *Student - School Time*) is learned as that group even when it is on most networks, instead of as a site-wide layer. This removed the flood of "add Students filtering" suggestions on device networks that already had it.
- Baseline filtering prefers an always-on Students filter over one on a school-hours schedule.
- "Mapped to … here, but filtered as … in UniFi" now offers *Map to …* (the fix button was never shown).

## 2026.12.13

### Added
- **Filterdesk learns how your UniFi filters are organised**: site-wide layers (filters on most networks, such as *Allow (Sitewide)* and *Block (Sitewide)*), groups per type of user with their schedules, blocklist layers, and user versus device networks. Guided setup shows it as suggestions to confirm, with a preview laid out like UniFi's Content Filter page (name, networks, categories, SafeSearch, schedule) and what Filterdesk manages each filter as.
- **Site-wide layers in publishing**: one site-wide layer takes the site-wide blocks, so new site-wide blocks go only into it instead of every filter (keeping each under UniFi's ~100,000-entry limit). Allows still go into every filter on the networks concerned.
- **Policies page as a table** like UniFi's, with sync status and what publishing would change per filter, instead of stacked cards.
- **Network check** (Networks & filters): each network as a user or device network (learned; confirm or change), with suggestions such as a user network that has only site-wide filtering, or a device network without Students filtering, and a button to add Students filtering.
- **HTTPS inspection certificate**: upload the gateway's inspection CA (public certificate only; a private key is refused) under Settings → HTTPS inspection. A public `/certificate` page offers it with install steps per device, the probe names the issuer when a site's certificate is intercepted, and checks link to the page when it is the school's.
- **DHCP reservations are accepted for probes** behind a DHCP relay once confirmed on the checklist (*It's reserved on our DHCP server*); UniFi reservations already were.

### Changed
- **Stacked filters are no longer "Changed in controller"**: that status now means only that a filter's lists or categories were edited in UniFi. Networks mapped here but filtered differently in UniFi show as *Networks differ*; other filters stacked on the same networks are one short line in the summary.
- Paused filters are greyed in the tables and never chosen to take the site-wide blocks.

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
