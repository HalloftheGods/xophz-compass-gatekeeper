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

## 6. Submodule Information

- **Repository**: `git@github.com:HalloftheGods/xophz-compass-gatekeeper.git`
- **Submodule Path**: `wp-content/plugins/xophz-compass-gatekeeper`
- **Category**: Castle Walls / Security
