# Policy lists and reconciliation

IT Requests keeps the *desired* content-filter state for every network and
compares it with what is actually configured in the controller. UniFi's
official API does not expose content-filter domain lists, so with UniFi the
app reads and (in Active mode) writes them through the Network application's
own content-filtering endpoints, verifying every write; see
[UNIFI.md](UNIFI.md). With pushing off, or another provider, the controller
side is a copy-and-paste step using the lists described here.

## Model

| Concept | What it is | Example |
| --- | --- | --- |
| **Network type** | A kind of network you manage, defined under **Settings → Network types**: Staff, Students, Guests, CCTV, Exam rooms, whatever fits. Each type has its own shared allow and block lists. The names are labels for a set of rules, not user roles. | `Students`, `CCTV`, `Exam rooms` |
| **Filter policy** | One content filter in the controller, of one **network type**, and optionally linked to a network probe. | `Students` → UniFi content filter on the Student VLAN |
| **List** | A named allow *or* block list with a **scope**. | `Site-wide allow`, `Students block`, `Exam-week allow` |
| **Entry** | One hostname in a list, with its source and an optional start/expiry. | `example.edu.au` · Approved request WEB-2026-00012 · until 30 Nov |

List scopes:

- **Site-wide**: applies to every enabled policy.
- **Network type**: applies to every policy of that type (for example every Students policy).
- **Single policy**: applies to one policy only.

The **desired allow list** for a policy is every effective entry in every allow
list that applies to it. The **desired block list** is the same for block lists.
When a hostname is in both, an entry added on purpose beats a feed or import,
then the more specific list wins; the overlap is reported on the policy page.

On a new install these are created automatically:

- network types Staff, Students, Guests and Other networks (rename, add or remove them under **Settings → Network types**)
- `Students` and `Staff` policies from the Student/Staff network profiles
- `Site-wide allow` / `Site-wide block`
- an allow and a block list for each type that is used or requestable

Add more policies under **Policy lists → Add a filter policy**, or let Guided
setup create one per UniFi filter. A new type's lists are created as soon as
the type is.

### Network type settings

| Setting | Effect |
| --- | --- |
| **Requestable** | Shown as a choice when someone requests access or a block. At least one type must be requestable. |
| **Auto-approve** | With *Auto-approve* on under Approval policy, an access request is approved straight away when every network type it is for has this ticked (by default only Staff). |

A type can be deleted once no policy, list or probe uses it. Its key never
changes, so renaming is always safe.

## Where domains come from

| Source | How it gets there | When it leaves |
| --- | --- | --- |
| **Approved request** | Approval adds the chosen hostnames to the default allow list (or, for a block request, block list) of each network type the request is for: *Staff* → Staff allow; *Staff and Students* → both; *Site-wide* → Site-wide allow. IT can change the types when approving. | Automatically when a temporary window ends or the request is revoked. |
| **Added by IT** | *Policy lists → Add hostnames*, with no request needed. Adding to **Site-wide allow** reaches every policy, students included. | When removed, or at its expiry if one was set. |
| **Imported** | Bulk paste into a list (merge or replace). Request entries are never replaced. | When removed. |

### Smart approval

With website categories turned on (Settings → Website categories), approving a permanent access request first works out why each hostname is blocked, and the approval panel shows the plan:

| Why it is blocked | What approval does |
| --- | --- |
| Its UniFi category is blocked by a filter the request covers | Adds an allow entry (needed to override the category) |
| A blocklist feed, or an entry imported from the controller | Adds an allow entry (the feed would put it back) |
| A block in a list that also covers networks outside the request | Adds an allow entry, so the other networks keep their block |
| Only blocks IT added, in lists the request covers | **Removes those blocks** instead; no allow entry |
| Nothing | Adds nothing |
| Category unknown, or timed access | Adds an allow entry, as before |

Untick *Only add allow entries where needed* to add an allow entry for every hostname. Removed blocks are not restored if the access is later revoked. Categories come from Cloudflare and UniFi's own category database can differ, so check the site afterwards; if it is still blocked, add an allow entry from the policy page.

Use **Where is a hostname?** to see every list containing a hostname or any
of its parent domains.

## DNS-only filtering rules

UniFi content filtering works at the DNS level. The rules:

1. **Hostnames only.** Paths, query strings, ports and schemes are invisible to DNS, so a single page cannot be allowed on its own. The parser strips them.
2. **No wildcards.** `*.example.com` is not valid; the leading `*.` is removed with a warning.
3. **List the domain and the subdomains a site needs.** Allowing `learn.example.edu.au` does not help if the page loads from `static.example.edu.au`, `example-cdn.net` or a sign-in host. When approving, IT sees recommendations:
   - the requested hostname;
   - the registrable domain (via the bundled Public Suffix List, so `example.edu.au`, not `edu.au`), or `www.` for a bare domain;
   - every other host the page loaded during a website check, **pre-selected if it failed on any network**.
4. **Subdomain matching** is set under *Settings → Network provider → DNS filter entry matching*:
   - *Explicit* (default): every hostname is listed. Works on every controller version.
   - *Entry covers subdomains*: redundant subdomains are collapsed into their parent in the desired output. Use this only after confirming your controller version behaves this way.

## Daily workflow

1. Open **Policy lists** and choose a policy (for example *Students*).
2. Copy that content filter's current allow and block lists from UniFi and paste them into **step 1**. Escaped dots (`www\.example.org`), schemes, paths and wildcard prefixes are normalised.
3. Review the **change set**:
   - *Add to allow list*: desired, missing from the controller.
   - *Remove from allow list*: previously managed here (expired, revoked or removed). Safe to remove.
   - *Review in allow list*: in the controller but never managed here. Import it into a list to keep it, or remove it.
   - *Add / remove from block list*.
4. Paste the **desired lists** (or just the changes) into UniFi, then click **Mark controller in sync**.
5. Mark any *Action required* requests as applied.

A daily digest (*Settings → Email and notifications*) emails IT when approvals
are waiting, temporary entries expire within 24 hours, or a policy's last
snapshot differs from its desired state.
