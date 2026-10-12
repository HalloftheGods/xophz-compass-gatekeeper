# Changelog

All notable changes to the Xophz Gatekeeper plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (XP Pro, Do It For The XP)
- **License store (#6006)**: `Gatekeeper_Xp_Pro_Licenses` keeps one record per family in `gk_xp_pro_{family_id}` (autoload off) with lookup options for the key (`XP-PRO-XXXX-XXXX-XXXX`), Stripe subscription, payment intent and customer ids, and the purchase email. Idempotent grants, a lifetime plan that later subscription events never shorten, expire and revoke that keep the record, the 3 day grace window, 10 devices per license and the last 50 event ids. Never touches `compass_gatekeeper_licenses`.
- **Stripe and RevenueCat adapters (#6007, #6008)**: `Gatekeeper_Xp_Pro_Stripe` and `Gatekeeper_Xp_Pro_RevenueCat` turn each event into one license operation (pure, tested row by row with real-shaped fixtures). A full refund of a subscription charge revokes by customer when the charge's payment intent was never stored.
- **Hookshot intake and admin (#6009)**: `Gatekeeper_Xp_Pro_Intake` handles `xophz_hookshot_incoming` only for the two webhook ids in `gatekeeper_xp_pro_webhooks` and only when `Hookshot_Signature::was_verified()`; the id picks the adapter. A new key is emailed once. Settings > Gatekeeper XP Pro holds the webhook ids, `xp_pro_ai_daily_limit` and the license list with Reset devices.
- **REST (#6010)**: `GET /xp-pro/plans`, `POST /xp-pro/claim`, `/validate`, `/recover` and `/manage`, public and rate limited per client address (30 a minute, recover 5 an hour).
- **AI helpers proxy (#6012)**: `POST /xp-pro/ai` (`Gatekeeper_Xp_Pro_AI`) replaces the Gemini key that shipped in the Do It For The XP app. It needs an entitled XP Pro license on a registered device (sandbox licenses only where `wp_get_environment_type()` is `local` or `development`), accepts only the tasks `monster_name`, `checklist`, `full_beast` and `schedule` with their structured, length-capped fields (never a prompt), keeps the prompts in PHP, sends the key in the `x-goog-api-key` header with the Google connector's model (fallback `gemini-3.6-flash`), parses each reply to its task's JSON shape (a malformed reply is a 502), and allows 100 calls per license per day (`xp_pro_ai_daily_limit`).

### Fixed (XP Pro review)
- **Refunds, sources and env (#6013)**: records track `period_source` (`stripe:<sub id>` or `revenuecat:<product id>`) and, for lifetime, `lifetime_source`. grant and extend keep the later `expires_at`; expire applies only from the current period source; only a refund of the lifetime purchase (or a dispute on it) revokes, any other refund ends the paid period as `expired` (the next `invoice.paid` or RENEWAL reactivates it) and never touches an active lifetime. Production wins: a sandbox event never changes a production record, and a production event on a sandbox record sets it to production. Settings > Gatekeeper XP Pro gains a Restore button (manage_options + nonce) that sets a record active and notes the admin user id and time.
- **Locks and atomic counters (#6014)**: `Gatekeeper_Xp_Pro_Licenses::apply()`, `attach_device()`, `reset_devices()` and `restore()` run under a per-family MySQL named lock (`GET_LOCK('gk_xp_pro_{family_id}', 10)`, released in a `finally`) and re-read the record after taking it; a lock that cannot be taken in 10 s writes nothing and logs the event id. The AI daily cap and the REST rate limits use `Gatekeeper_Xp_Pro_Db`, one `INSERT ... ON DUPLICATE KEY UPDATE option_value = option_value + 1` per hit on a counter whose name carries its window (unix window start for rate limits, `Ymd` for the daily cap), with an atomic decrement when a Gemini call fails. A daily cron event (`gk_xp_pro_cleanup_counters`) deletes counters older than 2 days.

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
