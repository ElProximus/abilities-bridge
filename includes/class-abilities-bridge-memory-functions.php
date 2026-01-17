<?php
/**
 * Memory Functions class - implements Anthropic's Memory Tool (Database-Based)
 *
 * This file uses direct database queries for the custom memory table.
 * Memory operations are real-time and don't benefit from object caching.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory Functions class.
 *
 * Implements Anthropic's Memory Tool using database-based storage.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Memory_Functions {

	/**
	 * Maximum content size (1MB)
	 */
	const MAX_CONTENT_SIZE = 1048576;

	/**
	 * Maximum total memory usage (50MB)
	 */
	const MAX_TOTAL_SIZE = 52428800;

	/**
	 * Validate and sanitize path to prevent directory traversal
	 *
	 * @param string $path Path to validate.
	 * @return string|WP_Error Sanitized path or error
	 */
	private static function validate_path( $path ) {
		// Remove any null bytes.
		$path = str_replace( "\0", '', $path );

		// Normalize path separators.
		$path = str_replace( '\\', '/', $path );

		// Ensure path starts with /memories.
		if ( strpos( $path, '/memories' ) !== 0 ) {
			return new WP_Error( 'invalid_path', 'Path must start with /memories' );
		}

		// Remove /memories prefix for internal use.
		$relative_path = substr( $path, 9 );

		// Check for directory traversal attempts.
		$dangerous_patterns = array(
			'../',
			'..\\',
			'%2e%2e%2f',
			'%2e%2e/',
			'%2e%2e%5c',
			'..%2f',
			'..%5c',
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( stripos( $relative_path, $pattern ) !== false ) {
				return new WP_Error( 'directory_traversal', 'Directory traversal attempt detected' );
			}
		}

		// Return the full path (starting with /memories).
		return $path;
	}

	/**
	 * Execute a memory tool command
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	public static function execute( $input ) {
		if ( ! isset( $input['command'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameter: command',
			);
		}

		$command = $input['command'];

		switch ( $command ) {
			case 'view':
				return self::view( $input );

			case 'create':
				return self::create( $input );

			case 'str_replace':
				return self::str_replace( $input );

			case 'insert':
				return self::insert( $input );

			case 'delete':
				return self::delete( $input );

			case 'rename':
				return self::rename( $input );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown memory command: {$command}",
				);
		}
	}

	/**
	 * View directory contents or file contents
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function view( $input ) {
		if ( ! isset( $input['path'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameter: path',
			);
		}

		$path = self::validate_path( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'error'   => $path->get_error_message(),
			);
		}

		global $wpdb;

		// Special case: /memories root directory listing.
		// Unlike subdirectories, /memories is never created as a database record.
		// because ensure_parent_directories() skips it. Rather than change that.
		// behavior (which could cause issues), we handle it explicitly here.
		if ( '/memories' === $path ) {
			// Get all top-level items in /memories.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT path, type FROM %i
					WHERE path LIKE %s
					AND path != %s
					ORDER BY type DESC, path ASC',
					Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES ),
					'/memories/%',
					'/memories'
				)
			);

			// Filter to only direct children (not nested subdirectories).
			$dirs  = array();
			$files = array();
			foreach ( $items as $item ) {
				$relative = substr( $item->path, 10 ); // Remove '/memories/' prefix.
				// Only include if there's no additional slash (direct child).
				if ( strpos( $relative, '/' ) === false ) {
					if ( 'directory' === $item->type ) {
						$dirs[] = $relative . '/';
					} else {
						$files[] = $relative;
					}
				}
			}

			// Format output.
			if ( empty( $dirs ) && empty( $files ) ) {
				$output = "Directory: /memories\n(empty)";
			} else {
				$output = "Directory: /memories\n";
				foreach ( $dirs as $dir ) {
					$output .= '  ' . $dir . "\n";
				}
				foreach ( $files as $file ) {
					$output .= '  ' . $file . "\n";
				}
			}

			return array(
				'success' => true,
				'output'  => trim( $output ),
			);
		}

		// Check if exact path exists.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE path = %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES ),
				$path
			)
		);

		// If directory, list contents.
		if ( $record && 'directory' === $record->type ) {
			// Get all items that are direct children of this directory.
			$path_prefix = rtrim( $path, '/' ) . '/';
			$items       = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT path, type FROM %i
					WHERE path LIKE %s
					AND path != %s
					ORDER BY type DESC, path ASC',
					Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES ),
					$wpdb->esc_like( $path_prefix ) . '%',
					$path
				)
			);

			// Filter to only direct children (not nested subdirectories).
			$dirs  = array();
			$files = array();
			foreach ( $items as $item ) {
				$relative = substr( $item->path, strlen( $path_prefix ) );
				// Only include if there's no additional slash (direct child).
				if ( strpos( $relative, '/' ) === false ) {
					if ( 'directory' === $item->type ) {
						$dirs[] = $relative . '/';
					} else {
						$files[] = $relative;
					}
				}
			}

			// Format output.
			$output = 'Directory: ' . $input['path'] . "\n";
			foreach ( $dirs as $dir ) {
				$output .= '  ' . $dir . "\n";
			}
			foreach ( $files as $file ) {
				$output .= '  ' . $file . "\n";
			}

			return array(
				'success' => true,
				'output'  => trim( $output ),
			);
		}

		// If file, read contents.
		if ( $record && 'file' === $record->type ) {
			$content = $record->content;

			// Handle view_range if specified.
			if ( isset( $input['view_range'] ) && is_array( $input['view_range'] ) ) {
				$lines   = explode( "\n", $content );
				$start   = max( 0, $input['view_range'][0] - 1 );
				$end     = min( count( $lines ), $input['view_range'][1] );
				$lines   = array_slice( $lines, $start, $end - $start );
				$content = implode( "\n", $lines );
			}

			return array(
				'success' => true,
				'output'  => $content,
			);
		}

		// Path not found.
		return array(
			'success' => false,
			'error'   => 'Path not found: ' . $input['path'],
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Create or overwrite a file
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function create( $input ) {
		if ( ! isset( $input['path'] ) || ! isset( $input['file_text'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameters: path and/or file_text',
			);
		}

		$path = self::validate_path( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'error'   => $path->get_error_message(),
			);
		}

		// Check content size.
		if ( strlen( $input['file_text'] ) > self::MAX_CONTENT_SIZE ) {
			return array(
				'success' => false,
				'error'   => 'File content exceeds maximum size of ' . self::MAX_CONTENT_SIZE . ' bytes',
			);
		}

		// Check total memory usage.
		$total_size = self::get_total_memory_size();
		if ( $total_size + strlen( $input['file_text'] ) > self::MAX_TOTAL_SIZE ) {
			return array(
				'success' => false,
				'error'   => 'Total memory usage would exceed limit of ' . self::MAX_TOTAL_SIZE . ' bytes',
			);
		}

		global $wpdb;

		// Create parent directories if needed.
		$parent_path = dirname( $path );
		if ( '/memories' !== $parent_path && '.' !== $parent_path ) {
			self::ensure_parent_directories( $parent_path );
		}

		// Insert or update the file.
		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );
		$existing       = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE path = %s',
				$memories_table,
				$path
			)
		);

		if ( $existing ) {
			// Update existing file.
			$wpdb->update(
				$memories_table,
				array(
					'content' => $input['file_text'],
					'type'    => 'file',
				),
				array( 'path' => $path ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		} else {
			// Insert new file.
			$wpdb->insert(
				$memories_table,
				array(
					'path'    => $path,
					'content' => $input['file_text'],
					'type'    => 'file',
				),
				array( '%s', '%s', '%s' )
			);
		}

		return array(
			'success' => true,
			'output'  => 'File created: ' . $input['path'],
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Ensure parent directories exist in database
	 *
	 * @param string $path Directory path.
	 */
	private static function ensure_parent_directories( $path ) {
		global $wpdb;

		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );

		// Split path into parts.
		$parts   = explode( '/', trim( $path, '/' ) );
		$current = '';

		foreach ( $parts as $part ) {
			if ( empty( $part ) ) {
				continue;
			}

			$current .= '/' . $part;

			// Check if directory exists.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE path = %s AND type = 'directory'",
					$memories_table,
					$current
				)
			);

			if ( ! $exists ) {
				// Create directory entry.
				$wpdb->insert(
					$memories_table,
					array(
						'path'    => $current,
						'type'    => 'directory',
						'content' => null,
					),
					array( '%s', '%s', null )
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Replace text in a file
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function str_replace( $input ) {
		if ( ! isset( $input['path'] ) || ! isset( $input['old_str'] ) || ! isset( $input['new_str'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameters: path, old_str, and/or new_str',
			);
		}

		$path = self::validate_path( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'error'   => $path->get_error_message(),
			);
		}

		global $wpdb;

		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );

		// Get file content.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE path = %s AND type = 'file'",
				$memories_table,
				$path
			)
		);

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'File not found: ' . $input['path'],
			);
		}

		$content = $record->content;

		// Check if old_str exists.
		if ( strpos( $content, $input['old_str'] ) === false ) {
			return array(
				'success' => false,
				'error'   => 'String not found in file',
			);
		}

		// Replace.
		$new_content = str_replace( $input['old_str'], $input['new_str'], $content );

		// Update.
		$wpdb->update(
			$memories_table,
			array( 'content' => $new_content ),
			array( 'path' => $path ),
			array( '%s' ),
			array( '%s' )
		);

		return array(
			'success' => true,
			'output'  => 'Text replaced in: ' . $input['path'],
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Insert text at a specific line
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function insert( $input ) {
		if ( ! isset( $input['path'] ) || ! isset( $input['insert_line'] ) || ! isset( $input['insert_text'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameters: path, insert_line, and/or insert_text',
			);
		}

		$path = self::validate_path( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'error'   => $path->get_error_message(),
			);
		}

		global $wpdb;

		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );

		// Get file content.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE path = %s AND type = 'file'",
				$memories_table,
				$path
			)
		);

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'File not found: ' . $input['path'],
			);
		}

		$content = $record->content;

		// Split into lines.
		$lines = explode( "\n", $content );

		// Insert at line (0-indexed).
		$insert_line = intval( $input['insert_line'] );
		if ( $insert_line < 0 || $insert_line > count( $lines ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid insert_line: must be between 0 and ' . count( $lines ),
			);
		}

		// Insert text.
		array_splice( $lines, $insert_line, 0, $input['insert_text'] );

		// Join back.
		$new_content = implode( "\n", $lines );

		// Update.
		$wpdb->update(
			$memories_table,
			array( 'content' => $new_content ),
			array( 'path' => $path ),
			array( '%s' ),
			array( '%s' )
		);

		return array(
			'success' => true,
			'output'  => 'Text inserted in: ' . $input['path'],
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Delete a file or directory
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function delete( $input ) {
		if ( ! isset( $input['path'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameter: path',
			);
		}

		// Don't allow deleting the root memory directory.
		if ( '/memories' === $input['path'] || '/memories/' === $input['path'] ) {
			return array(
				'success' => false,
				'error'   => 'Cannot delete root memory directory',
			);
		}

		$path = self::validate_path( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'error'   => $path->get_error_message(),
			);
		}

		global $wpdb;

		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );

		// Get record.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE path = %s',
				$memories_table,
				$path
			)
		);

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'Path not found: ' . $input['path'],
			);
		}

		// Delete file.
		if ( 'file' === $record->type ) {
			$wpdb->delete(
				$memories_table,
				array( 'path' => $path ),
				array( '%s' )
			);

			return array(
				'success' => true,
				'output'  => 'File deleted: ' . $input['path'],
			);
		}

		// Delete directory (recursive).
		if ( 'directory' === $record->type ) {
			// Delete the directory and all children.
			$path_prefix = rtrim( $path, '/' ) . '/';
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE path = %s OR path LIKE %s',
					$memories_table,
					$path,
					$wpdb->esc_like( $path_prefix ) . '%'
				)
			);

			return array(
				'success' => true,
				'output'  => 'Directory deleted: ' . $input['path'],
			);
		}

		return array(
			'success' => false,
			'error'   => 'Invalid record type',
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Rename or move a file/directory
	 *
	 * @param array $input Command input.
	 * @return array Result
	 */
	private static function rename( $input ) {
		if ( ! isset( $input['old_path'] ) || ! isset( $input['new_path'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required parameters: old_path and/or new_path',
			);
		}

		$old_path = self::validate_path( $input['old_path'] );
		if ( is_wp_error( $old_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid old_path: ' . $old_path->get_error_message(),
			);
		}

		$new_path = self::validate_path( $input['new_path'] );
		if ( is_wp_error( $new_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid new_path: ' . $new_path->get_error_message(),
			);
		}

		global $wpdb;

		$memories_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES );

		// Check if old path exists.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE path = %s',
				$memories_table,
				$old_path
			)
		);

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'Source path not found: ' . $input['old_path'],
			);
		}

		// Check if new path already exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE path = %s',
				$memories_table,
				$new_path
			)
		);

		if ( $exists ) {
			return array(
				'success' => false,
				'error'   => 'Destination path already exists: ' . $input['new_path'],
			);
		}

		// Ensure parent directories exist.
		$new_parent = dirname( $new_path );
		if ( '/memories' !== $new_parent && '.' !== $new_parent ) {
			self::ensure_parent_directories( $new_parent );
		}

		// Rename/move - update the path.
		if ( 'file' === $record->type ) {
			$wpdb->update(
				$memories_table,
				array( 'path' => $new_path ),
				array( 'path' => $old_path ),
				array( '%s' ),
				array( '%s' )
			);
		} else {
			// For directories, update the directory and all children.
			$old_prefix = rtrim( $old_path, '/' ) . '/';
			$new_prefix = rtrim( $new_path, '/' ) . '/';

			// Get all items that need to be renamed.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE path = %s OR path LIKE %s',
					$memories_table,
					$old_path,
					$wpdb->esc_like( $old_prefix ) . '%'
				)
			);

			foreach ( $items as $item ) {
				$updated_path = $item->path;
				if ( $item->path === $old_path ) {
					$updated_path = $new_path;
				} else {
					// Replace the old prefix with new prefix.
					$updated_path = $new_prefix . substr( $item->path, strlen( $old_prefix ) );
				}

				$wpdb->update(
					$memories_table,
					array( 'path' => $updated_path ),
					array( 'id' => $item->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		return array(
			'success' => true,
			'output'  => 'Renamed: ' . $input['old_path'] . ' -> ' . $input['new_path'],
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}

	/**
	 * Get total size of memory content
	 *
	 * @return int Total size in bytes
	 */
	private static function get_total_memory_size() {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(content)) FROM %i WHERE type = 'file'",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MEMORIES )
			)
		);

		return intval( $total );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.
	}
}
