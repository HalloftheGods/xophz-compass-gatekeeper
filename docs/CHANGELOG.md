# Changelog

All notable changes to the Xophz Gatekeeper plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Gatekeeper_REST::guard()` and global `gatekeeper_guard()` helper function providing universal cryptographic token and permission verification across all companion plugins.
- `gatekeeper_route_guard` filter hook for dynamic REST route security registration.
- Hookshot webhook fulfillment listener (`xophz_hookshot_incoming`) for automated machine API key generation (`xp_live_*`) on completed Stripe subscription checkouts.
- Dedicated REST routes for listing and minting client software licenses (`GET /licenses`, `POST /licenses`).
- Dedicated REST route for listing active GitHub sponsor entitlements (`GET /sponsors`).
- Expanded machine OpenAPI abilities declaration for AI coding assistants.
- Unit tests in `tests/` (`node tests/run-tests.mjs`, run against the `u-wordpress` container) for license validation and checkout key ownership.
- `Gatekeeper_Keys::generate_key()` takes an optional `$owner_email`, stored on the key and returned by `list_keys()`.

### Changed
- The route guard moved to `Gatekeeper_Route_Guard` (`includes/class-gatekeeper-route-guard.php`). `Gatekeeper_REST::guard()`, `gatekeeper_guard()` and the `gatekeeper_route_guard` filter keep working and delegate to it.

### Security
- The route guard reads an API key only from the `X-API-Key` header or `Authorization: Bearer`. A key in the query string (`?api_key=`) is refused with 400 `gatekeeper_api_key_in_url`, valid or not, and an `api_key` body field is ignored (#5992). Rotate any key that was ever sent in a URL.
- `POST /licenses/validate` no longer accepts unminted license keys (#5999). The "legacy offline license" fallback, which returned valid for any `CX-` string of 14 or more characters (the VIP tier with unlimited devices if it contained `VIP` or `MASTER`), is removed. License keys are random and carry no signature, so only keys stored in `compass_gatekeeper_licenses` validate. Keys minted only into the Chemical X edge KV store are not in that table and are refused here.
- The preview keys `CX-DEV-PREVIEW` and `QUANTUM-VIP` unlock the VIP tier only when `wp_get_environment_type()` is `local` (set `WP_ENVIRONMENT_TYPE=local`). WordPress reports `production` when nothing is set, so they are refused by default (#5999).
- A forthexp checkout whose buyer matches no WordPress user no longer mints its `xp_live_` key onto user 1 (#5999). The route guard authenticates a key's requests as its owner, so those keys acted as the administrator. The key is now minted unowned (`user_id` 0) with the customer email in its new `owner_email` field, and the webhook id and key id are logged. The customer email is also read from `customer_details.email` when `customer_email` is empty. Revoke any `forthexp.com SaaS Key` owned by user 1 that the administrator did not buy.

## [1.0.0] - 2026-09-13

### Added
- Initial release of Xophz Gatekeeper as a COMPASS companion plugin submodule.
- Core plugin class `Xophz_Compass_Gatekeeper` extending `Xophz_Compass_Plugin_Base`.
- Machine-to-machine API key authority in `Gatekeeper_Keys` supporting customizable key prefixes (defaulting to `stargate_`) and granular capability scopes.
- Software license authority in `Gatekeeper_Licenses` supporting `CX-ST-` (flat-rate eBook), `CX-SP-` (GitHub Sponsor), and `CX-VIP-` keys with device activation limits.
- Entitlement registry in `Gatekeeper_Entitlements` tracking active GitHub Sponsors across Solo, Team Power Puff, and Enterprise tiers.
- Standardized REST controller `Gatekeeper_REST` under `compass/v1/gatekeeper` extending `Xophz_Compass_REST_Controller`.
- Admin management dashboard in `Xophz_Compass_Gatekeeper_Admin`.
- Wizard logo assets (`icon.png`, `icon.svg`).
