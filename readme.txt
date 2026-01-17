=== Abilities Bridge ===
Contributors: joe12345campbell
Tags: ai, claude, admin, mcp, abilities
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


MCP server for WordPress. Connect Claude AI to execute WordPress Abilities with configurable permissions.

== Description ==

Abilities Bridge provides an MCP (Model Context Protocol) server for WordPress, allowing Claude AI to execute WordPress Abilities with granular permission controls. It includes an admin chat interface and persistent memory storage.

== External Services ==

**IMPORTANT: This plugin connects to external API services.**

This plugin requires communication with Anthropic's Claude API or a compatible API service to function. Depending on how you use the plugin:

**Option 1: WordPress Admin Interface (API Key)**
* Requires your own Anthropic API key from console.anthropic.com or compatible service
* Uses API credits from your account
* Data sent directly from your server to the API provider

**Option 2: MCP Connection (Claude Account or Anthropic API)**
* Requires a Claude account from claude.ai or Anthropic API key
* Connect via Claude Desktop or other MCP clients
* Uses your Claude subscription quota or API credits
* Data sent from MCP client to Anthropic

= What Data Is Sent to Anthropic =

When you use this plugin, the following data is transmitted to Anthropic's Claude API (https://api.anthropic.com):

* Your chat messages and prompts
* Memory contents
* Abilities execution requests and results

= When Data Is Sent =

* Every time you send a message in the chat interface
* When Claude Desktop or other MCP clients use WordPress tools
* When the AI executes abilities

**No data is sent to Anthropic without your explicit action** (sending a chat message or using MCP tools). The plugin does not send telemetry or usage statistics to any third party.

= Legal & Privacy Information =

* Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy
* Anthropic Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms
* Claude.ai Terms: https://claude.ai/legal

By using this plugin, you acknowledge that data will be transmitted to Anthropic's servers for processing. Please review their privacy policy before use.

= Key Features =

* **AI Chatbot Interface** - Natural language interaction with your WordPress site
* **MCP Integration** - Connect WordPress to Claude Desktop and other MCP-compatible apps
* **Memory Tool** - AI can maintain persistent notes in a database-backed storage system
* **Abilities Execution** - Run authorized WordPress Abilities with permission controls
* **Conversation Management** - Save, resume, and manage multiple conversations
* **Claude Models** - Choose between Opus 4.5 (most intelligent), Sonnet 4.5 (balanced), or Haiku 4.5 (fastest)
* **OAuth 2.0** - Secure authentication for MCP connections

= Memory =

Store persistent memories in the database across conversations. This optional feature requires consent and can be enabled in Settings > Memory.

= Remote Abilities Execution =

Execute authorized WordPress Abilities with a 7-gate permission system:
* Enable/disable toggle
* Daily rate limits
* Hourly rate limits
* Per-request limits
* Risk level classification
* User approval requirements
* Admin approval requirements

Requires Abilities API or WordPress 6.9+.

= Perfect For =

* Automating WordPress tasks via Abilities
* Using WordPress with Claude Desktop or other MCP clients
* Maintaining persistent AI memory across sessions
* Site management and administration

= Three Ways to Use =

**1. WordPress Admin Interface**
- Built-in chat interface in WordPress admin
- **Requires**: Anthropic API key (console.anthropic.com)
- **Uses**: Your Anthropic API credits

**2. Claude Custom Connector (Model Context Protocol)**
- Connect WordPress to Claude Desktop web & app
- **Requires**: Claude Pro account
- **No API key needed**
- **Uses**: Your Claude subscription quota
- No local setup needed

**3. Local Configuration (Model Context Protocol)**
- Connect WordPress to Claude Code and other applications
- **Requires**: Either Claude account OR Anthropic API key
- Local configuration file setup needed
- **Uses**: Your Claude subscription OR API credits

= Requirements =

**For WordPress Admin Interface:**
* Anthropic API key (from console.anthropic.com) or compatible API service

**For MCP OAuth 2.0 Connection:**
* Claude account (claude.ai) or Anthropic API key (from console.anthropic.com)
* HTTPS enabled on your WordPress site

**For All Methods:**
* WordPress 6.2+, PHP 7.4+
* Active internet connection (plugin sends data to the API service)
* Abilities API required for abilities execution (optional)

== Installation ==

1. Upload the `abilities-bridge` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Complete the welcome wizard to grant consent
4. **Choose your connection method:**
   - **Admin Interface**: Enter your Anthropic API key in Settings
   - **MCP OAuth 2.0**: Follow MCP setup below

= Claude Custom Connector MCP OAuth 2.0 Setup (Claude Account Required) =

1. Go to Abilities Bridge > Settings > MCP Server Setup
2. Click "Generate New Client Credentials"
3. Save both Client ID and Client Secret
4. In Claude Desktop: Settings → Connectors → Add custom connector
5. Enter credentials and MCP endpoint URL from WordPress
6. Authenticate with your Claude account

== Frequently Asked Questions ==

= Do I need an Anthropic API key to use this plugin? =

**It depends on how you want to use it:**

* **WordPress Admin Interface**: YES - Requires Anthropic API key from console.anthropic.com or compatible API service
* **MCP Claude Desktop**: NO - Only requires a Claude account
* **MCP Local Config**: Either API key OR Claude account

= Can I use my Claude subscription instead of paying for API credits? =

Yes! Use the OAuth 2.0 MCP. This connects your WordPress site to Claude using your existing Claude subscription. No Anthropic API key or API credits required.

= What's the difference between API key and Claude account? =

* **Anthropic API Key**: Developer account with pay-per-token pricing at console.anthropic.com
* **Claude Account**: Consumer subscription at claude.ai

= Is this safe to use? =

Abilities Bridge provides granular permission controls for all capabilities. Memory data is stored in the database with size limits. Abilities execution requires explicit consent and individual ability authorization.

= Where do I get an Anthropic API key? =

Visit https://console.anthropic.com/ to sign up and generate an API key. However, you only need this for the WordPress admin interface. If using MCP OAuth 2.0, you just need a Claude account from claude.ai.

= What data does this plugin send to external services? =

This plugin sends data to Anthropic's Claude API (https://api.anthropic.com) including:
* Your chat messages
* Memory contents
* Abilities execution requests

Data is only sent when you actively use the chat interface or MCP tools. No background data collection occurs.

= Is my data secure? =

Data transmission to Anthropic uses HTTPS encryption. Your API key (if used) is stored in your WordPress database. OAuth tokens (for MCP) are encrypted using AES-256-CBC.

Please review Anthropic's security practices and privacy policy at https://www.anthropic.com/legal/privacy

= Can I use this plugin offline? =

No. This plugin requires an active internet connection to communicate with the API service. Without internet access or valid authentication (API key or Claude account), the AI features will not function.

= Does this plugin collect telemetry? =

No. This plugin does NOT send usage statistics, error reports, or any telemetry to the plugin developer. The only external communication is with the configured API service for AI functionality.

= What is the Memory Tool? =

The Memory Tool allows Claude to store persistent memories in the WordPress database. It's limited to 1MB per entry and 50MB total. Enable it in Settings > Memory.

= What are Abilities? =

Abilities allow Claude to execute WordPress functions (like creating posts, managing users, etc.) when authorized. Each ability must be individually enabled in Abilities Bridge > Abilities. Requires Abilities API or WordPress 6.9+.

= How do I customize Claude's behavior? =

Go to Settings > Abilities Bridge and edit the System Prompt section. Click "Restore Default Prompt" to reset.

= What is Model Context Protocol (MCP)? =

MCP is an open standard that allows AI applications like Claude Desktop to connect to external systems. With MCP, you can use WordPress tools directly from Claude Desktop without needing an Anthropic API key.

= Do I need MCP to use Abilities Bridge? =

No! MCP is optional. You can use the built-in WordPress chat interface (requires API key) or connect via MCP OAuth 2.0 (requires Claude account or API key).

== Changelog ==

= 1.0.0 =
* Initial release
* MCP server for WordPress Abilities execution
* Memory tool with database storage
* OAuth 2.0 authentication
* Admin chat interface

== Privacy & Security ==

= Data Transmission =

**This plugin sends data to Anthropic's API** (https://api.anthropic.com) when you interact with the AI:
* Chat messages and conversation history
* Memory contents
* Abilities execution requests

**Authentication Methods:**
* **API Key**: Data sent directly from your WordPress server to Anthropic
* **OAuth 2.0**: Data sent from MCP client (like Claude Desktop) to Anthropic

**You control what data is sent** - The AI only accesses data when you ask it to, or when you enable specific tools.

= Security Features =

* **Permission Controls** - All write capabilities require explicit consent
* **Isolated Memory** - Memory data stored in database with size limits (50MB total, 1MB per file)
* **Abilities Authorization** - Each ability must be individually enabled with a 7-gate permission system
* **Complete Logging** - All actions logged in Activity Log for transparency
* **Nonce Verification** - All admin actions protected against CSRF attacks
* **Capability Checks** - Only users with manage_options can use AI features
* **Token Encryption** - OAuth tokens encrypted using AES-256-CBC

= Data Retention =

* **WordPress**: Conversations and logs stored in your database until manually deleted
* **Anthropic**: Refer to Anthropic's data retention policy at https://www.anthropic.com/legal/privacy

= No Telemetry =

This plugin does **NOT** send any usage statistics, telemetry, or analytics to the plugin developer or any third party (except Anthropic for AI functionality).

== Support ==

For support, visit https://aisystemadmin.com

== License ==

This plugin is licensed under the GPL v2 or later.
