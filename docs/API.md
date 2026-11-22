# API

IT Requests has a small JSON API for service desks, scripts and dashboards. It does everything through the same rules as the app: an approval made through the API needs a second IT officer when the app would ask for one.

## Tokens

Create tokens under **Settings → Integrations → API tokens** (administrators). A token:

- acts as the IT officer who created it, so every action is recorded against them;
- stops working if that officer is disabled or loses IT access, or when it expires or is revoked;
- is shown once; only its SHA-256 hash is stored;
- has one or more abilities: **read**, **requests** (submit on someone's behalf) and **decide** (approve or decline).

Send it as a bearer token. Requests are limited to 120 a minute per client.

```bash
curl -H "Authorization: Bearer itr_…" https://itrequests.example.edu.au/api/v1/requests?status=pending
```

## Endpoints

| Method and path | Ability | What it does |
| --- | --- | --- |
| `GET /api/v1/requests?status=&since=` | read | Requests, newest first, 50 a page (`next` links the next page). `status` is one of `pending`, `needs_information`, `awaiting_second`, `approved`, `action_required`, `active`, `rejected`, `expired`, `revoked`, `failed`. |
| `GET /api/v1/requests/{reference}` | read | One request. |
| `POST /api/v1/requests` | requests | Submit a request for an existing, enabled user: `requester_email`, `websites` (list), `network_types` (type keys), `category`, optional `reason`, `until` (ISO date) and `type` (`access` or `block`). |
| `POST /api/v1/requests/{reference}/decision` | decide | `decision`: `approve` or `decline` (with `message`). Answers 409 if the request cannot be decided, for example when the token's officer gave the first of two approvals. |
| `GET /api/v1/versions` | read | The last 50 published policy versions. |
| `GET /api/v1/explain?site=&type=&at=` | read | The access explorer's answer for a site, network type and optional time. |
| `GET /api/v1/metrics` | read | Prometheus metrics: open requests, second approvals waiting, overdue reviews, filters out of sync, coverage score, latest version, failed publishes, scheduler heartbeat age and queue size. |

## Webhooks

Add endpoints under **Settings → Integrations → Webhooks** and choose the events: `request.submitted`, `request.awaiting_second`, `request.approved`, `request.declined` and `policy.published`.

**JSON** endpoints receive:

```json
{"event": "request.submitted", "sent_at": "2026-12-01T09:00:00+10:00", "data": {"reference": "WEB-2026-00042", "websites": ["example.org"], "networks": "staff", "status": "pending", "url": "https://…"}}
```

With a secret set, `X-ITRequests-Signature: sha256=<hex>` is the HMAC-SHA256 of the raw body with that secret. Check it before trusting the body. Deliveries are retried three times; the last result is shown with the endpoint.

**Microsoft Teams** endpoints (an incoming webhook or a Workflows URL) receive a card. New requests have **Approve** and **Decline** buttons that open a confirmation page in IT Requests: the link is signed and expires after 7 days, and the officer still signs in, so the decision is theirs and the normal checks apply.

Ticketing systems that accept webhooks (for example Jira Service Management or Freshservice automations) can create a ticket from `request.submitted` and post decisions back through `POST /requests/{reference}/decision`.
