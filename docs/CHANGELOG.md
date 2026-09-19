# Changelog

All notable changes to the Xophz Gatekeeper plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Gatekeeper_REST::guard()` and global `gatekeeper_guard()` helper function providing universal cryptographic token and permission verification across all companion plugins.
- `gatekeeper_route_guard` filter hook for dynamic REST route security registration.
- Hookshot webhook fulfillment listener (`xophz_hookshot_incoming`) for automated machine API key generation (`xp_live_*`) on completed Stripe subscription checkouts.

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
