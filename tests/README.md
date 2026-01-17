# Abilities Bridge - Test Suite

Automated testing for the Abilities Bridge WordPress plugin, focusing on OAuth 2.0 security.

## 📊 Test Coverage

- **43 Tests** (37 unit + 5 integration + 1 skipped)
- **112 Assertions**
- **Run Time:** ~600ms
- **Focus:** OAuth security (tokens, encryption, authentication, complete flows)

## 🧪 Test Suites

### Unit Tests (OAuth Security Components)

Tests individual components in isolation with mocked dependencies.

#### 1. Token Encryption Tests (9 tests)
**File:** `tests/unit/oauth/TokenEncryptionTest.php`

Critical security tests for AES-256-CBC token encryption:
- ✅ Encryption/decryption roundtrip
- ✅ Version prefix (v1:) handling
- ✅ Random IV generation (prevents ciphertext reuse)
- ✅ Empty input rejection
- ✅ Corrupted data handling
- ✅ Backward compatibility with plaintext tokens
- ✅ Invalid base64 rejection
- ✅ Too-short data rejection

**Why these tests matter:** A single encryption bug could expose all OAuth tokens.

#### 2. Token Validator Tests (6 tests)
**File:** `tests/unit/oauth/TokenValidatorTest.php`

Tests for the API gatekeeper:
- ✅ Valid unexpired token acceptance
- ✅ Expired token rejection (security critical!)
- ✅ Invalid token rejection
- ✅ Missing expires_at field handling (backward compatibility)
- ✅ Wrong token value rejection (timing-safe comparison)
- ✅ Invalid scope rejection

**Why these tests matter:** The validator is your first line of defense against unauthorized API access.

#### 3. Client Manager Tests (6 tests)
**File:** `tests/unit/oauth/ClientManagerTest.php`

Credential generation and management:
- ✅ Client ID format (mcp_ prefix, 20 chars)
- ✅ Client secret length (32 chars)
- ✅ **CRITICAL:** Secret hashing before storage (NEVER stored plaintext!)
- ✅ created_at timestamp tracking
- ✅ Credential revocation
- ✅ Non-existent credential revocation handling

**Why these tests matter:** These tests ensure client secrets are never stored in plaintext - a critical security requirement.

#### 4. Token Format Tests (11 tests)
**File:** `tests/unit/oauth/TokenFormatTest.php`

Validates token format specifications:
- ✅ Authorization codes: 43 characters (RFC 6749)
- ✅ Access tokens: 64 characters
- ✅ Refresh tokens: 64 characters
- ✅ Client IDs: 20 characters (mcp_ + 16)
- ✅ Client secrets: 32 characters
- ✅ PKCE verifiers: 43-128 characters (RFC 7636)
- ✅ PKCE character set validation

**Why these tests matter:** Ensures compliance with OAuth 2.0 RFCs and prevents format regressions.

#### 5. Token Expiration Tests (4 tests)
**File:** `tests/unit/oauth/TokenExpirationTest.php`

Basic expiration logic:
- ✅ Expired token detection
- ✅ Valid token pass-through
- ✅ Edge case: token expiring exactly now
- ✅ Boundary test: 1 second past expiration

**Why these tests matter:** Foundation for understanding how tests work (great for learning!).

---

### Integration Tests (Complete OAuth Flows)

Tests multiple components working together in realistic scenarios with real encryption and real data flows.

#### 6. OAuth Token Handler Integration Tests (5 tests)
**File:** `tests/integration/oauth/TokenHandlerIntegrationTest.php`

Real-world OAuth 2.0 workflows:
- ✅ **Complete authorization code flow:** Auth code → Token exchange → Encrypted storage → Immediate validation
- ✅ **Token refresh flow:** Access token expires → Refresh with refresh token → New encrypted access token works
- ✅ **Token revocation:** Token generated → Token revoked → Validation fails (access removed)
- ✅ **Multiple concurrent tokens:** Same client on multiple devices → All tokens work independently
- ✅ **Storage encryption verification:** Tokens plaintext to client, encrypted with v1: prefix in database

**Why these tests matter:** Unit tests verify components work correctly in isolation. Integration tests verify the complete system works in real-world scenarios. These tests use REAL encryption, REAL token generation, and REAL validation - ensuring the security fix (no plaintext fallback) works end-to-end.

**Real-world scenarios covered:**
- User authorizes Claude Desktop for first time (authorization code flow)
- User's access token expires after 1 hour and is refreshed (refresh flow)
- User revokes Claude Desktop's access from WordPress admin (revocation)
- User installs Claude Desktop on desktop and mobile (multiple tokens)

**What's different from unit tests:**
- Uses real `Abilities_Bridge_Token_Encryption::encrypt()` (not mocked)
- Tests cross-component interaction (Handler + Encryption + Validator)
- Validates realistic data flows through multiple layers
- Only mocks WordPress-specific functions (`get_option`, `update_option`)

---

## 🚀 Running Tests

### Prerequisites

```bash
# Install dependencies (one-time setup)
php composer.phar install
```

### Run All Tests

```bash
# Standard output
vendor/bin/phpunit

# Human-readable output
vendor/bin/phpunit --testdox

# With code coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Run Specific Test Suite

```bash
# Run only unit tests
vendor/bin/phpunit --testsuite unit

# Run only integration tests
vendor/bin/phpunit --testsuite integration

# Run specific component tests
vendor/bin/phpunit --filter TokenEncryption
vendor/bin/phpunit --filter TokenValidator
vendor/bin/phpunit tests/integration/oauth/

# Just one specific test
vendor/bin/phpunit --filter test_encrypt_decrypt_roundtrip
```

### Run Tests by Group

```bash
# Run only security-critical tests
vendor/bin/phpunit --group security

# Run only fast tests
vendor/bin/phpunit --group fast
```

---

## 📁 Directory Structure

```
tests/
├── bootstrap.php           # Test environment setup
├── unit/                   # Unit tests
│   └── oauth/              # OAuth-specific tests
│       ├── ClientManagerTest.php
│       ├── TokenEncryptionTest.php
│       ├── TokenExpirationTest.php
│       ├── TokenFormatTest.php
│       └── TokenValidatorTest.php
└── README.md               # This file
```

---

## 🎓 Understanding the Tests

### Test Anatomy

Every test follows the **Arrange-Act-Assert** pattern:

```php
public function test_example() {
    // Arrange: Set up test data
    $token = 'test_token_123';

    // Act: Perform the action
    $result = SomeClass::do_something( $token );

    // Assert: Verify the result
    $this->assertTrue( $result );
}
```

### Common Assertions

| Assertion | Purpose | Example |
|-----------|---------|---------|
| `assertTrue()` | Value is true | `$this->assertTrue($is_valid)` |
| `assertFalse()` | Value is false | `$this->assertFalse($is_expired)` |
| `assertSame()` | Exact match (===) | `$this->assertSame(43, strlen($code))` |
| `assertInstanceOf()` | Object type check | `$this->assertInstanceOf('WP_Error', $result)` |
| `assertStringStartsWith()` | String prefix | `$this->assertStringStartsWith('mcp_', $id)` |

### Mocking WordPress Functions

We use **Brain Monkey** to mock WordPress functions:

```php
// Mock a simple function
Functions\when('__')->returnArg();

// Mock with expectations
Functions\expect('get_option')
    ->once()
    ->with('option_name', array())
    ->andReturn($test_data);
```

---

## 🔧 Troubleshooting

### Tests Not Found

**Problem:** `No tests executed!`

**Solution:** Ensure test files end with `Test.php` (not start with `Test_`):
```bash
# Good
TokenEncryptionTest.php

# Bad
Test_Token_Encryption.php
```

### Mocking Errors

**Problem:** `'ClassName::method' is not a valid function name`

**Solution:** Brain Monkey can't mock static class methods. Either:
1. Load the actual class (if it's simple)
2. Use Mockery for static methods
3. Refactor code to use dependency injection

### Memory Errors

**Problem:** `Allowed memory size exhausted`

**Solution:** Increase PHP memory limit in `phpunit.xml`:
```xml
<php>
    <ini name="memory_limit" value="512M"/>
</php>
```

---

## 📈 Test Metrics

### Current Status

```
✅ 36 tests passing
✅ 58 assertions
✅ 0 failures
✅ 0 errors
⚡ ~300ms execution time
```

### Coverage Goals

- **Current:** OAuth security components fully tested
- **Target (3 months):** 60% overall code coverage
- **Target (6 months):** 70%+ code coverage

---

## ➕ Adding New Tests

### 1. Create Test File

```bash
# Create new test file
touch tests/unit/oauth/MyNewTest.php
```

### 2. Use Template

```php
<?php
namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class MyNewTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_something() {
        // Your test here
        $this->assertTrue(true);
    }
}
```

### 3. Run Your New Test

```bash
vendor/bin/phpunit --filter MyNew
```

---

## 🎯 Next Steps

### Short Term
- Add integration tests for OAuth flow
- Test MCP endpoint handlers
- JavaScript tests for admin interface

### Long Term
- CI/CD with GitHub Actions
- Code coverage reporting
- Performance benchmarking
- End-to-end tests with Playwright

---

## 📚 Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Brain Monkey](https://brain-wp.github.io/BrainMonkey/)
- [WordPress Plugin Testing](https://make.wordpress.org/cli/handbook/plugin-unit-tests/)
- [OAuth 2.0 RFC 6749](https://tools.ietf.org/html/rfc6749)

---

## 🤝 Contributing

When adding new features:

1. **Write tests first** (Test-Driven Development)
2. **Run tests** before committing: `vendor/bin/phpunit`
3. **Ensure all tests pass** - never commit failing tests
4. **Add test documentation** if introducing new test patterns

---

## ✨ Success!

You now have a solid foundation of automated tests protecting your OAuth implementation. Every time you run `vendor/bin/phpunit`, you're verifying that 36 critical security scenarios work correctly.

**Remember:** Tests are living documentation. When you fix a bug, add a test that would have caught it!
