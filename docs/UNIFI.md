# UniFi integration and compatibility

## Credential

IT Requests expects an official UniFi API key in the `X-API-Key` header. Generate it in UniFi Site Manager **Settings → API Keys** or the local Network application's **Control Plane → Integrations** area. Use a dedicated least-privilege identity. Do not supply a UI password, session cookie or owner/super-admin key.

For a local UniFi OS console, the default Network integration prefix is:

```text
/proxy/network/integration/v1
```

The app initially calls `GET /sites`. Keep TLS verification enabled and install your internal CA in the container for private PKI; disabling verification is only a temporary diagnostic.

## Domain allowlisting caveat

The official Network API documents sites, networks, clients, firewall policies, ACL rules and traffic matching lists. These do not equal a stable content-filter domain exception. DNS answers change, CDNs share addresses, and an IP firewall rule is not a safe substitute for a domain allowlist.

The built-in provider currently reports:

- site/network discovery: available;
- firewall policy resources: available;
- domain allowlisting: unavailable;
- scheduled domain allowlisting: unavailable.

Approval records the decision and moves it to `Action required`; IT completes the content-filter change in UniFi and clicks **Mark manually applied**. This is deliberate. Automatic writes should be enabled only when Ubiquiti publishes a supported reversible resource for the relevant release.

## Compatibility procedure

UniFi local API documentation is version-specific. Open **Network → Control Plane → Integrations** on the target controller, compare its resources with this adapter, then use the read-only connection test. Test all write behavior in a non-production site before active mode.

Cloud Connector use is a future adapter option and should not be assumed equivalent to the local integration URL.
