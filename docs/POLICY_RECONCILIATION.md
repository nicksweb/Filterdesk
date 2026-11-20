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
| **Filter policy** | One content filter in the controller, tagged with an **audience** (Staff, Student, Guest, Other) and optionally linked to a network probe. | `Students` → UniFi content filter on the Student VLAN |
| **List** | A named allow *or* block list with a **scope**. | `Site-wide allow`, `Student block`, `Exam-week allow` |
| **Entry** | One hostname in a list, with its source and an optional start/expiry. | `example.edu.au` · Approved request WEB-2026-00012 · until 30 Nov |

List scopes:

- **Site-wide**: applies to every enabled policy, including guests.
- **Audience**: applies to every policy for that audience (for example all Student policies).
- **Single policy**: applies to one policy only.

The **desired allow list** for a policy is every effective entry in every allow
list that applies to it. The **desired block list** is the same for block lists,
minus anything that is also allowed. Allow wins, and the overlap is reported
as a conflict to clean up.

On first upgrade or install, these are created automatically:

- `Students` and `Staff` policies from the Student/Staff network profiles
- `Site-wide allow` / `Site-wide block`
- `Staff allow` / `Staff block` and `Student allow` / `Student block`

Add more policies (such as Guests) under **Policy lists → Add a filter policy**.
The audience's lists are created automatically.

## Where domains come from

| Source | How it gets there | When it leaves |
| --- | --- | --- |
| **Approved request** | Approval adds the chosen hostnames to the default allow list of each audience in the approved scope: *Staff only* → Staff allow; *Staff and students* → Staff allow + Student allow; *Site-wide* → Site-wide allow. | Automatically when a temporary window ends or the request is revoked. |
| **Added by IT** | *Policy lists → Add hostnames*, with no request needed. Adding to **Site-wide allow** reaches every policy, students included. | When removed, or at its expiry if one was set. |
| **Imported** | Bulk paste into a list (merge or replace). Request entries are never replaced. | When removed. |

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
