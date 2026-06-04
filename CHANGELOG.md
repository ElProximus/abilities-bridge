# Changelog

All notable changes to Abilities Bridge will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.2] - 2026-06-03

### Changed
- MCP discovery methods (`initialize`, `tools/list`, `ping`) now require authentication for every client, just like `tools/call`. The unauthenticated pre-OAuth discovery introduced in 1.3.1 has been removed: every client (Claude and the ChatGPT app builder alike) completes OAuth before any call. Fixes Claude custom-connector authentication; ChatGPT connects the same way (authenticate-first).
- Unauthenticated requests to the MCP endpoint now return HTTP 401 with a `WWW-Authenticate: Bearer resource_metadata="…"` challenge (RFC 9728 / MCP authorization), so spec-compliant MCP clients reliably initiate the OAuth flow. The JSON-RPC `-32000` error body is retained.

### Removed
- Removed the unauthenticated MCP discovery path and the associated `discovery_request` handling added in 1.3.1.

## [1.3.1] - 2026-06-01

### Added
- User-visible fallback summary of tool results when the AI provider completes a tool action but returns no final text response, instead of leaving the chat on a generic "response pending" message.

### Changed
- MCP discovery methods (`initialize`, `tools/list`, `ping`) are now reachable before OAuth so remote app builders such as ChatGPT Apps can discover available actions and then start authentication. Tool execution (`tools/call`) still requires authentication.
- Ability names are mapped to MCP-safe tool names through a single helper and resolved back by lookup against enabled abilities, replacing the lossy underscore/slash round-trip.
- Simplified the floating chat bubble by removing the in-bubble provider and model selectors and their supporting code.
- Replaced native `confirm()`/`alert()` dialogs in the admin chat with inline system messages and a two-click delete confirmation with error handling.
- Relabeled the Activity History counter from "tools used" to "activity events".

### Fixed
- Ability tools whose names contain underscores are now invoked correctly; unknown ability tools now return a clear error instead of a malformed ability lookup.

## [1.3.0] - 2026-05-29

### Added
- Chat image attachments — upload images or capture a browser-approved screenshot in both the main admin chat and the floating chat bubble (JPEG/PNG/WebP, up to 3 images per message; image-only messages are supported).
- "Enable image attachments" setting to turn the chat image upload and screenshot feature on or off.
- Claude Opus 4.8 model support (now the most intelligent option in the model dropdown).
- Documented `abilities_bridge_plugin_integrations` as the generic provider contract for Connected Plugins.
- Added safe per-callback integration discovery so one broken provider cannot white-screen the Integrations page.
- Added disabled and no-abilities integration card states with provider settings links.
- Added profile-aware approved-state storage, explicit profile ability lists, partial approval responses, and reference-aware revocation.
- Added a cleanup notice for old `beacon-send/*` approvals and permission rows.

### Changed
- Claude Opus 4.7 is no longer labeled "Most Intelligent" now that Opus 4.8 is available.
- The context-usage warning now scales to the selected model's context window instead of firing at fixed token counts, so larger-window models are no longer flagged as running low prematurely.
- Removed hardcoded Beacon Send and Site Abilities auto-detection; providers must register through the integration filter.
- Provider-supplied ability rate limits are now clamped to Bridge caps of 10,000/day and 1,000/hour.

### Security
- Chat image attachments are stored privately outside the WordPress Media Library and served only to authenticated administrators via nonce, capability, and conversation-ownership checks.
- Uploaded images are validated server-side (MIME sniffing, decoded byte size, and dimensions); SVG and non-raster types are rejected.
- Attachment files are removed when a conversation is permanently deleted or purged by retention cleanup, and the attachment directory is removed on plugin uninstall.
- Integration metadata is normalized and validated before rendering or approval.
- Integration settings links are restricted to local admin page slugs.

## [1.2.1] - 2026-04-27

### Added
- Claude Opus 4.7 model support (now the most intelligent option in the model dropdown)
- GPT-5.5 model support (now the recommended OpenAI option)
- Connected Plugins admin page — discover and approve plugins that register Abilities Bridge integrations via the `abilities_bridge_plugin_integrations` filter
- Per-model guidance text shown beneath the model selector in both the floating chat bubble and the dashboard

### Changed
- Default OpenAI model updated from GPT-5.4 to GPT-5.5
- OAuth consent flow now uses request-bound consent tokens stored in 5-minute transients; nonces are tied to each specific authorization request and the token is consumed once on success
- Conversation lookup, delete, and restore operations are now scoped to the current user

### Fixed
- Memory tool path validator no longer accepts paths sharing the `/memories` prefix but living outside the namespace (e.g. `/memoriesXYZ`)
- Conversation constructor now verifies the row exists and belongs to the current user before loading messages

### Security
- Closed a cross-user-access issue where any admin could view, delete, or restore another admin's conversation by guessing the conversation ID
- OAuth consent tokens are now single-use and request-bound, eliminating replay of captured consent forms

## [1.2.0] - 2026-03-10

### Added
- WP AI Client credential integration for shared API keys via WordPress Connectors
- WP AI Client integration test page
- Separate settings flows for Anthropic MCP and OpenAI ChatGPT MCP
- Direct OpenAI ChatGPT MCP flow served from the built-in WordPress MCP endpoint
- Floating chat bubble for administrators on front-end and admin pages

### Changed
- MCP OAuth client storage is now profile-aware for Anthropic MCP and OpenAI ChatGPT MCP
- MCP discovery metadata now includes protected-resource metadata and PKCE-focused OAuth details
- MCP tools/list now filters visible tools using the current authenticated permissions before exposing them
- OpenAI chat integration migrated to the Responses API

## [1.1.1] - 2026-03-06

### Added
- GPT-5.4 model support
- Token configurations for GPT-5.4 models (1.05M input / 128K output)

### Changed
- Default OpenAI model updated from GPT-5.2 to GPT-5.4
- Older OpenAI models marked as Legacy

## [1.1.0] - 2026-03-04

### Added
- OpenAI as an alternative AI provider
- Learn More settings tab with overview video and resources

### Changed
- Updated Pro Features tab with Concierge Service and Site Abilities Plugin
- Updated About tab to reflect multi-provider support
- Streamlined WordPress.org readme

## [1.0.0] - 2025-12-11

### Added
- Initial release of Abilities Bridge WordPress plugin
- Model Context Protocol (MCP) integration for Claude Desktop and ChatGPT
- OAuth 2.0 authentication with client credentials flow
- Memory tool for persistent AI context across sessions (database-backed)
- Admin chat interface for direct WordPress interaction
- Comprehensive settings page with tabbed interface
- Pro Features tab
- Ability system with 7-gate permission controls
- Activity logging and audit trails
- Welcome wizard for first-time setup
- Claude and OpenAI model support

### Security
- AES-256-CBC encryption for OAuth tokens
- Token encryption with no plaintext fallback
- Rate limiting and permission controls for abilities
- HTTPS requirement for MCP connections
- Nonce verification for all admin actions
- Capability checks for all operations

### Documentation
- Comprehensive README with setup instructions
- WordPress.org compliant readme.txt
- Test suite documentation in tests/README.md
- WordPress Coding Standards compliance

[1.2.0]: https://aisystemadmin.com/abilities-bridge/releases/v1.2.0/
[1.1.1]: https://aisystemadmin.com/abilities-bridge/releases/v1.1.1/
[1.1.0]: https://aisystemadmin.com/abilities-bridge/releases/v1.1.0/
[1.0.0]: https://aisystemadmin.com/abilities-bridge/releases/v1.0.0/

