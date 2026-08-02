# Connect handshake — server-side contract

What `citecue_app` has to implement for the WordPress plugin's one-click connect. The plugin side is done and shipped in this repo ([`includes/class-citecue-connect.php`](../includes/class-citecue-connect.php)); until the two pieces below exist, the button leads to a 404 and customers fall back to **Connect with an API key instead**, which still works exactly as it did.

The goal is that a customer installs the plugin, clicks one button, confirms in CiteCue, and is done — instead of carrying a `ck_live_…` key into WordPress and a `cws_…` secret back out.

## 1. `GET /connect/wordpress` — the confirm page

An authenticated Nuxt page. Query params sent by the plugin:

| Param | Example | Notes |
|---|---|---|
| `site` | `https://example.com/` | `home_url('/')` — the WordPress site asking to connect |
| `state` | 32 hex chars | Opaque. Echo it back untouched; never interpret it |
| `return` | `https://example.com/wp-admin/options-general.php?page=citecue` | Where to redirect after confirming |
| `v` | `1.0.0` | Plugin version |

Behaviour:

1. Require a session. Not logged in → the usual login redirect, preserving the full URL.
2. Resolve the org and pick the `brand_projects` row whose domain matches `site`'s host (compare lowercased, `www.` stripped, as `Citecue_Admin::handle_test_connection()` does). Offer a picker when several match or none do; offer to create one when the org has no project for that domain.
3. Show what connecting will do — serve optimized pages to AI crawlers on that domain, publish its llms.txt, and (checkbox, default **on**) allow CiteCue to push draft content into the site. This screen is the **only** place the customer is told about content pushes, which is why the plugin refuses to enable ingest unless the claim response says so.
4. On confirm:
   - Mint a `ck_live_` key scoped to the org, named `WordPress — {host}`, via the same path as `POST /api/orgs/[orgSlug]/api-keys` (`ck_live_${newToken(24)}`, store `sha256Hex` only). One key per connected site: revoking one site must not break the others, and the API-keys screen should show which site each key belongs to. Reuse the existing key when the same `site` reconnects rather than accumulating rows.
   - Store a one-time code — random, ≥128 bits — against `{ orgId, projectId, keyId, secret, siteUrl, ingest }` with a **10-minute TTL**, single use. KV with TTL is the natural home; the secret is held only until it is claimed.
   - Redirect to `return` with `citecue_code` and `citecue_state` appended (proper query-arg append — `return` already contains `?page=citecue`).

### Validating `return` — do not skip this

`return` decides where a bearer-grade code is delivered. Require that its **origin equals `site`'s origin** and that its path is under the site's `/wp-admin/`. Anything else is an open redirect that hands an org API key to whoever crafted the link. Reject with an error page rather than redirecting somewhere safe — a mismatch means the request was tampered with.

## 2. `POST /api/delivery/v2/connect/claim` — the exchange

Unauthenticated: the code *is* the credential. The plugin sends `X-Citecue-Channel: wordpress` and does not follow redirects.

Request body:

```json
{
  "code": "…",
  "site_url": "https://example.com/",
  "rest_url": "https://example.com/wp-json/citecue/v1/",
  "ingest_secret": "cws_…",
  "plugin_version": "1.0.0",
  "woocommerce": true
}
```

Steps:

1. Look up the code. Missing → `400 {"error":"invalid_code"}`. Already spent → `409 {"error":"code_used"}`. Past TTL → `410 {"error":"code_expired"}`.
2. **Mark it spent before doing anything else**, so two concurrent claims cannot both succeed.
3. Compare `site_url`'s origin with the `siteUrl` captured when the code was issued. Mismatch → `403 {"error":"site_mismatch"}`.
4. Persist against the project: `site_url`, `rest_url`, `ingest_secret` (encrypted at rest — mirror `google_connections.refreshTokenCiphertext`), `plugin_version`, `woocommerce`, connected-at. Either two columns on `delivery_settings` or a `wordpress_connections` table next to `google_connections`; prefer the table if one project may ever have several sites.
5. Set `delivery_settings.enabled = true` and stamp `installVerifiedAt` — connecting is the customer saying yes, so they should not then have to find a second switch on the Auto-Fix page.
6. Respond `200`:

```json
{ "apiKey": "ck_live_…", "publicKey": "pk_…", "domain": "example.com", "ingest": true }
```

`apiKey` and `publicKey` are required; the plugin rejects a payload without both. `domain` is shown on the settings screen. `ingest` reflects the checkbox from step 3 — **omit it and the plugin leaves content pushes off**, which is the correct failure mode.

Never return the key on any non-200. Log claim attempts with the code id, outcome and source IP; repeated `invalid_code` from one address is code-guessing.

## Once this lands

CiteCue holds the ingest secret and the site's REST base, so the content-push endpoint works without the customer touching **Settings → CiteCue → Shared secret**. The push itself is unchanged — same HMAC-SHA256 scheme documented in the [README](../README.md#content-push-api-create-posts).

Worth doing at the same time, since the data is now there: **Verify installation** in the app can call `GET {rest_url}health` (public, returns `{plugin, version, delivery, ingest, woocommerce}`) instead of probing headers blind.

## Errors the plugin already renders

| Status | `error` | Shown to the customer |
|---|---|---|
| 400 | `invalid_code` | That connection link is not valid. Start the connection again from WordPress. |
| 409 | `code_used` | That connection link has already been used. Start the connection again from WordPress. |
| 410 | `code_expired` | That connection link expired. Start the connection again from WordPress. |
| 403 | `site_mismatch` | CiteCue issued that link for a different site address than this one. |
| other | — | Unexpected response from CiteCue (HTTP *n*). |

Any of these leaves the site unconnected, with the setup screen and its API-key fallback intact.
