<?php
/**
 * Chat image attachment handling.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates, stores, displays, and hydrates private chat image attachments.
 */
class Abilities_Bridge_Attachments {

	const MAX_IMAGES    = 3;
	const MAX_BYTES     = 4194304; // 4MB after client-side normalization.
	const MAX_LONG_EDGE = 1568;

	/**
	 * Allowed MIME types mapped to file extensions.
	 *
	 * @var array
	 */
	private static $allowed_mimes = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * Return the shared client/server attachment config.
	 *
	 * @return array
	 */
	public static function get_config() {
		return array(
			'attachments_enabled' => self::is_enabled(),
			'max_images'          => self::MAX_IMAGES,
			'max_bytes'           => self::MAX_BYTES,
			'max_long_edge'       => self::MAX_LONG_EDGE,
			'allowed_mimes'       => array_keys( self::$allowed_mimes ),
			'accept'              => implode( ',', array_keys( self::$allowed_mimes ) ),
			'max_bytes_label'     => size_format( self::MAX_BYTES ),
		);
	}

	/**
	 * Whether new chat image attachments are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'abilities_bridge_enable_image_attachments', true );
	}

	/**
	 * Ensure the attachment directory exists and is not browseable.
	 *
	 * @return bool
	 */
	public static function ensure_storage_dir() {
		$dir = self::base_dir();

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Parse and validate incoming data-URL attachments before a conversation is saved.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return array|WP_Error
	 */
	public static function parse_incoming_attachments( $raw ) {
		if ( ! self::is_enabled() ) {
			return array();
		}

		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			// Browser handlers remove WordPress request slashes exactly once before
			// calling this parser. A second unslash corrupts JSON escape sequences
			// in filenames containing quotes or literal backslashes.
			$decoded = json_decode( $raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'invalid_attachments', __( 'Image attachment data is invalid.', 'abilities-bridge' ) );
			}
		} else {
			$decoded = $raw;
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_attachments', __( 'Image attachment data is invalid.', 'abilities-bridge' ) );
		}

		if ( count( $decoded ) > self::MAX_IMAGES ) {
			return new WP_Error(
				'too_many_attachments',
				sprintf(
					/* translators: %d: maximum image count */
					__( 'You can attach up to %d images per message.', 'abilities-bridge' ),
					self::MAX_IMAGES
				)
			);
		}

		$attachments = array();

		foreach ( $decoded as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_attachment', __( 'One of the image attachments is invalid.', 'abilities-bridge' ) );
			}

			$data_url = isset( $item['data_url'] ) ? trim( (string) $item['data_url'] ) : '';
			if ( ! preg_match( '#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $data_url, $matches ) ) {
				return new WP_Error( 'invalid_attachment_data', __( 'Only JPEG, PNG, and WebP image attachments are supported.', 'abilities-bridge' ) );
			}

			$declared_mime = strtolower( $matches[1] );
			$binary        = base64_decode( preg_replace( '/\s+/', '', $matches[2] ), true );
			if ( false === $binary ) {
				return new WP_Error( 'invalid_attachment_base64', __( 'One of the image attachments could not be decoded.', 'abilities-bridge' ) );
			}

			$size = strlen( $binary );
			if ( $size <= 0 || $size > self::MAX_BYTES ) {
				return new WP_Error(
					'attachment_too_large',
					sprintf(
						/* translators: %s: maximum file size label */
						__( 'Each image must be %s or smaller after normalization.', 'abilities-bridge' ),
						size_format( self::MAX_BYTES )
					)
				);
			}

			$image_info = getimagesizefromstring( $binary );
			if ( false === $image_info || empty( $image_info['mime'] ) ) {
				return new WP_Error( 'invalid_attachment_image', __( 'One of the image attachments is not a readable image.', 'abilities-bridge' ) );
			}

			$detected_mime = strtolower( $image_info['mime'] );
			if ( ! isset( self::$allowed_mimes[ $detected_mime ] ) || $declared_mime !== $detected_mime ) {
				return new WP_Error( 'invalid_attachment_mime', __( 'One of the image attachments has an unsupported image type.', 'abilities-bridge' ) );
			}

			$width  = isset( $image_info[0] ) ? (int) $image_info[0] : 0;
			$height = isset( $image_info[1] ) ? (int) $image_info[1] : 0;
			if ( $width <= 0 || $height <= 0 || max( $width, $height ) > self::MAX_LONG_EDGE ) {
				return new WP_Error(
					'attachment_dimensions_too_large',
					sprintf(
						/* translators: %d: maximum long edge in pixels */
						__( 'Images must be normalized to a maximum long edge of %d pixels.', 'abilities-bridge' ),
						self::MAX_LONG_EDGE
					)
				);
			}

			$source = isset( $item['source'] ) ? sanitize_key( $item['source'] ) : 'upload';
			if ( ! in_array( $source, array( 'upload', 'screenshot' ), true ) ) {
				$source = 'upload';
			}

			$attachments[] = array(
				'binary'    => $binary,
				'mime_type' => $detected_mime,
				'width'     => $width,
				'height'    => $height,
				'size'      => $size,
				'source'    => $source,
				'name'      => isset( $item['name'] ) ? sanitize_file_name( (string) $item['name'] ) : 'image-' . ( $index + 1 ),
			);
		}

		return $attachments;
	}

	/**
	 * Store validated image attachments and return metadata blocks.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $attachments Validated attachments from parse_incoming_attachments().
	 * @return array|WP_Error
	 */
	public static function store_attachments( $conversation_id, $attachments ) {
		if ( empty( $attachments ) ) {
			return array();
		}

		if ( ! self::ensure_storage_dir() ) {
			return new WP_Error( 'attachment_storage_unavailable', __( 'Image attachment storage is unavailable.', 'abilities-bridge' ) );
		}

		$conversation_dir = self::conversation_dir( $conversation_id );
		if ( ! file_exists( $conversation_dir ) && ! wp_mkdir_p( $conversation_dir ) ) {
			return new WP_Error( 'attachment_storage_unavailable', __( 'Image attachment storage is unavailable.', 'abilities-bridge' ) );
		}

		$blocks = array();

		foreach ( $attachments as $attachment ) {
			$attachment_id = self::generate_attachment_id();
			$extension     = self::$allowed_mimes[ $attachment['mime_type'] ];
			$relative_path = self::relative_path( $conversation_id, $attachment_id, $extension );
			$absolute_path = self::absolute_path_from_relative( $relative_path );

			$written = file_put_contents( $absolute_path, $attachment['binary'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === $written ) {
				self::delete_attachment_blocks( $conversation_id, $blocks );
				return new WP_Error( 'attachment_write_failed', __( 'Unable to save one of the image attachments.', 'abilities-bridge' ) );
			}

			$blocks[] = array(
				'type'          => 'image',
				'attachment_id' => $attachment_id,
				'mime_type'     => $attachment['mime_type'],
				'width'         => $attachment['width'],
				'height'        => $attachment['height'],
				'size'          => $attachment['size'],
				'source'        => $attachment['source'],
				'name'          => $attachment['name'],
				'path'          => $relative_path,
				'replay'        => 'current_turn',
			);
		}

		return $blocks;
	}

	/**
	 * Delete only the attachment files represented by the supplied blocks.
	 *
	 * Used by enqueue rollback so other turns in the same conversation are
	 * never removed with a failed request.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $blocks Stored attachment blocks.
	 * @return bool True when every requested file was removed.
	 */
	public static function delete_attachment_blocks( $conversation_id, $blocks ) {
		$success = true;

		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) || ( isset( $block['type'] ) && 'image' !== $block['type'] ) ) {
				continue;
			}

			$path = self::absolute_path_from_block( (int) $conversation_id, $block );
			if ( '' === $path ) {
				$success = false;
				continue;
			}

			$success = self::delete_file_entry( $path ) && $success;
		}

		$conversation_dir = self::conversation_dir( $conversation_id );
		if ( file_exists( $conversation_dir ) && is_dir( $conversation_dir ) ) {
			$entries = self::list_directory_entries( $conversation_dir );
			if ( is_array( $entries ) && empty( $entries ) ) {
				$success = self::delete_empty_directory( $conversation_dir ) && $success;
			}
		}

		return $success;
	}

	/**
	 * Build a multimodal user message from text and stored image blocks.
	 *
	 * @param string $text Text message.
	 * @param array  $image_blocks Image metadata blocks.
	 * @return string|array
	 */
	public static function build_user_content( $text, $image_blocks = array() ) {
		$text = trim( (string) $text );

		if ( empty( $image_blocks ) ) {
			return $text;
		}

		$content = array_values( $image_blocks );
		if ( '' !== $text ) {
			$content[] = array(
				'type' => 'text',
				'text' => $text,
			);
		}

		return $content;
	}

	/**
	 * Prepare content blocks for provider API replay.
	 *
	 * @param mixed $content Content.
	 * @param int   $conversation_id Conversation ID.
	 * @param bool  $hydrate_images Whether image bytes should be included.
	 * @return mixed
	 */
	public static function prepare_content_for_api( $content, $conversation_id, $hydrate_images = false ) {
		if ( ! is_array( $content ) ) {
			return $content;
		}

		$prepared            = array();
		$hydrated_image_seen = 0;
		$total_hydrated      = $hydrate_images ? self::count_image_blocks( $content ) : 0;

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}

			if ( 'image' !== $block['type'] ) {
				$prepared[] = $block;
				continue;
			}

			if ( ! $hydrate_images ) {
				$prepared[] = array(
					'type' => 'text',
					'text' => self::image_placeholder_text( $block ),
				);
				continue;
			}

			$image_data = self::read_attachment_data( $conversation_id, $block );
			if ( is_wp_error( $image_data ) ) {
				$prepared[] = array(
					'type' => 'text',
					'text' => self::image_placeholder_text( $block ) . ' (file unavailable)',
				);
				continue;
			}

			++$hydrated_image_seen;

			$image_block = array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $image_data['mime_type'],
					'data'       => $image_data['base64'],
				),
			);

			if ( $hydrated_image_seen === $total_hydrated ) {
				$image_block['cache_control'] = array( 'type' => 'ephemeral' );
			}

			$prepared[] = $image_block;
		}

		return $prepared;
	}

	/**
	 * Format content for UI display.
	 *
	 * @param mixed $content Content.
	 * @param int   $conversation_id Conversation ID.
	 * @return mixed
	 */
	public static function format_content_for_display( $content, $conversation_id ) {
		if ( ! is_array( $content ) ) {
			return $content;
		}

		$display = array();

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}

			if ( 'image' === $block['type'] ) {
				$display[] = array(
					'type'          => 'image',
					'attachment_id' => isset( $block['attachment_id'] ) ? $block['attachment_id'] : '',
					'mime_type'     => isset( $block['mime_type'] ) ? $block['mime_type'] : '',
					'width'         => isset( $block['width'] ) ? (int) $block['width'] : 0,
					'height'        => isset( $block['height'] ) ? (int) $block['height'] : 0,
					'size'          => isset( $block['size'] ) ? (int) $block['size'] : 0,
					'source'        => isset( $block['source'] ) ? $block['source'] : 'upload',
					'name'          => isset( $block['name'] ) ? $block['name'] : '',
					'preview_url'   => self::attachment_url( $conversation_id, $block ),
				);
			} elseif ( 'text' === $block['type'] && isset( $block['text'] ) ) {
				$display[] = array(
					'type' => 'text',
					'text' => $block['text'],
				);
			}
		}

		return $display;
	}

	/**
	 * Extract plain display text from a string or content blocks.
	 *
	 * @param mixed $content Content.
	 * @return string
	 */
	public static function extract_text( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$text_parts = array();
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text = trim( (string) $block['text'] );
				if ( '' !== $text ) {
					$text_parts[] = $text;
				}
			}
		}

		return trim( implode( "\n\n", $text_parts ) );
	}

	/**
	 * Derive a conversation title from text and attachments.
	 *
	 * @param string $text Text message.
	 * @param array  $attachments Parsed or stored attachment metadata.
	 * @return string
	 */
	public static function derive_title( $text, $attachments = array() ) {
		$text = trim( (string) $text );

		if ( '' !== $text ) {
			return substr( $text, 0, 80 ) . ( strlen( $text ) > 80 ? '...' : '' );
		}

		foreach ( $attachments as $attachment ) {
			if ( is_array( $attachment ) && isset( $attachment['source'] ) && 'screenshot' === $attachment['source'] ) {
				return __( 'Screenshot', 'abilities-bridge' );
			}
		}

		return __( 'Image attachment', 'abilities-bridge' );
	}

	/**
	 * Determine whether content includes an image block.
	 *
	 * @param mixed $content Content.
	 * @return bool
	 */
	public static function content_has_images( $content ) {
		if ( ! is_array( $content ) ) {
			return false;
		}

		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'image' === $block['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count image blocks in content.
	 *
	 * @param array $content Content blocks.
	 * @return int
	 */
	private static function count_image_blocks( $content ) {
		$count = 0;
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'image' === $block['type'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Estimate image tokens without looking at base64 payloads.
	 *
	 * @param array       $block Image block.
	 * @param string|null $provider Provider key.
	 * @return int
	 */
	public static function estimate_image_tokens( $block, $provider = null ) {
		$width    = isset( $block['width'] ) ? max( 1, (int) $block['width'] ) : self::MAX_LONG_EDGE;
		$height   = isset( $block['height'] ) ? max( 1, (int) $block['height'] ) : self::MAX_LONG_EDGE;
		$provider = $provider ? $provider : Abilities_Bridge_AI_Provider::get_current_provider();

		if ( class_exists( 'Abilities_Bridge_AI_Provider' ) && Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider ) {
			return 85 + (int) ceil( ( $width * $height ) / 4096 );
		}

		return (int) ceil( ( $width * $height ) / 750 );
	}

	/**
	 * Serve an attachment for an authenticated admin request.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $attachment_id Attachment ID.
	 * @return WP_Error|array
	 */
	public static function get_attachment_for_serving( $conversation_id, $attachment_id ) {
		$block = self::find_attachment_block( $conversation_id, $attachment_id );
		if ( ! $block ) {
			return new WP_Error( 'attachment_not_found', __( 'Image attachment not found.', 'abilities-bridge' ) );
		}

		$path = self::absolute_path_from_block( $conversation_id, $block );
		if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'attachment_missing', __( 'Image attachment file is missing.', 'abilities-bridge' ) );
		}

		return array(
			'path'      => $path,
			'mime_type' => isset( $block['mime_type'] ) ? $block['mime_type'] : 'application/octet-stream',
			'name'      => isset( $block['name'] ) ? $block['name'] : $attachment_id,
		);
	}

	/**
	 * Delete private image files for one conversation.
	 *
	 * This is best-effort cleanup for a flat directory:
	 * attachments/{conversation_id}/{attachment_id}.ext.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool True when every attempted delete succeeds, false on partial failure.
	 */
	public static function delete_conversation_attachments( $conversation_id ) {
		$conversation_id = (int) $conversation_id;
		if ( $conversation_id <= 0 ) {
			return true;
		}

		return self::delete_flat_directory( self::conversation_dir( $conversation_id ), true );
	}

	/**
	 * Delete all private chat image attachments.
	 *
	 * @return bool True when every attempted delete succeeds, false on partial failure.
	 */
	public static function delete_all_attachments() {
		$base_dir = self::base_dir();
		if ( ! file_exists( $base_dir ) && ! is_link( $base_dir ) ) {
			return true;
		}

		$success = true;
		$entries = self::list_directory_entries( $base_dir );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( is_link( $entry ) || is_file( $entry ) ) {
				$success = self::delete_file_entry( $entry ) && $success;
			} elseif ( is_dir( $entry ) ) {
				$success = self::delete_flat_directory( $entry, true ) && $success;
			}
		}

		if ( file_exists( $base_dir ) && ! is_link( $base_dir ) ) {
			$success = self::delete_empty_directory( $base_dir ) && $success;
		}

		return $success;
	}

	/**
	 * Read attachment bytes for provider hydration.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $block Image block.
	 * @return array|WP_Error
	 */
	private static function read_attachment_data( $conversation_id, $block ) {
		$path = self::absolute_path_from_block( $conversation_id, $block );
		if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'attachment_missing', __( 'Image attachment file is missing.', 'abilities-bridge' ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'attachment_read_failed', __( 'Unable to read image attachment.', 'abilities-bridge' ) );
		}

		return array(
			'mime_type' => isset( $block['mime_type'] ) ? $block['mime_type'] : 'image/png',
			'base64'    => base64_encode( $bytes ),
		);
	}

	/**
	 * Find an image block in a conversation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $attachment_id Attachment ID.
	 * @return array|null
	 */
	private static function find_attachment_block( $conversation_id, $attachment_id ) {
		$attachment_id = sanitize_key( $attachment_id );
		$messages      = Abilities_Bridge_Database::get_messages( $conversation_id );

		foreach ( $messages as $message ) {
			$content = json_decode( $message->content, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $content ) ) {
				continue;
			}

			foreach ( $content as $block ) {
				if (
					is_array( $block ) &&
					isset( $block['type'], $block['attachment_id'] ) &&
					'image' === $block['type'] &&
					$attachment_id === $block['attachment_id']
				) {
					return $block;
				}
			}
		}

		return null;
	}

	/**
	 * Build an authenticated attachment URL.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $block Image block.
	 * @return string
	 */
	private static function attachment_url( $conversation_id, $block ) {
		if ( empty( $block['attachment_id'] ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'          => 'abilities_bridge_get_attachment',
				'nonce'           => wp_create_nonce( 'abilities_bridge_nonce' ),
				'conversation_id' => (int) $conversation_id,
				'attachment_id'   => sanitize_key( $block['attachment_id'] ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Convert an image block to a text placeholder.
	 *
	 * @param array $block Image block.
	 * @return string
	 */
	private static function image_placeholder_text( $block ) {
		$source = isset( $block['source'] ) && 'screenshot' === $block['source'] ? __( 'Screenshot', 'abilities-bridge' ) : __( 'Image attachment', 'abilities-bridge' );
		$width  = isset( $block['width'] ) ? (int) $block['width'] : 0;
		$height = isset( $block['height'] ) ? (int) $block['height'] : 0;
		$mime   = isset( $block['mime_type'] ) ? $block['mime_type'] : '';

		$details = array_filter(
			array(
				$width && $height ? $width . 'x' . $height : '',
				$mime,
			)
		);

		return '[' . $source . ( $details ? ': ' . implode( ', ', $details ) : '' ) . ']';
	}

	/**
	 * Resolve a stored image path from a block.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $block Image block.
	 * @return string
	 */
	private static function absolute_path_from_block( $conversation_id, $block ) {
		if ( empty( $block['path'] ) ) {
			if ( empty( $block['attachment_id'] ) || empty( $block['mime_type'] ) || ! isset( self::$allowed_mimes[ $block['mime_type'] ] ) ) {
				return '';
			}
			$relative_path = self::relative_path( $conversation_id, $block['attachment_id'], self::$allowed_mimes[ $block['mime_type'] ] );
		} else {
			$relative_path = (string) $block['path'];
		}

		return self::absolute_path_from_relative( $relative_path );
	}

	/**
	 * Resolve a relative attachment path under the attachment base directory.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	private static function absolute_path_from_relative( $relative_path ) {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$relative_path = preg_replace( '#[^A-Za-z0-9_\-./]#', '', $relative_path );
		$parts         = array();

		foreach ( explode( '/', $relative_path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$parts[] = $part;
		}

		return trailingslashit( self::base_dir() ) . implode( '/', $parts );
	}

	/**
	 * Delete direct children of a directory without following symlinks.
	 *
	 * @param string $directory Directory path.
	 * @param bool   $remove_directory Whether to remove the directory after clearing it.
	 * @return bool True when every attempted delete succeeds, false on partial failure.
	 */
	private static function delete_flat_directory( $directory, $remove_directory = true ) {
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return true;
		}

		if ( is_link( $directory ) || is_file( $directory ) ) {
			return self::delete_file_entry( $directory );
		}

		if ( ! self::is_path_inside_attachment_base( $directory ) ) {
			return false;
		}

		$success = true;
		$entries = self::list_directory_entries( $directory );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( is_link( $entry ) || is_file( $entry ) ) {
				$success = self::delete_file_entry( $entry ) && $success;
			} elseif ( is_dir( $entry ) ) {
				// The attachment tree is expected to be flat. Remove only empty child directories.
				$success = self::delete_empty_directory( $entry ) && $success;
			}
		}

		if ( $remove_directory ) {
			$success = self::delete_empty_directory( $directory ) && $success;
		}

		return $success;
	}

	/**
	 * Delete a file or symlink after guarding the path.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function delete_file_entry( $path ) {
		if ( ! self::is_path_inside_attachment_base( $path ) ) {
			return false;
		}

		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Delete an empty directory after guarding the path.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	private static function delete_empty_directory( $path ) {
		if ( ! self::is_path_inside_attachment_base( $path ) ) {
			return false;
		}

		if ( ! file_exists( $path ) ) {
			return true;
		}

		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return self::delete_file_entry( $path );
		}

		return rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Confirm a path sits inside the resolved attachment base directory.
	 *
	 * Missing paths are treated as no-op safe because the caller has nothing to delete.
	 * Symlinks are guarded by their parent directory so they can be unlinked without
	 * following the link target.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	private static function is_path_inside_attachment_base( $path ) {
		$base_real = realpath( self::base_dir() );
		if ( false === $base_real ) {
			return false;
		}

		if ( is_link( $path ) ) {
			$parent_real = realpath( dirname( $path ) );
			return false !== $parent_real && self::path_has_prefix( $parent_real, $base_real );
		}

		$path_real = realpath( $path );
		if ( false === $path_real ) {
			return true;
		}

		return self::path_has_prefix( $path_real, $base_real );
	}

	/**
	 * Check path prefix with directory-boundary awareness.
	 *
	 * @param string $path Path.
	 * @param string $base Base path.
	 * @return bool
	 */
	private static function path_has_prefix( $path, $base ) {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		return $path === $base || 0 === strpos( $path, $base . '/' );
	}

	/**
	 * List direct children, including dotfiles.
	 *
	 * @param string $directory Directory path.
	 * @return array|false
	 */
	private static function list_directory_entries( $directory ) {
		$names = scandir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		if ( false === $names ) {
			return false;
		}

		$entries = array();
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$entries[] = trailingslashit( $directory ) . $name;
		}

		return $entries;
	}

	/**
	 * Build the relative path for an attachment.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $attachment_id Attachment ID.
	 * @param string $extension File extension.
	 * @return string
	 */
	private static function relative_path( $conversation_id, $attachment_id, $extension ) {
		return (int) $conversation_id . '/' . sanitize_key( $attachment_id ) . '.' . sanitize_key( $extension );
	}

	/**
	 * Attachment base directory.
	 *
	 * @return string
	 */
	private static function base_dir() {
		return trailingslashit( ABILITIES_BRIDGE_CONTENT_DIR ) . 'attachments';
	}

	/**
	 * Per-conversation directory.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	private static function conversation_dir( $conversation_id ) {
		return trailingslashit( self::base_dir() ) . (int) $conversation_id;
	}

	/**
	 * Generate an opaque attachment id.
	 *
	 * @return string
	 */
	private static function generate_attachment_id() {
		try {
			return 'abimg_' . bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return 'abimg_' . md5( uniqid( '', true ) );
		}
	}
}
