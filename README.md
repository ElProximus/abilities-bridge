# Abilities Bridge

MCP server for WordPress with admin interface. Connect Claude AI or OpenAI to execute WordPress Abilities with configurable permissions, activity monitoring, memory storage, and OAuth 2.0 authentication.

## Overview

Abilities Bridge provides two interfaces for connecting AI to your WordPress site:

1. **Admin Chat Interface** - Built-in chatbot for direct interaction with Claude or OpenAI
2. **Claude and ChatGPT Account Connections** - Connect external AI accounts through Model Context Protocol and WordPress OAuth

## Key Features

- **AI Chatbot Interface** - Natural language interaction with your WordPress site
- **Account Connections** - Separate Claude and ChatGPT MCP/OAuth setup flows that do not use the built-in chat API keys
- **Memory Tool** - AI can maintain persistent notes in database-backed storage
- **Abilities Execution** - Run authorized WordPress Abilities with permission controls
- **Conversation Management** - Save, resume, and manage multiple conversations
- **AI Models** - Claude Opus 5, Fable 5, and Sonnet 5 plus legacy Claude models; OpenAI GPT-5.6 Terra, Sol, and Luna plus legacy GPT-5 models
- **OpenAI Responses API** - OpenAI chat requests now use the Responses API for modern tool calling and conversation handling
- **OAuth 2.0** - Secure authentication for MCP connections

## Memory

Store persistent memories in the database across conversations. This optional feature requires consent and can be enabled in Settings > Memory.

## Abilities Execution

Execute authorized WordPress Abilities with a 7-gate permission system:

- Enable/disable toggle
- Daily rate limits
- Hourly rate limits
- Per-request limits
- Risk level classification
- User approval requirements
- Admin approval requirements

Requires Abilities API or WordPress 6.9+.

## Connected Plugins Contract

Provider plugins register cards for **Abilities Bridge > Integrations** with the `abilities_bridge_plugin_integrations` filter. Register the filter callback at file-include time or no later than `plugins_loaded`; discovery runs during admin page render after `admin_init`.

Each integration entry must include `plugin_slug`, `plugin_name`, `plugin_version`, `integration_enabled`, `settings_admin_page`, `abilities`, and optional `approval_profiles`. Slugs must match `^[a-z][a-z0-9]*(-[a-z0-9]+)*$`; ability names must match `^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)+$`. `settings_admin_page` must be a local admin page slug or `admin.php?page=...`; external URLs are rejected.

Approval profiles may define an explicit `abilities` list. Without that list, a profile approves abilities whose `group` matches the profile slug. The reserved `all` profile approves every ability declared by that integration.

## Requirements

**WordPress Admin Interface:**
- WordPress 6.2 or higher
- PHP 7.4 or higher
- Anthropic API key (from [console.anthropic.com](https://console.anthropic.com)) or OpenAI API key (from [platform.openai.com](https://platform.openai.com)) or compatible API service

**MCP Integration (Optional):**
- HTTPS enabled on WordPress site (required for OAuth authentication)
- Claude Desktop, ChatGPT, or other MCP-compatible application
- Claude account (claude.ai) for Claude Desktop, or ChatGPT account with developer mode for ChatGPT

## Installation

### From WordPress.org (Recommended)

1. Install from the WordPress plugin directory
2. Activate the plugin
3. Complete the welcome wizard to grant consent
4. For the built-in chat, go to **Abilities Bridge > Settings > Built-in Chat**, enter and save your Anthropic or OpenAI API key, then use the matching test button if desired. Each test sends one short, billable request using the selected model. To connect an external account instead, use **Connect Claude** or **Connect ChatGPT**
5. Start chatting

### From GitHub Release (Manual Installation)

**Important:** Download the `abilities-bridge.zip` file from the release, NOT the "Source code" zip.

1. Go to [Releases](https://aisystemadmin.com/abilities-bridge/releases/)
2. Download `abilities-bridge.zip` from the latest release
3. Go to **WordPress Admin > Plugins > Add New > Upload Plugin**
4. Upload the `abilities-bridge.zip` file
5. Click "Install Now" then "Activate"

**Or install manually:**

```bash
cd wp-content/plugins/
unzip /path/to/abilities-bridge.zip
```

Then activate the plugin through the WordPress admin.

**Note:** Do NOT use GitHub's auto-generated "Source code (zip)" - it won't update properly. Always use the `abilities-bridge.zip` file attached to the release.

### From GitHub (Development)

For development purposes:

```bash
cd wp-content/plugins/
git clone https://aisystemadmin.com/abilities-bridge.git
cd abilities-bridge
php composer.phar install  # Install test dependencies if needed
```

Then activate the plugin through the WordPress admin.

## Claude and ChatGPT Account Connections

The built-in chat and external account connections are separate. The built-in chat uses an Anthropic or OpenAI API key stored on the site and is billed through that API account. Claude and ChatGPT account connections use MCP and WordPress OAuth; they do not use those built-in-chat API keys.

### Connect Claude to This Site — Claude Account

1. Go to **Abilities Bridge > Settings > Connect Claude**
2. Generate Claude connection credentials
3. Use the WordPress-hosted MCP endpoint directly
4. Connect from Claude Desktop or another Claude MCP client and complete OAuth with PKCE

### Connect ChatGPT to This Site — ChatGPT Account

1. Go to **Abilities Bridge > Settings > Connect ChatGPT**
2. Generate ChatGPT connection credentials
3. Copy the built-in WordPress `/mcp` endpoint
4. Add that `/mcp` endpoint in ChatGPT developer mode
5. Complete OAuth with PKCE using the ChatGPT-specific client credentials

WordPress remains the execution and authorization layer for tools in both flows.

## Security

Abilities Bridge provides comprehensive security controls:

- **Permission Controls** - All write capabilities require explicit consent
- **7-Gate Ability System** - Granular control over ability execution
- **Isolated Memory** - Memory data stored in database with size limits (50MB total, 1MB per file)
- **Complete Logging** - All actions logged in Activity Log for transparency
- **Nonce Verification** - AJAX requests protected with WordPress nonces
- **Capability Checks** - Only users with `manage_options` can use AI features
- **Authenticated MCP** - Every MCP method, including discovery (`initialize`, `tools/list`, `ping`), requires OAuth; unauthenticated requests receive an HTTP 401 with a `WWW-Authenticate` challenge
- **Token Encryption** - OAuth tokens encrypted using AES-256-CBC
- **Input Sanitization** - All user input properly sanitized and escaped

## Testing

The plugin includes comprehensive automated tests for OAuth security:

### Running Tests

```bash
# Install test dependencies
php composer.phar install

# Run all tests
vendor/bin/phpunit

# Run with detailed output
vendor/bin/phpunit --testdox

# Run with code coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Test Coverage

- âœ… **Token Encryption** - AES-256-CBC encryption/decryption
- âœ… **Token Validation** - Expiration, scope, timing-safe comparison
- âœ… **Client Management** - Credential generation, hashing, revocation
- âœ… **Format Validation** - OAuth token format compliance (RFC 6749, RFC 7636)
- âœ… **Security Tests** - Error handling, edge cases, attack prevention

For detailed testing documentation, see [`tests/README.md`](tests/README.md).

## Development

### Project Structure

```
abilities-bridge/
â”œâ”€â”€ admin/                  # Admin interface files
â”‚   â”œâ”€â”€ css/               # Admin styles
â”‚   â”œâ”€â”€ js/                # Admin JavaScript
â”‚   â”œâ”€â”€ partials/          # Admin template files
â”‚   â””â”€â”€ class-*.php        # Admin page classes
â”œâ”€â”€ assets/                # Plugin assets and templates
â”œâ”€â”€ includes/              # Core plugin classes
â”‚   â”œâ”€â”€ class-*.php        # Core functionality
â”‚   â””â”€â”€ OAuth classes      # MCP OAuth implementation
â”œâ”€â”€ abilities-bridge.php   # Main plugin file
â”œâ”€â”€ readme.txt             # WordPress.org readme
â”œâ”€â”€ uninstall.php          # Uninstall cleanup
â””â”€â”€ LICENSE                # GPL v2 license
```

### Code Standards

This plugin follows WordPress Coding Standards:

- PHP_CodeSniffer with WordPress ruleset
- Proper sanitization and escaping
- Internationalization ready
- Security best practices

### Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

Please ensure your code follows WordPress Coding Standards.

## Privacy & Data Handling

This plugin sends data to your selected AI provider â€” Anthropic's Claude API (https://api.anthropic.com) or OpenAI's API (https://api.openai.com) â€” when you interact with the AI:

- Chat messages and conversation history
- Memory contents
- Abilities execution requests

Data is only sent when you actively use the chat interface or MCP tools. No background data collection occurs.

Long-running chat requests store local job status, timing, provider/model, tool-checkpoint, and error metadata so a request can survive a closed browser. Terminal job metadata is automatically removed after 30 days.

For durable OpenAI chat jobs, the plugin uses Responses API background mode with response storage enabled so it can poll and recover an answer after the browser closes. Under OpenAI's standard data controls, stored Responses application state is retained for at least 30 days. OpenAI organizations approved for Zero Data Retention override `store: true` to false; background response data is then held temporarily for roughly 10 minutes to support polling. Provider-side retention is controlled by the OpenAI account and its current data-retention terms.

Please review [Anthropic's privacy policy](https://www.anthropic.com/legal/privacy) and/or [OpenAI's privacy policy](https://openai.com/policies/privacy-policy).

## Support

- **Website**: [aisystemadmin.com](https://aisystemadmin.com)
- **Issues**: [Support](https://aisystemadmin.com/abilities-bridge/support/)

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Credits

Built with:
- [Anthropic Claude API](https://www.anthropic.com/claude)
- [OpenAI API](https://platform.openai.com)
- [WordPress](https://wordpress.org)
- [Model Context Protocol](https://modelcontextprotocol.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.
## Account Connection Profiles

Abilities Bridge separates remote MCP setup into two account-specific flows. Neither profile uses the provider API key configured for the built-in chat.

### Claude Account Connection
- Uses the WordPress-hosted MCP endpoint directly
- Keeps Anthropic-oriented OAuth credentials separate from ChatGPT credentials
- Is intended for Anthropic MCP clients such as Claude Desktop

### ChatGPT Account Connection
- Uses the WordPress-hosted MCP endpoint directly
- Exposes the built-in HTTPS WordPress `/mcp` endpoint for ChatGPT developer mode
- Keeps OAuth, tool discovery, and tool execution inside WordPress
- Uses its own client credentials and settings tab to avoid confusion with Anthropic MCP
