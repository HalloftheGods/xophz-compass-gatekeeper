# Xophz Gatekeeper

![Xophz Gatekeeper Icon](icon.png)

> **"You Shall Not Pass... without a valid key or active sponsorship."**
> Central authentication authority, machine-to-machine API key minting, and SaaS entitlement gating for Project COMPASS and YouMeOS.

---

## 1. Overview

**Xophz Gatekeeper** is the centralized identity, key authority, and entitlement controller for the COMPASS companion plugin ecosystem. It provides:

- **Machine-to-Machine (M2M) API Keys**: Mint and validate cryptographically secure API keys with customizable prefixes (defaulting to `stargate_`) for CLI tools, AI agents, and external microservices.
- **Chemical X Software Licensing**: Cryptographic issuance and validation for flat-rate eBook licenses (`CX-ST-`), GitHub Sponsor monthly licenses (`CX-SP-`), and Team Power Puff unlimited seats (`CX-VIP-`).
- **SaaS Entitlement Bridge**: Integrates with `xophz-compass-hookshot` to automatically process GitHub Sponsors webhooks and provision immediate developer entitlements.
- **AI Agent Self-Discovery**: Exposes OpenAPI / JSON Schema ability definitions so AI coding assistants can discover and call endpoints cleanly.

---

## 2. API Key Management (Prefix: `stargate_`)

API keys are generated using 24 bytes of secure system entropy and stored as irreversible SHA-256 hashes.

### Key Prefix
- The system supports passing custom prefixes upon creation (e.g. `cx_live_`, `compass_gate_`).
- Default prefix: `stargate_` (e.g. `stargate_4f8a9b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e`).

### Scopes
- `read:audits`: Access audit telemetry and scorecards.
- `write:workspaces`: Save and sync IDE workspaces.
- `verify:licenses`: Validate software licenses and sponsor status.
- `admin:all`: Super-user capabilities across all COMPASS APIs.

---

## 3. REST API Endpoints

All endpoints are exposed under the namespace `compass/v1/gatekeeper`:

| Method | Endpoint | Description | Permission |
|---|---|---|---|
| `GET` | `/compass/v1/gatekeeper/status` | Health check and active key/sponsor counts | Public |
| `POST` | `/compass/v1/gatekeeper/licenses/validate` | Validate license key or GitHub sponsor handle | Public |
| `POST` | `/compass/v1/gatekeeper/keys` | Mint a new API key with custom prefix (`stargate_`) | Admin |
| `GET` | `/compass/v1/gatekeeper/keys` | List active keys (masked display) | Admin |
| `DELETE` | `/compass/v1/gatekeeper/keys/{id}` | Revoke an active key | Admin |
| `POST` | `/compass/v1/gatekeeper/sponsors/sync` | Sync sponsor status from Hookshot webhook bridge | Admin / Bridge |
| `GET` | `/compass/v1/gatekeeper/schema` | Machine abilities schema for AI agents | Public |
| `GET` | `/compass/v1/gatekeeper/xp-pro/plans` | XP Pro plan catalog with `on_sale` flags (filled by the XP plugin through `gatekeeper_xp_pro_plans`) | Public |
| `POST` | `/compass/v1/gatekeeper/xp-pro/claim` | `{family_id, device_id}`: the family's XP Pro license, and registers the device. 404 `{pro:false}` until a purchase arrives, 409 `device_limit` on an 11th device | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/xp-pro/validate` | `{license_key, device_id}`: same shape plus `family_id`, for restoring on a new device | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/xp-pro/recover` | `{email}`: emails every key on file for that address. Always 202 for a well-formed address | Public, 5/hour |
| `POST` | `/compass/v1/gatekeeper/xp-pro/manage` | `{license_key, return_url}`: a Stripe Billing Portal `{url}` or the `{store}` to open | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/xp-pro/ai` | `{license_key, device_id, task, input}`: the Gemini helpers for XP Pro families. `task` is `monster_name`, `checklist`, `full_beast` or `schedule`; 402 `pro_required`, 400 `invalid_input`, 429 `daily_limit` (100 a day per license, option `xp_pro_ai_daily_limit`) or `rate_limited`, 502 `ai_unavailable` | Public, license-checked |

### XP Pro

XP Pro licenses (`XP-PRO-XXXX-XXXX-XXXX`) are per family, not per user, and live in their own options (`gk_xp_pro_{family_id}` plus lookup options). They never pass through `licenses/validate`. Stripe and RevenueCat events arrive through Hookshot (`xophz_hookshot_incoming`) and count only on the two webhook ids saved in Settings > Gatekeeper XP Pro whose request Hookshot verified. The AI helpers endpoint takes only a task name and that task's structured fields (strings capped at 200 characters, lists at 20 items); the prompts live in `class-gatekeeper-xp-pro-ai.php` and the Gemini key stays on the server. The Settings page also holds the AI daily limit and lists the licenses, with a Reset devices button per row.

### License validation

`POST /licenses/validate` accepts only license keys minted into Gatekeeper's own table (`compass_gatekeeper_licenses`) or the GitHub handle of an active sponsor. Keys are random and unsigned, so a key's format proves nothing: an unknown `CX-` key is refused.

The preview keys `CX-DEV-PREVIEW` and `QUANTUM-VIP` unlock the VIP tier only on a local install, where `wp_get_environment_type()` returns `local` (`WP_ENVIRONMENT_TYPE=local`). Everywhere else they are refused.

### Checkout key ownership

When a forthexp Stripe checkout completes, Gatekeeper mints an `xp_live_` key for the WordPress user named in `metadata.customer_uid`, or else the user whose email matches the checkout. If neither matches, the key is minted with no owner (`user_id` 0), the customer email is stored in the key's `owner_email`, and the webhook id and key id are logged. It never falls back to another account, because the route guard runs a key's requests as its owner.

---

## 4. GitHub Sponsors & SaaS Tiers

Gatekeeper models four distinct access tiers:

1. **Community (Free)**: Access to audit tools, capsule generators, and scaffolding in exchange for project data published to GitHub Discussions. Evaluation nag banners remain active.
2. **Standard Vault ($27 Flat)**: One-time eBook purchase with lifetime single-developer key (`CX-ST-`) silences CLI nag banners on up to 3 machines.
3. **Solo Sponsor ($9/month)**: Recurring GitHub Sponsor subscription. Automatic nag silencing and continuous prompt updates.
4. **Team Power Puff ($49/month)**: Unlimited team seats, private repository access, CI workflow gating, and VIP Discord.

---

## 5. Architecture & Inheritance

- **Base Class**: Extends `Xophz_Compass_Plugin_Base` from COMPASS Core.
- **REST Base**: Extends `Xophz_Compass_REST_Controller`.
- **Spark**: Registered as `gatekeeper` (`#9333EA`, `fal fa-hat-wizard`).

---

## 6. Tests

Unit tests live in `tests/` and run PHP inside the `u-wordpress` Docker container, with in-memory options and no database:

```bash
node tests/run-tests.mjs
```

---

## 7. Submodule Information

- **Repository**: `git@github.com:HalloftheGods/xophz-compass-gatekeeper.git`
- **Submodule Path**: `wp-content/plugins/xophz-compass-gatekeeper`
- **Category**: Castle Walls / Security
