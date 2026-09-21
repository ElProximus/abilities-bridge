<?php
/**
 * Tests for OAuth token lifetimes, sliding refresh expiry, and pruning.
 *
 * @package Abilities_Bridge
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-oauth-client-manager.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-oauth-token-handler.php';

/**
 * Token lifetime tests.
 */
class TokenLifetimeTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return $default_value;
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Default access token lifetime is 30 days.
	 */
	public function test_default_access_token_lifetime_is_30_days() {
		$this->assertSame( 30 * DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
	}

	/**
	 * The settings-page choice drives the lifetime.
	 */
	public function test_hour_choice_gives_one_hour() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_TOKEN_LIFETIME === $name ? 'hour' : $default_value;
			}
		);

		$this->assertSame( 'hour', Abilities_Bridge_OAuth_Token_Handler::get_lifetime_choice() );
		$this->assertSame( HOUR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
		// Refresh lifetime never drops below its 90-day default.
		$this->assertSame( 90 * DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_a' ) );
	}

	/**
	 * A one-year access token stretches the refresh lifetime to match.
	 */
	public function test_year_choice_extends_refresh_lifetime() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_TOKEN_LIFETIME === $name ? 'year' : $default_value;
			}
		);

		$this->assertSame( YEAR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
		$this->assertSame( YEAR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_a' ) );
	}

	/**
	 * The one-day choice gives one day and keeps the 90-day refresh floor.
	 */
	public function test_day_choice_gives_one_day() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_TOKEN_LIFETIME === $name ? 'day' : $default_value;
			}
		);

		$this->assertSame( DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
		$this->assertSame( 90 * DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_a' ) );
	}

	/**
	 * "Never expires" issues a 100-year token and stretches refresh to match.
	 */
	public function test_never_choice_gives_hundred_years() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_TOKEN_LIFETIME === $name ? 'never' : $default_value;
			}
		);

		$this->assertSame( 100 * YEAR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
		$this->assertSame( 100 * YEAR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_a' ) );
		$this->assertLessThan( PHP_INT_MAX, time() + Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
	}

	/**
	 * Choices are offered shortest to longest so the radio list reads naturally.
	 */
	public function test_choices_are_ordered_shortest_to_longest() {
		$seconds = array_column( Abilities_Bridge_OAuth_Token_Handler::get_lifetime_choices(), 'seconds' );
		$sorted  = $seconds;
		sort( $sorted );

		$this->assertSame( array( 'hour', 'day', '30days', 'year', 'never' ), array_keys( Abilities_Bridge_OAuth_Token_Handler::get_lifetime_choices() ) );
		$this->assertSame( $sorted, $seconds );
	}

	/**
	 * An unknown stored value falls back to the default choice.
	 */
	public function test_unknown_choice_falls_back_to_default() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_TOKEN_LIFETIME === $name ? 'bogus' : $default_value;
			}
		);

		$this->assertSame( '30days', Abilities_Bridge_OAuth_Token_Handler::get_lifetime_choice() );
		$this->assertSame( 30 * DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_a' ) );
	}

	/**
	 * Revoking access tokens keeps clients and refresh tokens.
	 */
	public function test_revoke_all_access_tokens_keeps_refresh_tokens() {
		$stored = array(
			'clients'        => array( 'client_a' => array() ),
			'access_tokens'  => array( array( 'access_token' => 'a' ), array( 'access_token' => 'b' ) ),
			'refresh_tokens' => array( array( 'refresh_token' => 'r' ) ),
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) use ( $stored ) {
				return Abilities_Bridge_OAuth_Token_Handler::OPTION_NAME === $name ? $stored : $default_value;
			}
		);
		$saved = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);

		$this->assertSame( 2, Abilities_Bridge_OAuth_Token_Handler::revoke_all_access_tokens() );
		$this->assertSame( array(), $saved['access_tokens'] );
		$this->assertCount( 1, $saved['refresh_tokens'] );
		$this->assertArrayHasKey( 'client_a', $saved['clients'] );
	}

	/**
	 * Default refresh token lifetime is 90 days.
	 */
	public function test_default_refresh_token_lifetime_is_90_days() {
		$this->assertSame( 90 * DAY_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_a' ) );
	}

	/**
	 * The lifetime filter is honored and receives the client id.
	 */
	public function test_access_token_lifetime_filter_is_applied() {
		Monkey\Filters\expectApplied( 'abilities_bridge_access_token_lifetime' )
			->once()
			->with( 30 * DAY_IN_SECONDS, 'client_b', Mockery::type( 'string' ) )
			->andReturn( HOUR_IN_SECONDS );

		$this->assertSame( HOUR_IN_SECONDS, Abilities_Bridge_OAuth_Token_Handler::get_access_token_lifetime( 'client_b' ) );
	}

	/**
	 * A filter cannot push the lifetime below one minute.
	 */
	public function test_lifetime_has_a_floor() {
		Monkey\Filters\expectApplied( 'abilities_bridge_refresh_token_lifetime' )->once()->andReturn( 0 );

		$this->assertSame( 60, Abilities_Bridge_OAuth_Token_Handler::get_refresh_token_lifetime( 'client_c' ) );
	}

	/**
	 * Pruning removes expired access and refresh tokens but keeps live ones
	 * and entries without an expiry.
	 */
	public function test_prune_removes_only_expired_tokens() {
		$now  = time();
		$data = array(
			'clients'        => array( 'client_a' => array() ),
			'access_tokens'  => array(
				array(
					'access_token' => 'old',
					'expires_at'   => $now - 10,
				),
				array(
					'access_token' => 'live',
					'expires_at'   => $now + 1000,
				),
			),
			'refresh_tokens' => array(
				array(
					'refresh_token' => 'old',
					'expires_at'    => $now - 10,
				),
				array( 'refresh_token' => 'no-expiry' ),
			),
		);

		$pruned = Abilities_Bridge_OAuth_Token_Handler::prune_expired_tokens( $data );

		$this->assertSame( array( 'client_a' => array() ), $pruned['clients'] );
		$this->assertCount( 1, $pruned['access_tokens'] );
		$this->assertSame( 'live', $pruned['access_tokens'][0]['access_token'] );
		$this->assertCount( 1, $pruned['refresh_tokens'] );
		$this->assertSame( 'no-expiry', $pruned['refresh_tokens'][0]['refresh_token'] );
	}

	/**
	 * Pruning tolerates missing keys.
	 */
	public function test_prune_handles_missing_keys() {
		$this->assertSame( array(), Abilities_Bridge_OAuth_Token_Handler::prune_expired_tokens( array() ) );
	}
}
