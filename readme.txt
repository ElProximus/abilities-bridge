=== Abilities Bridge ===
Contributors: joe12345campbell
Tags: ai, claude, openai, mcp, abilities
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


MCP server for WordPress. Connect Claude AI or OpenAI to execute WordPress Abilities with configurable permissions.

== Description ==

**Making Connections Possible** | Now with Claude Opus 4.8, in-chat image attachments and screenshots, GPT-5.5, and Custom Apps in ChatGPT

Abilities Bridge connects AI to your WordPress site. Use the built-in admin chat, connect via MCP to Claude Desktop, or integrate with other MCP-compatible applications. Supports both Anthropic (Claude) and OpenAI models.

= Key Features =

* Admin chat interface for direct AI interaction
* Image attachments in chat — upload images or capture a browser-approved screenshot (can be disabled in settings)
* MCP server for Claude Desktop, ChatGPT, and other MCP clients
* Persistent memory storage across conversations
* Abilities execution with 7-gate permission controls
* Integrated Connected Plugins — discover and approve AI abilities that other plugins register, with per-ability permission controls
* Claude and OpenAI model support
* OAuth 2.0 authentication for MCP connections

= Four Ways to Connect =

1. **Admin Chat** - Built-in interface using your Anthropic or OpenAI API key
2. **Claude Custom Connector** - Connect Claude Desktop using your Claude subscription (no API key needed)
3. **ChatGPT Developer Mode** - Connect ChatGPT using the built-in MCP endpoint with OAuth
4. **Local MCP Config** - Connect Claude Code and other apps using API key or Claude account

= Requirements =

* WordPress 6.2+, PHP 7.4+
* Anthropic API key, OpenAI API key, or Claude account (depending on connection method)
* HTTPS required for MCP OAuth 2.0 connections

== External Services ==

**This plugin connects to external API services.**

This plugin communicates with Anthropic's Claude API (https://api.anthropic.com) and/or OpenAI's API (https://api.openai.com) to provide AI functionality. Data is only sent when you actively use the chat interface or MCP tools. No background data collection or telemetry occurs.

Durable OpenAI chat jobs use Responses API background mode with response storage enabled so the plugin can poll and recover an answer after the browser closes. Under OpenAI's standard data controls, stored Responses application state is retained for at least 30 days. For OpenAI organizations approved for Zero Data Retention, OpenAI treats storage as disabled and temporarily stores background response data for roughly 10 minutes to support polling.

= Data Sent =

* Chat messages and prompts
* Memory contents
* Abilities execution requests and results

= Legal & Privacy =

* Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy
* Anthropic Terms: https://www.anthropic.com/legal/consumer-terms
* OpenAI Privacy Policy: https://openai.com/policies/privacy-policy
* OpenAI Terms: https://openai.com/policies/terms-of-use
* Abilities Bridge Privacy Policy: https://aisystemadmin.com/privacy-policy
* Abilities Bridge Terms: https://aisystemadmin.com/terms-and-conditions/

By using this plugin, you acknowledge that data will be transmitted to your selected AI provider for processing.

== Installation ==

1. Upload the `abilities-bridge` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Complete the welcome wizard to grant consent
4. Enter your Anthropic or OpenAI API key in Settings, or set up MCP in Settings > MCP Setup

= MCP OAuth 2.0 Setup =

**For Claude Desktop:**

1. Go to Abilities Bridge > Settings > Anthropic MCP
2. Click "Generate New Anthropic Client Credentials"
3. Save both Client ID and Client Secret
4. In Claude Desktop: Settings > Connectors > Add custom connector
5. Enter credentials and MCP endpoint URL from WordPress

**For ChatGPT:**

1. Go to Abilities Bridge > Settings > OpenAI ChatGPT MCP
2. Click "Generate New ChatGPT Client Credentials"
3. Save both Client ID and Client Secret
4. In ChatGPT: Settings > Apps > Advanced Settings > Enable developer mode
5. Create app, add MCP endpoint URL, choose OAuth, and enter credentials

== Frequently Asked Questions ==

= Do I need an API key? =

For the admin chat interface, yes - an Anthropic or OpenAI API key is required. For MCP via Claude Desktop, you only need a Claude account (no API key needed). For ChatGPT, you need a ChatGPT account with developer mode enabled.

= Where do I get an API key? =

* **Anthropic**: https://console.anthropic.com/
* **OpenAI**: https://platform.openai.com/

= Do I need the Abilities API? =

Yes. The Abilities API is the official WordPress API for AI. It comes standard with WordPress 6.9 and is also available as a plugin.

= Is this safe to use? =

All capabilities require explicit consent. Abilities use a 7-gate permission system with rate limits, risk levels, and admin approval. All actions are logged.

= What data is sent to external services? =

Chat messages, memory contents, and abilities execution requests are sent to your selected AI provider. Data is only sent when you actively use the plugin. No telemetry or usage statistics are collected.

= What is the Memory Tool? =

An optional feature that lets AI store persistent notes in the WordPress database across conversations. Limited to 1MB per entry, 50MB total. Enable in Settings > Memory.

= What are Abilities? =

AI-callable WordPress functions (creating posts, managing users, etc.) that must be individually authorized. Each ability is controlled by rate limits, risk levels, and approval requirements.

= What is Beacon Campaign Sender? =

Beacon Campaign Sender is a separate plugin that connects to Abilities Bridge as an Integrated Connected Plugin. When it is installed and active, it registers its tools with Abilities Bridge and they appear on the Integrations page. Approve them with one click to let AI agents use Beacon's tools, with the same per-ability permission controls as every other ability. Nothing is enabled until you approve it.

== Screenshots ==

1. Settings page with admin chat interface, API key configuration, and WP AI Client integration
2. OpenAI ChatGPT MCP setup with endpoint URL and 9-step connection guide
3. OpenAI ChatGPT MCP setup before configuration
4. Admin chat with model selection, conversation management, and AI response
5. Authorize Ability form with 7-gate permission controls
6. Ability permissions list with core read-only abilities and authorized abilities

== Changelog ==

= 1.3.3 =
* Fixed the one-time display of newly generated MCP client credentials never appearing on sites with a broken or evicting external object cache (e.g. LiteSpeed/Redis/Memcached object caching). The pending credentials are now stored in the database instead of a transient, shown once, then deleted
* Fixed OAuth authorization failing with "Authorization request has expired or is invalid" on the same sites: in-flight authorization requests and consent tokens are now stored in the database instead of transients, so connecting Claude or ChatGPT works even when the host's object cache is broken, restarted, or evicting

= 1.3.2 =
* MCP discovery (initialize, tools/list, ping) now requires authentication for every client, the same as running a tool; the unauthenticated pre-OAuth discovery added in 1.3.1 has been removed. This fixes Claude custom connectors (which connect authenticate-first); ChatGPT connects the same way via OAuth
* Unauthenticated MCP requests now return an HTTP 401 with a WWW-Authenticate challenge so MCP clients reliably start the OAuth flow

= 1.3.1 =
* MCP discovery (initialize, tools/list, ping) is now available before OAuth so remote app builders such as ChatGPT Apps can discover actions and then authenticate; running a tool still requires authentication
* Ability names are now mapped to MCP-safe tool names and resolved back by lookup, fixing tool calls for abilities whose names contain underscores
* Chat now returns a useful summary of tool results when the AI provider completes a tool action but returns no final text, instead of getting stuck on a "response pending" message
* Simplified the floating chat bubble by removing the in-bubble provider and model selectors
* Replaced browser confirm/alert popups in the admin chat with inline messages and a two-click delete confirmation

= 1.3.0 =
* Added chat image attachments — upload images or capture a browser-approved screenshot in the main chat and floating bubble (JPEG/PNG/WebP, up to 3 per message)
* Added an "Enable image attachments" setting to turn the upload and screenshot feature on or off
* Added Claude Opus 4.8 support and relabeled Opus 4.7
* Context-usage warning now scales to the model's context window instead of firing at fixed token counts
* Private attachment storage with server-side validation and authenticated, ownership-checked serving; files are cleaned up on conversation delete and plugin uninstall
* Replaced hardcoded Connected Plugins detection with the documented abilities_bridge_plugin_integrations contract
* Added validation, safe per-callback discovery, disabled integration cards, profile-aware approvals, and partial approval messages
* Added cleanup notice for old beacon-send/* approvals from pre-release Beacon Send integrations
* Breaking change for private/pre-release partner integrations: providers must register themselves through the integration filter

= 1.2.1 =
* Added Claude Opus 4.7 model support (most intelligent option)
* Added GPT-5.5 model support (now the default OpenAI option)
* Added Connected Plugins admin page for discovering and approving Abilities Bridge integrations
* Added per-model guidance text in the chat bubble and dashboard
* Conversation lookup, delete, and restore are now scoped to the current user — admins can no longer access other admins' conversations by ID
* OAuth consent now uses request-bound, single-use consent tokens with 5-minute transients
* Memory tool path validator no longer accepts paths sharing the /memories prefix but living outside the namespace (e.g. /memoriesXYZ)
* Default OpenAI model updated from GPT-5.4 to GPT-5.5

= 1.2.0 =
* Added WP AI Client credential integration for shared API keys via WordPress Connectors
* Added WP AI Client integration test page
* Added separate settings flows for Anthropic MCP and OpenAI ChatGPT MCP
* Added direct OpenAI ChatGPT MCP flow from built-in WordPress MCP endpoint
* Added floating chat bubble for administrators
* MCP OAuth client storage is now profile-aware
* MCP tools/list now filters visible tools using authenticated permissions
* OpenAI chat integration migrated to the Responses API

= 1.1.1 =
* Added GPT-5.4 model support
* Updated default OpenAI model to GPT-5.4

= 1.1.0 =
* Added OpenAI as an alternative AI provider
* Added Learn More tab with overview video and resources
* Updated Pro Features tab with Concierge Service and Site Abilities Plugin

= 1.0.0 =
* Initial release
* MCP server for WordPress Abilities execution
* Memory tool with database storage
* OAuth 2.0 authentication
* Admin chat interface

== Privacy & Security ==

= Data Transmission =

This plugin sends data to Anthropic's API (https://api.anthropic.com) or OpenAI's API (https://api.openai.com) when you interact with the AI. This includes chat messages, memory contents, and abilities execution requests. You control what data is sent - the AI only accesses data when you use it.

= Security =

* Permission controls with explicit consent for all write capabilities
* 7-gate ability authorization system
* Isolated memory storage with size limits (50MB total)
* Full activity logging and audit trails
* OAuth tokens encrypted with AES-256-CBC
* MCP access requires authentication for every method, including discovery (initialize, tools/list, ping); unauthenticated requests receive an HTTP 401 OAuth challenge
* All admin actions protected with nonce verification and capability checks

= Data Retention =

Conversations and logs are stored in your WordPress database until manually deleted. Background chat jobs also store status, timing, provider/model, tool-checkpoint, and error metadata; terminal job metadata is automatically removed after 30 days. Durable OpenAI jobs enable Responses API storage for polling and recovery; OpenAI normally retains that application state for at least 30 days, while approved Zero Data Retention organizations use temporary background storage instead. Refer to your provider's privacy policy and account data controls for current retention practices.

= No Telemetry =

This plugin does NOT send usage statistics, telemetry, or analytics to the plugin developer.

== Support ==

For support, visit https://aisystemadmin.com

== License ==

This plugin is licensed under the GPL v2 or later.
