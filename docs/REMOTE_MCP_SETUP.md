# Remote MCP Connector Setup Guide

This guide explains how to connect your WordPress site to Claude Desktop using the Model Context Protocol (MCP) remote connector.

## Overview

Abilities Bridge implements a **remote MCP connector** that runs directly on your WordPress hosting. This means:

- No local setup scripts required
- No Node.js installation needed
- No additional dependencies
- Works on any WordPress hosting (shared, VPS, cloud)
- OAuth 2.0 authentication
- Can be added via Claude Desktop GUI

The MCP server is written in PHP and runs via WordPress REST API endpoints.

## Prerequisites

### Required
- WordPress 6.2 or higher
- PHP 7.4 or higher
- **HTTPS enabled** (required for OAuth authentication)
- Claude Desktop app or other MCP-compatible client

### Not Required
- Node.js
- npm or package managers
- Local MCP server
- Setup scripts
- Build tools

## Setup Guide

### Step 1: Generate Client Credentials

1. Log in to your WordPress admin
2. Navigate to **Abilities Bridge > Settings**
3. Scroll to **MCP Server Setup** section
4. Click **"Generate New Client Credentials"**
5. **Save both credentials immediately** - the client secret is only shown once!

Example credentials:
```
Client ID:     mcp_xK9mXp2jR4wN7qL3
Client Secret: fH8sB6tY1cZ5gV0aU4dE2rI9xQ7wS3nM8bF6hJ1kP5yT0vL4
```

### Step 2: Get Your MCP Endpoint URL

Your MCP endpoint URL is displayed in the settings page. It follows this format:

```
https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp
```

**Important:** The URL must be HTTPS. HTTP will not work for OAuth authentication.

### Step 3: Add Connector in Claude Desktop

1. Open **Claude Desktop**
2. Navigate to **Settings > Connectors**
3. Click **"Add custom connector"**
4. Fill in the form:
   - **Name:** WordPress (or any name you prefer)
   - **Remote MCP server URL:** Paste your endpoint URL from Step 2
   - **OAuth Client ID:** Paste your Client ID from Step 1
   - **OAuth Client Secret:** Paste your Client Secret from Step 1
5. Click **"Add connector"**

Claude Desktop will automatically:
- Exchange credentials for access tokens
- Refresh tokens when needed
- Handle authentication in the background

### Step 4: Restart Claude Desktop

1. **Completely quit** Claude Desktop (don't just close the window)
   - **Mac:** Cmd+Q or Claude > Quit
   - **Windows:** Right-click taskbar icon > Exit
   - **Linux:** File > Quit or close window
2. **Wait 5 seconds**
3. **Reopen** Claude Desktop

### Step 5: Verify Connection

1. Open a new conversation in Claude Desktop
2. Look for the **hammer icon** in the chat input area
3. Click the hammer icon
4. You should see available WordPress tools

**Success!** You can now use WordPress tools from Claude Desktop.

---

## Available Tools

Once connected, Claude can use these tools:

### memory (Optional)
**Type:** Read/Write (database storage)
**Description:** Persistent storage for notes across conversations

**Requirements:** Must be enabled in Settings and consent provided.

**Example usage:**
```
Remember that this site uses WooCommerce and has a custom checkout flow
```

### WordPress Abilities
Any abilities registered via the WordPress Abilities API and enabled in Ability Permissions will be available. Common abilities include:
- `get-site-info` - Get WordPress site information
- `get-user-info` - Get user roles and permissions
- `get-environment-info` - Get server environment details

---

## Security & Authentication

### OAuth 2.0 Client Credentials Flow

The remote MCP connector uses the standard OAuth 2.0 Client Credentials grant type:

**Client Credentials:**
- **Client ID:** Unique identifier (e.g., `mcp_xK9mXp2jR4wN7qL3`)
- **Client Secret:** Secure random string
- **Storage:** Client secrets stored hashed in WordPress database
- **Lifetime:** Persistent until manually revoked

**Access Tokens:**
- **Generation:** Automatically exchanged from client credentials
- **Lifetime:** 1 hour
- **Refresh:** Automatic via refresh tokens

**Refresh Tokens:**
- **Lifetime:** 30 days
- **Purpose:** Obtain new access tokens without re-entering credentials
- **Auto-refresh:** Claude Desktop handles automatically

**Security Features:**
- Timing-safe comparisons prevent timing attacks
- HTTPS requirement for all requests
- Hashed storage for client secrets
- AES-256-CBC encryption for tokens
- Automatic cleanup of expired tokens

### Access Control

- **User Capability:** Credentials tied to WordPress users with `manage_options` capability
- **Memory Isolation:** Memory tool limited to database storage with size limits
- **Size Limits:** Memory entries limited to 1MB each, 50MB total
- **Ability Permissions:** 7-gate permission system for all abilities

### Credential Management

**View Active Clients:**
```
Abilities Bridge > Settings > MCP Server Setup > Manage Client Credentials
```

**Revoke Client:**
1. Go to **Manage Client Credentials** section
2. Click **Revoke** next to the client
3. Confirm revocation
4. All associated tokens are immediately invalidated

**Best Practices:**
- Save credentials in a secure password manager immediately
- Generate separate credentials for different devices
- Revoke credentials when you stop using a device
- Don't share credentials with others
- Don't commit credentials to version control

---

## Troubleshooting

### Tools Not Showing Up

**Symptoms:** No hammer icon in Claude Desktop, or tools don't appear

**Solutions:**
1. Verify you completely quit and restarted Claude Desktop
2. Check credentials are correct
3. Ensure URL uses HTTPS (not HTTP)
4. Check Claude Desktop logs for errors

**Mac Logs:**
```
~/Library/Logs/Claude/mcp*.log
```

**Windows Logs:**
```
%APPDATA%\Claude\logs\mcp*.log
```

### Authentication Errors

**Symptoms:** "Authentication required" or "Invalid client" errors

**Solutions:**
1. Verify HTTPS is enabled on WordPress site
2. Check client credentials haven't been revoked
3. Regenerate client credentials and update config
4. Ensure WordPress user has `manage_options` capability

**Test OAuth token endpoint:**
```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "client_credentials",
    "client_id": "YOUR_CLIENT_ID",
    "client_secret": "YOUR_CLIENT_SECRET"
  }'
```

### HTTPS Not Available

**Symptoms:** "HTTPS Required" error

**Solutions:**
1. Enable SSL certificate on your hosting
2. Use Let's Encrypt for free SSL certificates
3. Contact your hosting provider for SSL setup

**Note:** OAuth authentication requires HTTPS for security.

---

## Architecture Details

### Endpoints

**OAuth 2.0 Endpoints:**
```
POST /wp-json/abilities-bridge-mcp/v1/oauth/token
```
Token exchange endpoint (client_credentials and refresh_token grant types)

```
POST /wp-json/abilities-bridge-mcp/v1/oauth/revoke
```
Token revocation endpoint

**Main MCP Endpoint:**
```
POST /wp-json/abilities-bridge-mcp/v1/mcp
```

Accepts JSON-RPC 2.0 requests with methods:
- `initialize` - Server capabilities
- `tools/list` - Available tools
- `tools/call` - Execute tool
- `ping` - Health check

### Protocol

- **Transport:** Streamable HTTP (MCP spec)
- **Format:** JSON-RPC 2.0
- **Authentication:** OAuth 2.0 Client Credentials grant
- **Token Type:** Bearer tokens
- **Token Lifetime:** Access tokens (1 hour), Refresh tokens (30 days)

---

## Support

### Documentation
- WordPress Admin: Abilities Bridge > Settings > MCP Server Setup
- MCP Specification: https://modelcontextprotocol.io/

### Common Questions

**Q: Do I need an Anthropic API key for MCP?**
A: No! For MCP integration, you use your Claude Desktop subscription. The Anthropic API key is only needed for the WordPress admin chat interface.

**Q: Can I use this with other MCP clients?**
A: Yes! Any MCP-compatible client can connect using the remote connector protocol.

**Q: Is my data secure?**
A: Yes. All communication uses HTTPS, tokens are encrypted, and abilities are subject to the 7-gate permission system.

**Q: Can I use this on shared hosting?**
A: Yes! The MCP server runs via PHP/WordPress REST API, so it works on any hosting that supports WordPress with HTTPS.
