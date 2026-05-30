<?php
/**
 * Tests for chat image attachment handling.
 *
 * @package Abilities_Bridge
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-attachments.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-conversation.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-database.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-openai-api.php';

if ( ! class_exists( 'Abilities_Bridge_Test_WPDB' ) ) {
	/**
	 * Minimal wpdb test double for conversation deletion tests.
	 */
	class Abilities_Bridge_Test_WPDB {
		/**
		 * WordPress table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Captured delete calls.
		 *
		 * @var array
		 */
		public $deleted = array();

		/**
		 * Captured update calls.
		 *
		 * @var array
		 */
		public $updated = array();

		/**
		 * Prepare query.
		 *
		 * @param string $query Query.
		 * @param mixed  ...$args Args.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $query;
		}

		/**
		 * Return a fake conversation row.
		 *
		 * @param string $query Query.
		 * @return object
		 */
		public function get_row( $query ) {
			return (object) array( 'id' => 321 );
		}

		/**
		 * Capture an update.
		 *
		 * @param string $table Table.
		 * @param array  $data Data.
		 * @param array  $where Where.
		 * @return int
		 */
		public function update( $table, $data, $where, ...$args ) {
			$this->updated[] = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);
			return 1;
		}

		/**
		 * Capture a delete.
		 *
		 * @param string $table Table.
		 * @param array  $where Where.
		 * @return int
		 */
		public function delete( $table, $where, ...$args ) {
			$this->deleted[] = array(
				'table' => $table,
				'where' => $where,
			);
			return 1;
		}
	}
}

/**
 * Attachment tests.
 */
final class AttachmentsTest extends TestCase {

	/**
	 * Option values returned by get_option().
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Set up test stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->stub_wp_functions();
		$this->delete_test_storage();
	}

	/**
	 * Tear down test state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->delete_test_storage();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Image-only content creates blocks and a useful title.
	 *
	 * @return void
	 */
	public function test_image_only_content_and_title(): void {
		$attachments = array(
			array(
				'source' => 'screenshot',
			),
		);

		$content = Abilities_Bridge_Attachments::build_user_content( '', array( $this->image_block() ) );

		$this->assertIsArray( $content );
		$this->assertSame( 'image', $content[0]['type'] );
		$this->assertSame( 'Screenshot', Abilities_Bridge_Attachments::derive_title( '', $attachments ) );
	}

	/**
	 * Text is extracted from multimodal content for summaries.
	 *
	 * @return void
	 */
	public function test_summary_extracts_text_from_multimodal_content(): void {
		$conversation = ( new ReflectionClass( Abilities_Bridge_Conversation::class ) )->newInstanceWithoutConstructor();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => array(
					$this->image_block(),
					array(
						'type' => 'text',
						'text' => 'Please review this screenshot.',
					),
				),
			),
		);

		$messages_property = new ReflectionProperty( Abilities_Bridge_Conversation::class, 'messages' );
		$messages_property->setAccessible( true );
		$messages_property->setValue( $conversation, $messages );

		$this->assertSame( 'Please review this screenshot.', $conversation->get_summary() );
	}

	/**
	 * Attachment validation accepts normalized raster data URLs.
	 *
	 * @return void
	 */
	public function test_parse_incoming_attachments_accepts_png_data_url(): void {
		$attachments = Abilities_Bridge_Attachments::parse_incoming_attachments(
			wp_json_encode(
				array(
					array(
						'data_url' => $this->png_data_url(),
						'source'   => 'upload',
						'name'     => 'tiny.png',
					),
				)
			)
		);

		$this->assertIsArray( $attachments );
		$this->assertCount( 1, $attachments );
		$this->assertSame( 'image/png', $attachments[0]['mime_type'] );
		$this->assertSame( 1, $attachments[0]['width'] );
		$this->assertSame( 1, $attachments[0]['height'] );
	}

	/**
	 * The image attachment feature defaults to enabled and follows the saved option.
	 *
	 * @return void
	 */
	public function test_is_enabled_defaults_true_and_follows_option(): void {
		$this->assertTrue( Abilities_Bridge_Attachments::is_enabled() );

		$this->options['abilities_bridge_enable_image_attachments'] = false;
		$this->assertFalse( Abilities_Bridge_Attachments::is_enabled() );

		$this->options['abilities_bridge_enable_image_attachments'] = true;
		$this->assertTrue( Abilities_Bridge_Attachments::is_enabled() );
	}

	/**
	 * Client config exposes the shared enabled flag.
	 *
	 * @return void
	 */
	public function test_get_config_exposes_enabled_flag(): void {
		$this->options['abilities_bridge_enable_image_attachments'] = false;

		$config = Abilities_Bridge_Attachments::get_config();

		$this->assertArrayHasKey( 'attachments_enabled', $config );
		$this->assertFalse( $config['attachments_enabled'] );
	}

	/**
	 * Disabled image attachments are ignored instead of stored or rejected.
	 *
	 * @return void
	 */
	public function test_parse_incoming_attachments_drops_payload_when_disabled(): void {
		$this->options['abilities_bridge_enable_image_attachments'] = false;

		$attachments = Abilities_Bridge_Attachments::parse_incoming_attachments(
			wp_json_encode(
				array(
					array(
						'data_url' => $this->png_data_url(),
						'source'   => 'upload',
						'name'     => 'tiny.png',
					),
				)
			)
		);

		$this->assertSame( array(), $attachments );
	}

	/**
	 * Attachment validation rejects unsupported data.
	 *
	 * @return void
	 */
	public function test_parse_incoming_attachments_rejects_unsupported_mime(): void {
		$result = Abilities_Bridge_Attachments::parse_incoming_attachments(
			wp_json_encode(
				array(
					array(
						'data_url' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
						'source'   => 'upload',
					),
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Current-turn images hydrate while older turns become placeholders.
	 *
	 * @return void
	 */
	public function test_prepare_content_for_api_hydrates_only_requested_turn(): void {
		$dir = ABILITIES_BRIDGE_CONTENT_DIR . 'attachments/123';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/abimg_test.png', base64_decode( $this->png_base64() ) );

		$block = $this->image_block();

		$hydrated = Abilities_Bridge_Attachments::prepare_content_for_api( array( $block ), 123, true );
		$old      = Abilities_Bridge_Attachments::prepare_content_for_api( array( $block ), 123, false );

		$this->assertSame( 'image', $hydrated[0]['type'] );
		$this->assertSame( 'base64', $hydrated[0]['source']['type'] );
		$this->assertSame( 'text', $old[0]['type'] );
		$this->assertStringContainsString( 'Screenshot', $old[0]['text'] );
	}

	/**
	 * Conversation attachment cleanup removes files and empty direct child directories.
	 *
	 * @return void
	 */
	public function test_delete_conversation_attachments_removes_files_and_empty_child_dirs(): void {
		$dir = ABILITIES_BRIDGE_CONTENT_DIR . 'attachments/456';
		mkdir( $dir . '/empty-child', 0777, true );
		file_put_contents( $dir . '/abimg_delete.png', 'image-data' );

		$this->assertTrue( Abilities_Bridge_Attachments::delete_conversation_attachments( 456 ) );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	/**
	 * Missing conversation attachment directories are no-op successes.
	 *
	 * @return void
	 */
	public function test_delete_conversation_attachments_missing_directory_is_noop(): void {
		$this->assertTrue( Abilities_Bridge_Attachments::delete_conversation_attachments( 999 ) );
	}

	/**
	 * Guarded deletion refuses paths outside the attachment base.
	 *
	 * @return void
	 */
	public function test_guarded_delete_refuses_outside_attachment_base(): void {
		$base = ABILITIES_BRIDGE_CONTENT_DIR . 'attachments';
		mkdir( $base, 0777, true );

		$outside = sys_get_temp_dir() . '/abilities-bridge-outside-delete-test';
		if ( ! is_dir( $outside ) ) {
			mkdir( $outside, 0777, true );
		}
		file_put_contents( $outside . '/keep.txt', 'keep' );

		$method = new ReflectionMethod( Abilities_Bridge_Attachments::class, 'delete_flat_directory' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, $outside, true ) );
		$this->assertFileExists( $outside . '/keep.txt' );

		unlink( $outside . '/keep.txt' );
		rmdir( $outside );
	}

	/**
	 * All-attachment cleanup removes dotfiles and the attachment root.
	 *
	 * @return void
	 */
	public function test_delete_all_attachments_removes_dotfiles_and_root(): void {
		$base = ABILITIES_BRIDGE_CONTENT_DIR . 'attachments';
		mkdir( $base . '/789', 0777, true );
		file_put_contents( $base . '/.htaccess', 'Deny from all' );
		file_put_contents( $base . '/789/abimg_delete.png', 'image-data' );

		$this->assertTrue( Abilities_Bridge_Attachments::delete_all_attachments() );
		$this->assertDirectoryDoesNotExist( $base );
	}

	/**
	 * Soft delete keeps attachments, permanent delete removes them.
	 *
	 * @return void
	 */
	public function test_soft_delete_retains_and_permanent_delete_removes_attachments(): void {
		$dir = ABILITIES_BRIDGE_CONTENT_DIR . 'attachments/321';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/abimg_delete.png', 'image-data' );

		$previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new Abilities_Bridge_Test_WPDB();

		try {
			$this->assertTrue( Abilities_Bridge_Database::delete_conversation( 321, 12 ) );
			$this->assertDirectoryExists( $dir );

			$this->assertTrue( Abilities_Bridge_Database::permanently_delete_conversation( 321, 12 ) );
			$this->assertDirectoryDoesNotExist( $dir );
			$this->assertCount( 3, $GLOBALS['wpdb']->deleted );
		} finally {
			if ( null === $previous_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $previous_wpdb;
			}
		}
	}

	/**
	 * OpenAI conversion maps hydrated image blocks to Responses input_image content.
	 *
	 * @return void
	 */
	public function test_openai_converter_maps_hydrated_images(): void {
		$client = ( new ReflectionClass( Abilities_Bridge_OpenAI_API::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Abilities_Bridge_OpenAI_API::class, 'convert_messages_to_responses_input' );
		$method->setAccessible( true );

		$input = $method->invoke(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => 'image/png',
								'data'       => $this->png_base64(),
							),
						),
						array(
							'type' => 'text',
							'text' => 'What is shown?',
						),
					),
				),
			),
			false
		);

		$this->assertSame( 'user', $input[0]['role'] );
		$this->assertSame( 'input_image', $input[0]['content'][0]['type'] );
		$this->assertStringStartsWith( 'data:image/png;base64,', $input[0]['content'][0]['image_url'] );
		$this->assertSame( 'input_text', $input[0]['content'][1]['type'] );
	}

	/**
	 * Build a stored image block fixture.
	 *
	 * @return array
	 */
	private function image_block(): array {
		return array(
			'type'          => 'image',
			'attachment_id' => 'abimg_test',
			'mime_type'     => 'image/png',
			'width'         => 1,
			'height'        => 1,
			'size'          => 68,
			'source'        => 'screenshot',
			'name'          => 'screenshot.png',
			'path'          => '123/abimg_test.png',
			'replay'        => 'current_turn',
		);
	}

	/**
	 * 1x1 PNG data URL.
	 *
	 * @return string
	 */
	private function png_data_url(): string {
		return 'data:image/png;base64,' . $this->png_base64();
	}

	/**
	 * 1x1 PNG base64.
	 *
	 * @return string
	 */
	private function png_base64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
	}

	/**
	 * Remove temp attachment storage between tests.
	 *
	 * @return void
	 */
	private function delete_test_storage(): void {
		$base = ABILITIES_BRIDGE_CONTENT_DIR;
		if ( ! file_exists( $base ) ) {
			return;
		}

		$this->delete_tree( $base );
	}

	/**
	 * Test-only recursive delete for temp fixtures.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function delete_tree( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->delete_tree( rtrim( $path, '/\\' ) . '/' . $entry );
			}
		}

		rmdir( $path );
	}

	/**
	 * Stub WordPress functions used by attachment helpers.
	 *
	 * @return void
	 */
	private function stub_wp_functions(): void {
		Functions\when( '__' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $value ) {
				return json_encode( $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $value ) {
				return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value );
			}
		);
		Functions\when( 'size_format' )->alias(
			function ( $bytes ) {
				return round( $bytes / 1048576 ) . ' MB';
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			function ( $path ) {
				return rtrim( $path, '/\\' ) . '/';
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $value ) {
				return $value instanceof WP_Error;
			}
		);
		Functions\when( 'current_time' )->alias(
			function () {
				return '2026-05-30 12:00:00';
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 12 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
	}
}
