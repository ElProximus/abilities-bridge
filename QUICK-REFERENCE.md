# Quick Reference Guide - Abilities Bridge Classes

## Conversation Classes

### Core Conversation Management
```php
// Main class - use this for most operations
$conversation = new Abilities_Bridge_Conversation($conversation_id);
$conversation->create('New Chat', 'claude-sonnet-4-6');
$conversation->add_user_message($message);
$conversation->send_message($message, $plan_mode);
$result = $conversation->compact_conversation();
```

### Token Calculation
```php
// Calculate tokens for any message array
$tokens = Abilities_Bridge_Token_Calculator::calculate_token_usage(
    $messages,
    $tools,
    $model
);

// Estimate tokens for text
$count = Abilities_Bridge_Token_Calculator::estimate_tokens($text);

// Get model limits
$limits = Abilities_Bridge_Token_Calculator::get_model_limits('claude-sonnet-4-6');
```

### Message Validation
```php
// Fix empty tool inputs
$fixed = Abilities_Bridge_Message_Validator::fix_empty_tool_inputs($content);

// Validate and repair conversation
Abilities_Bridge_Message_Validator::validate_and_repair_conversation($messages, $conversation_id);

// Validate tool results
$result = Abilities_Bridge_Message_Validator::validate_tool_results($messages, $conversation_id);

// Repair all conversations
$stats = Abilities_Bridge_Message_Validator::repair_all_conversations();
```

### Conversation Compaction
```php
// Compact a conversation
$result = Abilities_Bridge_Conversation_Compactor::compact($conversation);
```

---

## OAuth Classes

### Client Management
```php
// Generate credentials
$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials($user_id);
// Returns: array('client_id' => 'mcp_xxx', 'client_secret' => 'xxx')

// Get client
$client = Abilities_Bridge_OAuth_Client_Manager::get_client($client_id);

// Verify credentials
$client = Abilities_Bridge_OAuth_Client_Manager::verify_client_credentials($client_id, $client_secret);

// Revoke client
$success = Abilities_Bridge_OAuth_Client_Manager::revoke_credentials($client_id);

// Get user's clients
$clients = Abilities_Bridge_OAuth_Client_Manager::get_user_clients($user_id);

// Cleanup expired tokens
Abilities_Bridge_OAuth_Client_Manager::cleanup_expired_tokens();

// Migrate tokens to encrypted
$stats = Abilities_Bridge_OAuth_Client_Manager::migrate_tokens_to_encrypted();
```

### Token Validation
```php
// Validate OAuth token
$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token($token);

// Check permission
$allowed = Abilities_Bridge_OAuth_Token_Validator::check_permission($request);
```

### Discovery Endpoints
```php
// OAuth metadata (RFC 8414)
$metadata = Abilities_Bridge_OAuth_Discovery_Handler::handle_metadata_request();

// MCP discovery
$discovery = Abilities_Bridge_OAuth_Discovery_Handler::handle_mcp_discovery();

// Add CORS headers
Abilities_Bridge_OAuth_Discovery_Handler::add_cors_headers();
```

### Router (Initialization)
```php
// Initialize all OAuth routes and hooks
Abilities_Bridge_OAuth_Router::init();
```

---

## Token Encryption

### Encrypt/Decrypt
```php
// Encrypt a token
$encrypted = Abilities_Bridge_Token_Encryption::encrypt($plaintext_token);
// Returns: "v1:base64_encrypted_data"

// Decrypt a token
$decrypted = Abilities_Bridge_Token_Encryption::decrypt($encrypted_token);
// Handles both encrypted and plaintext tokens automatically

// Check if encrypted
$is_encrypted = Abilities_Bridge_Token_Encryption::is_encrypted($token);
```

### Migration
```php
// Migrate existing plaintext tokens to encrypted
$stats = Abilities_Bridge_Token_Encryption::migrate_existing_tokens();
// Returns: array(
//     'access_tokens_migrated' => 5,
//     'refresh_tokens_migrated' => 5,
//     'access_tokens_skipped' => 0,
//     'refresh_tokens_skipped' => 0,
//     'errors' => array()
// )
```

### Testing
```php
// Run self-tests
$results = Abilities_Bridge_Token_Encryption::test_encryption();
// Returns: array(
//     'success' => true,
//     'tests' => array(
//         'basic_encryption' => array('passed' => true, ...),
//         'unique_iv' => array('passed' => true, ...),
//         ...
//     )
// )
```

---

## Common Patterns

### Creating and Using a Conversation
```php
// Create new conversation
$conversation = new Abilities_Bridge_Conversation();
$conversation->create('Technical Support', 'claude-sonnet-4-6');

// Send message
$result = $conversation->send_message('How do I fix this error?');
if ($result['success']) {
    echo $result['response'];
}

// Check tokens
$tokens = $conversation->calculate_token_usage();
echo "Used {$tokens['total']} tokens ({$tokens['percentage']}%)";

// Compact if needed
if ($tokens['percentage'] > 80) {
    $conversation->compact_conversation();
}
```

### OAuth Token Flow
```php
// Generate client credentials
$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials();

// Client exchanges code for token (handled by OAuth_Token_Handler)
// Token is automatically encrypted before storage

// Validate incoming request
$valid = Abilities_Bridge_OAuth_Token_Validator::check_permission($request);
if (is_wp_error($valid)) {
    // Handle error
}

// Token is automatically decrypted during validation
```

### Manual Token Encryption/Decryption
```php
// Generate token
$token = wp_generate_password(64, true, true);

// Encrypt before storing
$encrypted = Abilities_Bridge_Token_Encryption::encrypt($token);
update_option('my_token', $encrypted);

// Later: decrypt when needed
$encrypted = get_option('my_token');
$decrypted = Abilities_Bridge_Token_Encryption::decrypt($encrypted);

if (!is_wp_error($decrypted)) {
    // Use token
}
```

---

## Error Handling

### Encryption Errors
```php
$result = Abilities_Bridge_Token_Encryption::encrypt($token);
if (is_wp_error($result)) {
    echo $result->get_error_message();
    // Fallback to plaintext or handle error
}
```

### Validation Errors
```php
$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token($token);
if (is_wp_error($result)) {
    // Error codes: 'token_expired', 'invalid_token', etc.
    $code = $result->get_error_code();
    $message = $result->get_error_message();
}
```

---

## Class Type Reference

| Class | Type | When to Use |
|-------|------|-------------|
| `Token_Calculator` | Static | Token counting utilities |
| `Message_Validator` | Static | Message validation/repair |
| `Conversation_Compactor` | Static | Summarization |
| `Message_Processor` | Instance | Usually via Conversation |
| `Conversation` | Instance | Main conversation operations |
| `OAuth_Router` | Static | Initialization only |
| `OAuth_Redirect_Handler` | Static | Internal use |
| `OAuth_Discovery_Handler` | Static | Discovery endpoints |
| `OAuth_Client_Manager` | Static | Client management |
| `OAuth_Token_Validator` | Static | Token validation |
| `OAuth_Token_Handler` | Static | Internal use (endpoints) |
| `OAuth_Authorization_Handler` | Static | Internal use (endpoints) |
| `Token_Encryption` | Static | Encryption utilities |

---

## File Locations

```
includes/
├── class-conversation.php
├── class-token-calculator.php
├── class-message-validator.php
├── class-conversation-compactor.php
├── class-message-processor.php
├── class-mcp-oauth.php
├── class-oauth-router.php
├── class-oauth-redirect-handler.php
├── class-oauth-discovery-handler.php
├── class-oauth-client-manager.php
├── class-oauth-token-validator.php
├── class-oauth-token-handler.php
├── class-oauth-authorization-handler.php
└── class-token-encryption.php
```

All classes are autoloaded by WordPress. No manual `require` needed.
