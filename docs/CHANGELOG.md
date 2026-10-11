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

### Changed
- The route guard moved to `Gatekeeper_Route_Guard` (`includes/class-gatekeeper-route-guard.php`). `Gatekeeper_REST::guard()`, `gatekeeper_guard()` and the `gatekeeper_route_guard` filter keep working and delegate to it.

### Security
- The route guard reads an API key only from the `X-API-Key` header or `Authorization: Bearer`. A key in the query string (`?api_key=`) is refused with 400 `gatekeeper_api_key_in_url`, valid or not, and an `api_key` body field is ignored (#5992). Rotate any key that was ever sent in a URL.

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
