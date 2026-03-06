# Changelog

All notable changes to Abilities Bridge will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- Model Context Protocol (MCP) integration for Claude Desktop
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

[1.1.0]: https://aisystemadmin.com/abilities-bridge/releases/v1.1.0/
[1.0.0]: https://aisystemadmin.com/abilities-bridge/releases/v1.0.0/
