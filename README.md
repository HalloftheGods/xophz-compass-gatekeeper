# Xophz Gatekeeper

![Xophz Gatekeeper Icon](icon.png)

> **"You Shall Not Pass... without a valid key or active sponsorship."**
> Central authentication authority, machine-to-machine API key minting, and SaaS entitlement gating for Project COMPASS and YouMeOS.

---

## 1. Overview

**Xophz Gatekeeper** is the centralized identity, key authority, and entitlement controller for the COMPASS companion plugin ecosystem. It provides:

- **Machine-to-Machine (M2M) API Keys**: Mint and validate cryptographically secure API keys with customizable prefixes (defaulting to `stargate_`) for CLI tools, AI agents, and external microservices.
- **Chemical X Software Licensing**: Cryptographic issuance and validation for flat-rate eBook licenses (`CX-ST-`), GitHub Sponsor monthly licenses (`CX-SP-`), and Team Power Puff unlimited seats (`CX-VIP-`).
- **License products**: a license engine other plugins register into (see "License products" below). Keys, records, devices, Stripe and RevenueCat intake, the public REST routes and the admin list are Gatekeeper's; each product's names, plans, strings and rules stay in the plugin that registers it. XP Pro (Do It For The XP) is the first product and now lives in `xophz-compass-xp`.
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
| `GET` | `/compass/v1/gatekeeper/{route_prefix}/plans` | A license product's plan catalog with `on_sale` flags (the product's `plans` callable). For XP Pro: `/xp-pro/plans` | Public |
| `POST` | `/compass/v1/gatekeeper/{route_prefix}/claim` | `{<account_param>, <device_param>}`: the account's license, and registers the device. For XP Pro `{family_id, device_id}`. 404 `{pro:false}` until a purchase arrives, 409 `device_limit` past the product's device cap (10 for XP Pro) | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/{route_prefix}/validate` | `{license_key, <device_param>}`: same shape plus the account field, for restoring on a new device | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/{route_prefix}/recover` | `{email}`: emails every key on file for that address. Always 202 for a well-formed address | Public, 5/hour |
| `POST` | `/compass/v1/gatekeeper/{route_prefix}/manage` | `{license_key, return_url}`: a Stripe Billing Portal `{url}` or the `{store}` to open | Public, 30/min |
| `POST` | `/compass/v1/gatekeeper/{route_prefix}/setup/stripe-webhook` | `{mode: test\|live, rotate?}`: creates and registers the product's Stripe webhook, keeping the signing secret server side | Admin |
| `GET`/`POST` | `/compass/v1/gatekeeper/{route_prefix}/settings` | The product's webhook ids and its own settings (from its settings schema) | Admin |

The XP Pro AI helpers route (`POST /xp-pro/ai`, the Gemini proxy) is no longer Gatekeeper's: the XP plugin (`xophz-compass-xp`) registers it and checks the key with `gatekeeper_license_for_key()`.

### License products

Gatekeeper is the license engine; a plugin adds a product by filtering `gatekeeper_license_products` (this is the XP plugin's registration, shortened):

```php
add_filter( 'gatekeeper_license_products', function ( array $products ): array {
    $products['xp-pro'] = array(
        'label' => 'XP Pro', 'app_name' => 'Do It For The XP',
        'key_prefix' => 'XP-PRO-', 'option_prefix' => 'gk_xp_pro_',
        'account_param' => 'family_id', 'device_param' => 'device_id', 'max_devices' => 10,
        'plans' => array( 'Xophz_Compass_Xp_Pro', 'get_plans' ), 'route_prefix' => 'xp-pro',
        'settings' => array( 'ai_daily_limit' => array( 'label' => 'AI requests per family per day', 'type' => 'int', 'option' => 'xp_pro_ai_daily_limit', 'default' => 100 ) ),
    );
    return $products;
} );
```

`Gatekeeper_License_Products` validates each config, fills the defaults (key alphabet, `gk_lic_{product}_` option prefix, a uuid4 account validator, a device id validator, 3 day grace, 7 day trial, webhook and Stripe setup option names, the email strings built from `app_name`), writes one `error_log` line for a malformed config and skips that product, never fatals. An int setting row may carry whole-number `min` and `max`. A product's records live in `{option_prefix}{account}` plus lookup options, never in `compass_gatekeeper_licenses`, and never pass through `licenses/validate`.

- **Engine classes**: `Gatekeeper_License_Products` (registry), `Gatekeeper_License_Store` (records, keys, devices, entitlement; every method takes the product id first), `Gatekeeper_License_Db` (named locks, atomic counters, rate limiting, daily counter cleanup), `Gatekeeper_License_Stripe` and `Gatekeeper_License_RevenueCat` (a webhook payload to one license operation), `Gatekeeper_License_Intake` (Hookshot: the webhook id picks the product and adapter, only a request `Hookshot_Signature::was_verified()` counts, a new key is emailed once), `Gatekeeper_License_REST` and `Gatekeeper_License_Setup` (the routes above).
- **Helper functions** (consumers guard with `function_exists`): `gatekeeper_license_record( $product, $account )`, `gatekeeper_license_entitled( $product, $account, $now = null )`, `gatekeeper_license_for_key( $product, $key, $device )` (the record, or a `WP_Error`: 400 `invalid_input`, 404 `not_found`, 402 `pro_required`, 409 `device_limit`; a new device attaches when there is room), `gatekeeper_counter_bump( $name, $ttl, $delta = 1 )` and `gatekeeper_rate_limited( $bucket, $max, $window, $request, $scope )`.
- **Hooks**: `do_action( 'gatekeeper_license_applied', $product, $op, $result )` after a webhook changes a record; `apply_filters( 'gatekeeper_license_public_view', $view, $record, $product )` on the JSON a client sees.
- **Admin**: Settings > Gatekeeper Licenses (slug `gatekeeper-xp-pro`, so the old link still lands) has one section per product: webhook ids, the settings form drawn from the schema, and the license list (key, plan, status, expires, env, email, devices, last event) with Reset devices, Restore and Update.
- **Transition**: until the XP plugin registers `xp-pro`, Gatekeeper registers a built-in `xp-pro` (plans through the old `gatekeeper_xp_pro_plans` filter, or the shipped catalog) and logs one line per request saying so. `Gatekeeper_Xp_Pro_Licenses` stays for one release as a facade over the store for `xp-pro`, and `Gatekeeper_Xp_Pro_Db` as an alias of `Gatekeeper_License_Db`. Both go in the release after the XP plugin ships.

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
