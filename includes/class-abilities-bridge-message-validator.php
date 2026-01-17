<?php
/**
 * Message validation and repair utility class
 *
 * This file uses direct database queries for one-time repair operations
 * on the custom messages table. These are maintenance operations.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Message Validator class.
 *
 * Validates message format and content.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Message_Validator {

	/**
	 * Fix empty arrays that should be empty objects in tool_use inputs
	 *
	 * When json_decode($json, true) is used, empty objects {} become empty arrays []
	 * This breaks Claude API validation which expects input to be a dictionary (object)
	 *
	 * @param array $content Message content.
	 * @return array Fixed content
	 */
	public static function fix_empty_tool_inputs( $content ) {
		// Handle array of content blocks (assistant messages with tool_use).
		if ( isset( $content[0] ) && is_array( $content[0] ) ) {
			foreach ( $content as &$block ) {
				if ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
					// If input is an empty array, convert it to empty object for JSON encoding.
					if ( isset( $block['input'] ) && is_array( $block['input'] ) && empty( $block['input'] ) ) {
						$block['input'] = new stdClass();
					}
					// Also check for indexed arrays that should be associative.
					// Sequential numeric keys (0,1,2...) indicate it was an object that got converted.
					if ( isset( $block['input'] ) && is_array( $block['input'] ) &&
						array_keys( $block['input'] ) === range( 0, count( $block['input'] ) - 1 ) ) {
						// This is a sequential array, might have been an empty object.
						// For tool inputs, we expect associative arrays (objects), not sequential.
						if ( empty( $block['input'] ) || ! self::has_string_keys( $block['input'] ) ) {
							$block['input'] = new stdClass();
						}
					}
				}
			}
		}

		return $content;
	}

	/**
	 * Check if array has string keys (is associative)
	 *
	 * @param array $arr Array to check.
	 * @return bool True if has string keys
	 */
	private static function has_string_keys( $arr ) {
		if ( ! is_array( $arr ) || empty( $arr ) ) {
			return false;
		}
		return count( array_filter( array_keys( $arr ), 'is_string' ) ) > 0;
	}

	/**
	 * Validate conversation and repair if corrupted
	 * Fixes issues with unmatched tool_use blocks and malformed tool inputs
	 *
	 * @param array    &$messages Messages array (passed by reference, will be modified).
	 * @param int|null $conversation_id Conversation ID for database updates.
	 * @return bool True if repairs were made
	 */
	public static function validate_and_repair_conversation( &$messages, $conversation_id = null ) {
		$pending_tool_uses = array();
		$repaired          = false;
		$message_count     = count( $messages );

		// Scan messages for unmatched tool_use blocks and fix malformed inputs.
		for ( $i = 0; $i < $message_count; $i++ ) {
			$message = $messages[ $i ];

			if ( 'assistant' === $message['role'] && is_array( $message['content'] ) ) {
				// Check for tool_use blocks and fix empty input arrays.
				foreach ( $message['content'] as &$block ) {
					if ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
						// Fix empty array inputs -> empty object.
						if ( isset( $block['input'] ) && is_array( $block['input'] ) && empty( $block['input'] ) ) {
							$block['input'] = new stdClass();
							$repaired       = true;
						}

						$pending_tool_uses[] = array(
							'tool_use_id' => $block['id'],
							'tool_name'   => $block['name'],
						);
					}
				}
				// Update the message if we fixed anything.
				if ( $repaired ) {
					$messages[ $i ]['content'] = $message['content'];
				}
			} elseif ( 'user' === $message['role'] && is_array( $message['content'] ) ) {
				// Check for tool_result blocks.
				$new_pending = array();
				foreach ( $pending_tool_uses as $pending ) {
					$found = false;
					foreach ( $message['content'] as $block ) {
						if ( isset( $block['type'] ) && 'tool_result' === $block['type']
							&& isset( $block['tool_use_id'] ) && $block['tool_use_id'] === $pending['tool_use_id'] ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						$new_pending[] = $pending;
					}
				}
				$pending_tool_uses = $new_pending;
			}
		}

		// If we have unmatched tool_use blocks, add error results for them.
		if ( ! empty( $pending_tool_uses ) ) {
			$tool_results = array();

			foreach ( $pending_tool_uses as $pending ) {
				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $pending['tool_use_id'],
					'content'     => wp_json_encode(
						array(
							'success' => false,
							'error'   => 'Tool execution was interrupted (conversation recovered from corrupted state)',
						)
					),
				);
			}

			// Add repair message.
			$messages[] = array(
				'role'    => 'user',
				'content' => $tool_results,
			);

			// Save to database if conversation ID provided.
			if ( $conversation_id ) {
				Abilities_Bridge_Database::add_message(
					$conversation_id,
					'user',
					wp_json_encode( $tool_results )
				);
			}

			$repaired = true;
		}

		return $repaired;
	}

	/**
	 * Validate and clean orphaned tool results
	 *
	 * Ensures all tool_result blocks have corresponding tool_use blocks in the previous message.
	 * This prevents API errors: "unexpected tool_use_id found in tool_result blocks"
	 *
	 * @param array    &$messages Messages array (passed by reference, will be modified).
	 * @param int|null $conversation_id Conversation ID for logging.
	 * @return array Array with 'cleaned' (bool) and 'orphaned_ids' (array) if any were removed
	 */
	public static function validate_tool_results( &$messages, $conversation_id = null ) {
		$cleaned      = false;
		$orphaned_ids = array();

		// Find the last assistant message with tool_use blocks.
		$last_tool_use_ids = array();
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( 'assistant' === $messages[ $i ]['role'] ) {
				$content = $messages[ $i ]['content'];

				// Check if content is array (can contain tool_use blocks).
				if ( is_array( $content ) ) {
					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && isset( $block['id'] ) ) {
							$last_tool_use_ids[] = $block['id'];
						}
					}
					break; // Found the last assistant message with potential tool_use blocks.
				}
			}
		}

		// Now check all user messages for tool_result blocks and validate their IDs.
		foreach ( $messages as $index => $message ) {
			if ( 'user' === $message['role'] && is_array( $message['content'] ) ) {
				$cleaned_content = array();

				foreach ( $message['content'] as $block ) {
					if ( isset( $block['type'] ) && 'tool_result' === $block['type'] ) {
						$tool_use_id = isset( $block['tool_use_id'] ) ? $block['tool_use_id'] : null;

						// Check if this tool_result has a corresponding tool_use.
						if ( $tool_use_id && in_array( $tool_use_id, $last_tool_use_ids, true ) ) {
							// Valid - keep it.
							$cleaned_content[] = $block;
						} else {
							// Orphaned - remove it.
							$cleaned        = true;
							$orphaned_ids[] = $tool_use_id;
						}
					} else {
						// Not a tool_result, keep it.
						$cleaned_content[] = $block;
					}
				}

				// Update message content if we removed anything.
				if ( count( $cleaned_content ) !== count( $message['content'] ) ) {
					$messages[ $index ]['content'] = $cleaned_content;
				}
			}
		}

		// Remove any messages that now have empty content (prevents API error: "messages must have non-empty content").
		$messages = array_values(
			array_filter(
				$messages,
				function ( $message ) {
					// Keep messages with non-empty content.
					if ( is_array( $message['content'] ) ) {
						return count( $message['content'] ) > 0;
					}
					// Keep string content (assumed non-empty).
					return ! empty( $message['content'] );
				}
			)
		);

		// Log if we found and cleaned orphaned results.
		if ( $cleaned && $conversation_id ) {
			Abilities_Bridge_Logger::log_tool_progress(
				$conversation_id,
				'validation',
				'warning',
				'⚠️ Cleaned ' . count( $orphaned_ids ) . ' orphaned tool_result(s)'
			);
		}

		return array(
			'cleaned'      => $cleaned,
			'orphaned_ids' => $orphaned_ids,
		);
	}

	/**
	 * Repair all conversations in database
	 * Fixes corrupted tool_use inputs (empty arrays -> empty objects)
	 *
	 * @return array Repair statistics
	 */
	public static function repair_all_conversations() {
		global $wpdb;

		// Get all assistant messages that might have tool_use blocks.
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, content FROM %i WHERE role = %s AND content LIKE %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MESSAGES ),
				'assistant',
				'%tool_use%'
			),
			ARRAY_A
		);

		$repaired = 0;
		$checked  = 0;

		foreach ( $messages as $msg ) {
			++$checked;
			$content = json_decode( $msg['content'], true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $content ) ) {
				continue;
			}

			$modified = false;

			// Check each content block.
			foreach ( $content as &$block ) {
				if ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
					if ( isset( $block['input'] ) && is_array( $block['input'] ) && empty( $block['input'] ) ) {
						$block['input'] = new stdClass();
						$modified       = true;
					}
				}
			}

			if ( $modified ) {
				// Re-encode and save.
				$new_content = wp_json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$wpdb->update(
					Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MESSAGES ),
					array( 'content' => $new_content ),
					array( 'id' => $msg['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				++$repaired;
			}
		}

		return array(
			'checked'  => $checked,
			'repaired' => $repaired,
		);
	}
}
