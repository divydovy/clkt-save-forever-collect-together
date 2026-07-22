# CLKT REST API (`clkt/v1`)

Save WordPress content to the permanent web (Arweave) **headlessly** — from a
script, CI, or an agent — without opening wp-admin.

**Design principle: the plugin never holds a key and never signs content.**
Signing happens in *your own* wallet, exactly like the browser flow. The API
prepares a record, you sign + dispatch it yourself, and the plugin records and
verifies the result. Liability for what gets saved stays with the signer.

## Authentication

All routes require a WordPress **application password** and map to the user's
capabilities:

| Routes | Capability |
|---|---|
| `/health`, `/records`, `/auto-save-queue` | `edit_posts` |
| `/prepare/<id>`, `/save/<id>`, `/status/<id>` | `edit_post` on that post |
| `/settings` (GET/POST) | `manage_options` |

There are no unauthenticated (`nopriv`) routes. Send credentials via HTTP Basic
auth:

```bash
curl -u "USER:APP_PASSWORD" https://example.com/wp-json/clkt/v1/health
```

## The save flow

```
1. GET  /clkt/v1/prepare/<id>   → canonical record + tags + content hash
2. (you) sign those + dispatch to Arweave with your own wallet
3. POST /clkt/v1/save/<id>      → { "tx_id": "<43-char id>" }
```

Step 2 is yours: any Arweave-compatible signer works (e.g. an EVM wallet
dispatching via the ArDrive Turbo network — free for data under 100KB). The
plugin verifies on-chain that the tx is a CLKT record for *this* post and site
before recording it.

## Endpoints

### `GET /health`
Returns plugin version and mode.
```json
{ "plugin": "clkt", "version": "1.4.0", "site": "https://example.com",
  "signing": "caller-side", "verify": true }
```

### `GET /prepare/<id>`
Canonical `clkt-record-v1` envelope + Arweave tags to sign, plus the SHA-256
`content_hash`. Deterministic, so you can build a byte-identical record.

### `POST /save/<id>`
Body: `{ "tx_id": "<id>", "content_hash": "<optional sha256>" }`.
Registers a caller-signed transaction. Response:
```json
{ "success": true, "post_id": 12, "tx_id": "…", "status": "uploaded",
  "arweave_url": "https://arweave.net/…" }
```
`status` is `uploaded` once the tx is verified on-chain, or `pending_confirmation`
if Arweave hasn't indexed it yet (re-POST the same tx later to confirm — the call
is idempotent, no duplicate versions). A tx that doesn't match the post/site is
rejected with `422`.

### `GET /status/<id>`
Current `status`, `tx_id`, `arweave_url`, and the full `versions` history.

### `GET /records`
All posts that have a saved transaction (`post_id`, `title`, `tx_id`, `arweave_url`).

### `GET /auto-save-queue`
Published posts that *should* be auto-saved per the site's own settings (global
auto-save + per-post `always`/`never`/`default`) but have no tx yet. Lets an
external signer preserve new posts on publish while respecting your settings.

### `GET` / `POST /settings` (admin only)
Read or update whitelisted CLKT settings. `POST` accepts any of:
`clkt_global_auto_save` (bool), `clkt_show_cost_estimate` (bool),
`clkt_free_dispatch_limit_kb` (int), `clkt_max_allowed_upload_mb` (int),
`clkt_wallet_type`, `clkt_wallet_provider`. Unknown keys are ignored.
```bash
curl -u USER:PW -X POST https://example.com/wp-json/clkt/v1/settings \
  -H "Content-Type: application/json" -d '{"clkt_global_auto_save": true}'
```

## Verify a record yourself

Every save records a SHA-256 `Content-Hash` tag. To check a record independently:
fetch `https://arweave.net/<tx-id>`, recompute the SHA-256 of the content, and
confirm it matches the tag — no CLKT, login, or API key required.

## Reference implementation

A working Node dispatcher (fetch → build record → EVM-sign → Turbo dispatch →
register) lives in the CLKT tooling as `clkt-dispatch.mjs`. It signs with the
caller's own wallet and calls `POST /save/<id>` to register the result.
