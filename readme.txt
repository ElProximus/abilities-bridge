=== Abilities Bridge ===
Contributors: joe12345campbell
Tags: ai, claude, openai, mcp, abilities
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


MCP server for WordPress. Connect Claude AI or OpenAI to execute WordPress Abilities with configurable permissions.

== Description ==

**Making Connections Possible** | Now with support for ChatGPT 5.4 and Custom Apps in ChatGPT

Abilities Bridge connects AI to your WordPress site. Use the built-in admin chat, connect via MCP to Claude Desktop, or integrate with other MCP-compatible applications. Supports both Anthropic (Claude) and OpenAI models.

= Key Features =

* Admin chat interface for direct AI interaction
* MCP server for Claude Desktop, ChatGPT, and other MCP clients
* Persistent memory storage across conversations
* Abilities execution with 7-gate permission controls
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

== Screenshots ==

1. Settings page with admin chat interface, API key configuration, and WP AI Client integration
2. OpenAI ChatGPT MCP setup with endpoint URL and 9-step connection guide
3. OpenAI ChatGPT MCP setup before configuration
4. Admin chat with model selection, conversation management, and AI response
5. Authorize Ability form with 7-gate permission controls
6. Ability permissions list with core read-only abilities and authorized abilities

== Changelog ==

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
* All admin actions protected with nonce verification and capability checks

= Data Retention =

Conversations and logs are stored in your WordPress database until manually deleted. Refer to your provider's privacy policy for their data retention practices.

= No Telemetry =

This plugin does NOT send usage statistics, telemetry, or analytics to the plugin developer.

== Support ==

For support, visit https://aisystemadmin.com

== License ==

This plugin is licensed under the GPL v2 or later.
