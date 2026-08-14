<?php
/**
 * Regression tests for provider tool naming, schemas, and input handling.
 *
 * Guards the two historical defects: (1) dashed ability names (e.g.
 * core/get-site-info) advertised a tool name the resolver could never match,
 * and (2) no-input abilities were skipped entirely instead of being
 * advertised with an empty object schema and executed with NULL input.
 *
 * @package Abilities_Bridge
 */

require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-ability-permissions.php';

use PHPUnit\Framework\TestCase;

/**
 * Provider tool naming/schema/input regression tests.
 */
final class ProviderToolsTest extends TestCase {

	/**
	 * Dashed ability names must survive the canonical transform: only "/"
	 * maps to "_", hyphens are preserved (legal in provider tool names).
	 */
	public function test_dashed_ability_names_round_trip() {
		$cases = array(
			'core/get-site-info'        => 'ability_core_get-site-info',
			'core/get-environment-info' => 'ability_core_get-environment-info',
			'core/get-user-info'        => 'ability_core_get-user-info',
			'beacon-campaign-sender/get-dashboard' => 'ability_beacon-campaign-sender_get-dashboard',
			'a/b'                       => 'ability_a_b',
		);

		foreach ( $cases as $ability => $expected_tool ) {
			$this->assertSame( $expected_tool, Abilities_Bridge_Ability_Permissions::provider_tool_name( $ability ) );
		}
	}

	/**
	 * The map must resolve every non-colliding ability exactly, and exclude
	 * (never guess) abilities whose lossy transform collides.
	 */
	public function test_provider_tool_map_resolves_and_excludes_collisions() {
		$built = Abilities_Bridge_Ability_Permissions::build_provider_tool_map(
			array(
				array( 'ability_name' => 'core/get-site-info' ),
				array( 'ability_name' => 'core/get-environment-info' ),
				array( 'ability_name' => 'a/b' ),
				array( 'ability_name' => 'a_b' ),
				array( 'ability_name' => '' ),
			)
		);

		$this->assertSame( 'core/get-site-info', $built['map']['ability_core_get-site-info'] );
		$this->assertSame( 'core/get-environment-info', $built['map']['ability_core_get-environment-info'] );
		$this->assertArrayNotHasKey( 'ability_a_b', $built['map'], 'Colliding abilities must be excluded, not guessed.' );
		$this->assertSame( array( 'a/b', 'a_b' ), $built['collisions']['ability_a_b'] );
	}

	/**
	 * A no-input ability must be advertised with an empty OBJECT schema
	 * (providers require type:object and {} rather than []), while abilities
	 * with a real schema keep it byte-for-byte.
	 */
	public function test_provider_input_schema_defaults_and_passthrough() {
		foreach ( array( array(), null, '', false ) as $empty ) {
			$schema = Abilities_Bridge_Ability_Permissions::provider_input_schema( $empty );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertInstanceOf( stdClass::class, $schema['properties'] );
			$this->assertSame( '{"type":"object","properties":{}}', json_encode( $schema ) );
		}

		$real = array(
			'type'       => 'object',
			'properties' => array( 'query' => array( 'type' => 'string' ) ),
			'required'   => array( 'query' ),
		);
		$this->assertSame( $real, Abilities_Bridge_Ability_Permissions::provider_input_schema( $real ) );
	}

	/**
	 * Empty provider input for a no-input ability must normalize to NULL
	 * (what the WordPress Abilities API expects); parameterized abilities
	 * must receive their input unchanged, including deliberately empty
	 * arrays when a schema exists.
	 */
	public function test_normalize_ability_input() {
		$this->assertNull( Abilities_Bridge_Ability_Permissions::normalize_ability_input( array(), array() ) );
		$this->assertNull( Abilities_Bridge_Ability_Permissions::normalize_ability_input( null, array() ) );

		$schema = array(
			'type'       => 'object',
			'properties' => array( 'query' => array( 'type' => 'string' ) ),
		);
		$input  = array( 'query' => 'hello' );

		$this->assertSame( $input, Abilities_Bridge_Ability_Permissions::normalize_ability_input( $input, $schema ) );
		$this->assertSame( array(), Abilities_Bridge_Ability_Permissions::normalize_ability_input( array(), $schema ), 'A schema-bearing ability keeps an empty array untouched.' );
	}
}
