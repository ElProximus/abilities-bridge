# Testing Guide for Abilities Bridge

This guide provides testing procedures for the Abilities Bridge MCP connector.

## Overview

The remote MCP connector allows Claude Desktop to connect to your WordPress site via HTTPS using OAuth 2.0 authentication.

## Testing Checklist

- [ ] Environment setup complete
- [ ] OAuth client credentials generation working
- [ ] MCP endpoint accessible
- [ ] Memory tool functional (if enabled)
- [ ] Abilities execution working (if configured)
- [ ] Error handling correct
- [ ] Security measures validated

---

## Pre-Testing Setup

### 1. Environment Requirements

**Required:**
- WordPress 6.2 or higher
- PHP 7.4 or higher
- HTTPS/SSL enabled
- WordPress user with `manage_options` capability
- Claude Desktop app installed

### 2. Plugin Activation

1. Activate Abilities Bridge plugin
2. Complete the welcome wizard and provide consent
3. Navigate to Abilities Bridge > Settings
4. Verify settings page loads without errors

### 3. WordPress Debug Mode

Enable debug logging to catch any issues:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Monitor: `wp-content/debug.log`

---

## Test Suite

### Test 1: OAuth Client Credentials Generation

**Objective:** Verify OAuth client credentials generation works correctly

**Steps:**
1. Go to Abilities Bridge > Settings > MCP Server Setup
2. Click "Generate New Client Credentials"
3. Verify Client ID and Client Secret are displayed
4. Save both credentials immediately (secret shown only once)

**Expected Results:**
- Client ID displayed (format: `mcp_xxxxxxxxxxxx`)
- Client Secret displayed (64-character string)
- Credentials stored in database (secret hashed)

### Test 2: MCP Endpoint Accessibility

**Test 2.1: Unauthenticated Request**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'
```

**Expected Result:**
```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32600,
    "message": "Authentication required"
  }
}
```

**Test 2.2: Get Access Token**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "client_credentials",
    "client_id": "YOUR_CLIENT_ID",
    "client_secret": "YOUR_CLIENT_SECRET"
  }'
```

**Expected Result:**
```json
{
  "access_token": "xxx",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "xxx"
}
```

**Test 2.3: Authenticated Ping**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ACCESS_TOKEN_HERE" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'
```

**Expected Result:**
```json
{
  "jsonrpc": "2.0",
  "result": {"status": "ok"},
  "id": 1
}
```

### Test 3: Tools List

**Objective:** Verify tools are listed correctly

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ACCESS_TOKEN_HERE" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'
```

**Expected Result:**
Should return available tools:
- `memory` (if enabled) - Persistent storage

Plus any registered WordPress Abilities that are enabled.

### Test 4: Memory Tool (If Enabled)

**Prerequisite:** Enable memory tool in Settings and provide consent.

**Test 4.1: Create memory entry**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ACCESS_TOKEN_HERE" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "memory",
      "arguments": {
        "command": "create",
        "path": "/memories/test-notes.md",
        "file_text": "# Test Notes\n\nThis is a test."
      }
    },
    "id": 1
  }'
```

**Expected Result:**
- Success: true
- Entry created in database

**Test 4.2: View memory entry**

```bash
# Same structure but command: "view", path: "/memories/test-notes.md"
```

**Expected Result:**
- Returns file contents
- Success: true

**Test 4.3: Memory disabled**

1. Disable memory in settings
2. Try to create entry

**Expected Result:**
- Success: false
- Error: "Memory tool is disabled"

### Test 5: Claude Desktop Integration

**Test 5.1: Configure Claude Desktop**

1. Generate client credentials in WordPress
2. In Claude Desktop: Settings > Connectors > Add custom connector
3. Enter:
   - Name: WordPress
   - MCP Endpoint URL: `https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp`
   - Client ID: Your client ID
   - Client Secret: Your client secret
4. Restart Claude Desktop completely

**Test 5.2: Verify tools appear**

1. Open Claude Desktop
2. Start new conversation
3. Look for hammer icon
4. Click hammer icon

**Expected Result:**
- Hammer icon visible
- Available tools listed

### Test 6: Error Handling

**Test 6.1: Invalid JSON-RPC**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ACCESS_TOKEN_HERE" \
  -d '{"invalid": "request"}'
```

**Expected Result:**
- JSON-RPC error response
- Error code: -32600

**Test 6.2: Unknown tool**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ACCESS_TOKEN_HERE" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "nonexistent_tool",
      "arguments": {}
    },
    "id": 1
  }'
```

**Expected Result:**
- Error message about unknown tool

**Test 6.3: Expired token**

1. Wait for token to expire (or manually set expiration to past)
2. Try to use token

**Expected Result:**
- Error: "OAuth token has expired"
- Status: 401

### Test 7: Security

**Test 7.1: HTTPS enforcement**

OAuth authentication requires HTTPS. HTTP requests should fail.

**Test 7.2: Invalid Bearer token**

```bash
curl -X POST "https://yoursite.com/wp-json/abilities-bridge-mcp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer INVALID_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'
```

**Expected Result:**
- Error: "Invalid OAuth token"
- Status: 401

---

## Post-Testing Verification

### Checklist

- [ ] All tests passed
- [ ] No errors in wp-content/debug.log
- [ ] No PHP warnings or notices
- [ ] Claude Desktop integration working
- [ ] Error handling correct
- [ ] Security measures effective

---

## Troubleshooting

### Tools Not Showing Up

1. Completely quit and restart Claude Desktop
2. Verify config/credentials are correct
3. Ensure HTTPS is enabled
4. Check Claude Desktop logs

### Authentication Errors

1. Verify HTTPS is enabled
2. Check credentials haven't been revoked
3. Regenerate credentials
4. Ensure WordPress user has `manage_options` capability

### Debug Logging

Enable WordPress debug logging:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `wp-content/debug.log` for errors.
