# Policy list reconciliation

Administrators can open **Policy lists** to compare IT Requests with the allow
and block lists currently copied from UniFi. This is a review-and-copy workflow;
it does not make undocumented controller changes.

## Daily workflow

1. Maintain the school's master allow and block lists in IT Requests.
2. Paste the current UniFi allow and block lists into the comparison form.
3. Review the domains to add or remove and any conflicts or pending requests.
4. Copy the complete desired allow and block lists back into UniFi.

The desired allow list combines the master allow list with approved, active
website requests. Temporary requests are included only during their approved
time window. Pending requests are shown for awareness but do not change policy.
The desired block list is the master block list minus anything currently
allowed. Where a domain occurs in both master lists, allow takes precedence and
the conflict is highlighted for an administrator to resolve.

The parser accepts one domain per line as well as comma- or semicolon-separated
values. It removes URL schemes and paths, normalises case, and accepts copied
escaped dots such as `www\.example.org` and service records such as
`_https._tcp.pkgs.example.org`.

The **review removal** output is advisory because a current UniFi entry may
have been added outside IT Requests. An administrator should confirm it before
removing it.
