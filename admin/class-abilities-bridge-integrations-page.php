<?php
/**
 * Integrations Page - Discover and manage plugin integrations.
 *
 * @package Abilities_Bridge
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations Page class.
 *
 * Discovers plugins that register abilities with Abilities Bridge,
 * displays their available abilities, and manages approval state.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_Integrations_Page {

	const FILTER_NAME        = 'abilities_bridge_plugin_integrations';
	const NOTICE_TRANSIENT   = 'abilities_bridge_integrations_validation_notice';
	const MAX_INTEGRATIONS   = 50;
	const MAX_ABILITIES      = 100;
	const MAX_PROFILES       = 10;
	const MAX_PER_DAY        = 10000;
	const MAX_PER_HOUR       = 1000;
	const APPROVED_STATE_VER = 2;

	/**
	 * Validation counters for the current request.
	 *
	 * @var array
	 */
	private static $notice_counts = array(
		'callbacks'    => 0,
		'integrations' => 0,
		'abilities'    => 0,
		'profiles'     => 0,
	);

	/**
	 * Initialize the integrations page.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_approve_integration', array( __CLASS__, 'ajax_approve_integration' ) );
		add_action( 'wp_ajax_abilities_bridge_revoke_integration', array( __CLASS__, 'ajax_revoke_integration' ) );
		add_action( 'admin_post_abilities_bridge_cleanup_beacon_send', array( __CLASS__, 'handle_cleanup_beacon_send' ) );
	}

	/**
	 * Add submenu page under Abilities Bridge.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'abilities-bridge',
			__( 'Integrations', 'abilities-bridge' ),
			__( 'Integrations', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-integrations',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JS assets only on the integrations page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'abilities-bridge-integrations' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'ab-integrations',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/integrations.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'ab-integrations',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/integrations.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'ab-integrations',
			'abIntegrations',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_integrations_nonce' ),
				'i18n'    => array(
					'approving'        => __( 'Approving...', 'abilities-bridge' ),
					'revoking'         => __( 'Revoking...', 'abilities-bridge' ),
					'approveProfile'   => __( 'Approve', 'abilities-bridge' ),
					'revokeProfile'    => __( 'Revoke', 'abilities-bridge' ),
					'approveAll'       => __( 'Approve All Abilities', 'abilities-bridge' ),
					'revokeAll'        => __( 'Revoke All', 'abilities-bridge' ),
					'approved'         => __( 'Approved', 'abilities-bridge' ),
					'available'        => __( 'Available', 'abilities-bridge' ),
					'partial'          => __( 'Partially Approved', 'abilities-bridge' ),
					'notApproved'      => __( 'Not approved', 'abilities-bridge' ),
					'requestFailed'    => __( 'Request failed. Please try again.', 'abilities-bridge' ),
					'approvalFailed'   => __( 'Approval failed.', 'abilities-bridge' ),
					'revocationFailed' => __( 'Revocation failed.', 'abilities-bridge' ),
				),
			)
		);
	}

	/**
	 * Discover available plugin integrations.
	 *
	 * Partner plugins register by adding a callback to the
	 * `abilities_bridge_plugin_integrations` filter at file-include time or no
	 * later than `plugins_loaded`. Discovery runs when this admin page renders,
	 * after `admin_init`.
	 *
	 * @return array Associative array of integration data keyed by plugin slug.
	 */
	public static function discover_integrations() {
		self::reset_notice_counts();

		$integrations = self::apply_integration_filters_safely();
		$approved     = self::get_approved_state();

		foreach ( $integrations as $slug => &$integration ) {
			$approved_abilities = self::get_approved_ability_names( $approved, $slug );
			$ability_universe   = self::get_integration_ability_name_universe( $integration );
			$total              = count( $ability_universe );
			$approved_count     = 0;

			foreach ( $integration['abilities'] as &$ability ) {
				$ability['approved'] = in_array( $ability['name'], $approved_abilities, true );
				if ( $ability['approved'] ) {
					++$approved_count;
				}
			}
			unset( $ability );

			foreach ( $ability_universe as $ability_name ) {
				if ( ! self::integration_declares_ability( $integration, $ability_name ) && in_array( $ability_name, $approved_abilities, true ) ) {
					++$approved_count;
				}
			}

			if ( ! $integration['integration_enabled'] ) {
				$integration['status'] = 'disabled';
			} elseif ( 0 === $total && empty( $integration['approval_profiles'] ) ) {
				$integration['status'] = 'empty';
			} elseif ( 0 === $approved_count ) {
				$integration['status'] = 'available';
			} elseif ( $approved_count === $total && $total > 0 ) {
				$integration['status'] = 'approved';
			} else {
				$integration['status'] = 'partial';
			}

			$integration['approved_count'] = $approved_count;
			$integration['total_count']    = $total;

			foreach ( $integration['approval_profiles'] as $profile_key => &$profile ) {
				$profile_names    = self::get_ability_names_for_profile( $integration, $profile_key );
				$profile_approved = 0;

				foreach ( $profile_names as $ability_name ) {
					if ( in_array( $ability_name, $approved_abilities, true ) ) {
						++$profile_approved;
					}
				}

				$profile['total']             = count( $profile_names );
				$profile['approved']          = $profile_approved;
				$profile['is_fully_approved'] = ( $profile_approved === count( $profile_names ) && count( $profile_names ) > 0 );
			}
			unset( $profile );
		}
		unset( $integration );

		self::store_notice_transient();

		return $integrations;
	}

	/**
	 * Apply integration filter callbacks one at a time.
	 *
	 * This intentionally reads WP_Hook internals in a read-only way so a
	 * Throwable in one callback cannot erase integrations registered by earlier
	 * callbacks or prevent later callbacks from running.
	 *
	 * @return array Normalized integrations.
	 */
	private static function apply_integration_filters_safely() {
		global $wp_filter, $wp_current_filter;

		$integrations = array();

		if ( empty( $wp_filter[ self::FILTER_NAME ] ) || ! is_object( $wp_filter[ self::FILTER_NAME ] ) || empty( $wp_filter[ self::FILTER_NAME ]->callbacks ) ) {
			return $integrations;
		}

		$callbacks_by_priority = $wp_filter[ self::FILTER_NAME ]->callbacks;
		ksort( $callbacks_by_priority );

		if ( ! is_array( $wp_current_filter ) ) {
			$wp_current_filter = array();
		}
		$wp_current_filter[] = self::FILTER_NAME;

		foreach ( $callbacks_by_priority as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function      = isset( $callback['function'] ) ? $callback['function'] : null;
				$accepted_args = isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1;
				$source        = self::describe_callback( $function );

				try {
					$args   = array_slice( array( $integrations ), 0, max( 0, $accepted_args ) );
					$result = call_user_func_array( $function, $args );
				} catch ( Throwable $e ) {
					++self::$notice_counts['callbacks'];
					self::debug_log(
						sprintf(
							'integration callback failed: %1$s at %2$s. %3$s',
							$source['name'],
							$source['location'],
							$e->getMessage()
						)
					);
					continue;
				}

				if ( ! is_array( $result ) ) {
					++self::$notice_counts['callbacks'];
					self::debug_log( 'Integration callback returned non-array: ' . $source['name'] . ' at ' . $source['location'] );
					continue;
				}

				$integrations = self::normalize_integrations( $result, $integrations, $source );
			}
		}

		array_pop( $wp_current_filter );

		return $integrations;
	}

	/**
	 * Normalize integrations returned by a callback.
	 *
	 * @param array $candidate Integrations returned by the callback.
	 * @param array $accepted  Previously accepted integrations.
	 * @param array $source    Callback source metadata.
	 * @return array Updated accepted integrations.
	 */
	private static function normalize_integrations( $candidate, $accepted, $source ) {
		foreach ( $candidate as $key => $integration ) {
			if ( count( $accepted ) >= self::MAX_INTEGRATIONS ) {
				self::drop_integration( 'Integration cap exceeded by ' . $source['name'] );
				break;
			}

			if ( ! is_array( $integration ) ) {
				self::drop_integration( 'Integration entry is not an array from ' . $source['name'] );
				continue;
			}

			$slug = isset( $integration['plugin_slug'] ) ? trim( wp_strip_all_tags( (string) $integration['plugin_slug'] ) ) : trim( wp_strip_all_tags( (string) $key ) );

			if ( ! self::is_valid_slug( $slug ) ) {
				self::drop_integration( 'Invalid integration slug from ' . $source['name'] );
				continue;
			}

			if ( isset( $accepted[ $slug ] ) ) {
				if ( $accepted[ $slug ] !== $integration ) {
					self::drop_integration( 'Duplicate integration slug dropped: ' . $slug . ' from ' . $source['name'] );
				}
				continue;
			}

			$plugin_name = self::clean_text( isset( $integration['plugin_name'] ) ? $integration['plugin_name'] : '', 100 );
			if ( '' === $plugin_name ) {
				self::drop_integration( 'Integration missing plugin_name for slug ' . $slug );
				continue;
			}

			$normalized = array(
				'plugin_slug'         => $slug,
				'plugin_name'         => $plugin_name,
				'plugin_description'  => self::clean_text( isset( $integration['plugin_description'] ) ? $integration['plugin_description'] : '', 500 ),
				'plugin_version'      => self::clean_text( isset( $integration['plugin_version'] ) ? $integration['plugin_version'] : 'Unknown', 50 ),
				'plugin_active'       => isset( $integration['plugin_active'] ) ? (bool) $integration['plugin_active'] : true,
				'integration_enabled' => isset( $integration['integration_enabled'] ) ? (bool) $integration['integration_enabled'] : true,
				'settings_url'        => self::build_settings_url( isset( $integration['settings_admin_page'] ) ? $integration['settings_admin_page'] : '' ),
				'abilities'           => self::normalize_abilities( isset( $integration['abilities'] ) ? $integration['abilities'] : array(), $slug ),
				'approval_profiles'   => self::normalize_profiles( isset( $integration['approval_profiles'] ) ? $integration['approval_profiles'] : array(), $slug ),
				'icon'                => sanitize_html_class( isset( $integration['icon'] ) ? $integration['icon'] : 'dashicons-admin-plugins' ),
			);

			$accepted[ $slug ] = $normalized;
		}

		return $accepted;
	}

	/**
	 * Normalize ability definitions for an integration.
	 *
	 * @param mixed  $abilities Raw abilities.
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	private static function normalize_abilities( $abilities, $plugin_slug ) {
		if ( ! is_array( $abilities ) ) {
			++self::$notice_counts['abilities'];
			self::debug_log( 'Abilities for ' . $plugin_slug . ' are not an array.' );
			return array();
		}

		$normalized = array();

		foreach ( $abilities as $ability ) {
			if ( count( $normalized ) >= self::MAX_ABILITIES ) {
				++self::$notice_counts['abilities'];
				self::debug_log( 'Ability cap exceeded for ' . $plugin_slug );
				break;
			}

			if ( ! is_array( $ability ) ) {
				++self::$notice_counts['abilities'];
				continue;
			}

			$name = isset( $ability['name'] ) ? (string) $ability['name'] : '';
			if ( ! self::is_valid_ability_name( $name ) ) {
				++self::$notice_counts['abilities'];
				self::debug_log( 'Invalid ability name for ' . $plugin_slug . ': ' . $name );
				continue;
			}

			$description = self::clean_text( isset( $ability['description'] ) ? $ability['description'] : '', 500 );
			if ( '' === $description ) {
				++self::$notice_counts['abilities'];
				self::debug_log( 'Missing ability description for ' . $name );
				continue;
			}

			$risk_level = isset( $ability['risk_level'] ) ? sanitize_key( $ability['risk_level'] ) : 'low';
			if ( ! in_array( $risk_level, array( 'low', 'medium', 'high' ), true ) ) {
				++self::$notice_counts['abilities'];
				self::debug_log( 'Invalid risk level for ' . $name );
				continue;
			}

			$group = '';
			if ( isset( $ability['group'] ) ) {
				$group = trim( wp_strip_all_tags( (string) $ability['group'] ) );
				if ( '' !== $group && ! self::is_valid_slug( $group ) ) {
					++self::$notice_counts['abilities'];
					self::debug_log( 'Invalid group for ' . $name );
					$group = '';
				}
			}

			$normalized[] = array(
				'name'        => $name,
				'description' => $description,
				'risk_level'  => $risk_level,
				'group'       => $group,
				'permissions' => self::normalize_permissions( isset( $ability['permissions'] ) ? $ability['permissions'] : array(), $name ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize approval profiles.
	 *
	 * @param mixed  $profiles Raw profiles.
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	private static function normalize_profiles( $profiles, $plugin_slug ) {
		if ( empty( $profiles ) ) {
			return array();
		}

		if ( ! is_array( $profiles ) ) {
			++self::$notice_counts['profiles'];
			self::debug_log( 'Approval profiles for ' . $plugin_slug . ' are not an array.' );
			return array();
		}

		$normalized = array();

		foreach ( $profiles as $profile_slug => $profile ) {
			if ( count( $normalized ) >= self::MAX_PROFILES ) {
				++self::$notice_counts['profiles'];
				self::debug_log( 'Approval profile cap exceeded for ' . $plugin_slug );
				break;
			}

			$profile_slug = trim( wp_strip_all_tags( (string) $profile_slug ) );
			if ( ! self::is_valid_slug( $profile_slug ) || ! is_array( $profile ) ) {
				++self::$notice_counts['profiles'];
				continue;
			}

			$label = self::clean_text( isset( $profile['label'] ) ? $profile['label'] : '', 100 );
			if ( '' === $label ) {
				++self::$notice_counts['profiles'];
				self::debug_log( 'Approval profile missing label: ' . $profile_slug );
				continue;
			}

			$profile_abilities = array();
			if ( isset( $profile['abilities'] ) ) {
				if ( is_array( $profile['abilities'] ) ) {
					foreach ( $profile['abilities'] as $ability_name ) {
						if ( count( $profile_abilities ) >= self::MAX_ABILITIES ) {
							++self::$notice_counts['profiles'];
							self::debug_log( 'Profile ability cap exceeded for ' . $profile_slug );
							break;
						}
						$ability_name = (string) $ability_name;
						if ( self::is_valid_ability_name( $ability_name ) ) {
							$profile_abilities[] = $ability_name;
						} else {
							++self::$notice_counts['profiles'];
							self::debug_log( 'Invalid ability name in profile ' . $profile_slug . ': ' . $ability_name );
						}
					}
				} else {
					++self::$notice_counts['profiles'];
					self::debug_log( 'Profile abilities list is not an array for ' . $profile_slug );
				}
			}

			$normalized[ $profile_slug ] = array(
				'label'       => $label,
				'description' => self::clean_text( isset( $profile['description'] ) ? $profile['description'] : '', 500 ),
				'abilities'   => array_values( array_unique( $profile_abilities ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize rate limits.
	 *
	 * @param mixed  $permissions Raw permissions.
	 * @param string $ability_name Ability name.
	 * @return array
	 */
	private static function normalize_permissions( $permissions, $ability_name ) {
		$normalized = array(
			'max_per_day'  => 1000,
			'max_per_hour' => 100,
		);

		if ( ! is_array( $permissions ) ) {
			return $normalized;
		}

		foreach ( $normalized as $key => $default ) {
			if ( ! isset( $permissions[ $key ] ) || ! is_numeric( $permissions[ $key ] ) ) {
				continue;
			}

			$value = (int) $permissions[ $key ];
			$cap   = ( 'max_per_day' === $key ) ? self::MAX_PER_DAY : self::MAX_PER_HOUR;

			if ( $value < 0 ) {
				$value = 0;
				_doing_it_wrong( __METHOD__, esc_html( $ability_name . ' supplied a negative ' . $key . ' limit.' ), esc_html( ABILITIES_BRIDGE_VERSION ) );
			}

			if ( $value > $cap ) {
				$value = $cap;
				_doing_it_wrong( __METHOD__, esc_html( $ability_name . ' supplied a ' . $key . ' limit above the bridge cap.' ), esc_html( ABILITIES_BRIDGE_VERSION ) );
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * AJAX handler: Approve abilities for a plugin integration.
	 *
	 * @return void
	 */
	public static function ajax_approve_integration() {
		check_ajax_referer( 'abilities_bridge_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'abilities-bridge' ) ), 403 );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';
		$profile     = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : 'all';

		if ( empty( $plugin_slug ) || ! self::is_valid_slug( $plugin_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin slug required.', 'abilities-bridge' ) ), 400 );
		}

		$integrations = self::discover_integrations();

		if ( ! isset( $integrations[ $plugin_slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Integration not found.', 'abilities-bridge' ) ), 404 );
		}

		$integration = $integrations[ $plugin_slug ];
		if ( empty( $integration['integration_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Integration is disabled in the provider settings.', 'abilities-bridge' ) ), 400 );
		}

		$target_names = self::get_ability_names_for_profile( $integration, $profile );
		if ( empty( $target_names ) ) {
			wp_send_json_error( array( 'message' => __( 'No abilities found for this profile.', 'abilities-bridge' ) ), 400 );
		}

		$approved_names = array();
		$missing        = array();

		foreach ( $target_names as $ability_name ) {
			$ability = self::resolve_ability_for_approval( $integration, $ability_name );
			if ( ! $ability ) {
				$missing[] = $ability_name;
				continue;
			}

			if ( self::approve_ability_in_runtime( $ability ) ) {
				$approved_names[] = $ability_name;
			}
		}

		$approved_names = array_values( array_unique( $approved_names ) );
		$missing        = array_values( array_unique( $missing ) );

		self::persist_approved_profile( $plugin_slug, $profile, $approved_names );

		if ( ! get_option( 'abilities_bridge_enable_abilities_api', false ) ) {
			update_option( 'abilities_bridge_enable_abilities_api', true );
		}

		$message = sprintf(
			/* translators: 1: number of approved abilities, 2: plugin name. */
			__( 'Approved %1$d abilities for %2$s.', 'abilities-bridge' ),
			count( $approved_names ),
			$integration['plugin_name']
		);

		if ( ! empty( $missing ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of skipped abilities. */
				__( '%d abilities were not registered yet.', 'abilities-bridge' ),
				count( $missing )
			);
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'approved' => count( $approved_names ),
				'skipped'  => count( $missing ),
				'missing'  => $missing,
				'profile'  => $profile,
			)
		);
	}

	/**
	 * AJAX handler: Revoke abilities for a plugin integration.
	 *
	 * @return void
	 */
	public static function ajax_revoke_integration() {
		check_ajax_referer( 'abilities_bridge_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'abilities-bridge' ) ), 403 );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';
		$profile     = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : 'all';

		if ( empty( $plugin_slug ) || ! self::is_valid_slug( $plugin_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin slug required.', 'abilities-bridge' ) ), 400 );
		}

		$integrations = self::discover_integrations();

		if ( ! isset( $integrations[ $plugin_slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Integration not found.', 'abilities-bridge' ) ), 404 );
		}

		$integration    = $integrations[ $plugin_slug ];
		$revoked_names  = self::get_ability_names_for_profile( $integration, $profile );
		$approved_state = self::remove_approved_profile( $plugin_slug, $profile );
		$still_approved = self::get_all_approved_ability_names( $approved_state );

		foreach ( $revoked_names as $ability_name ) {
			if ( ! in_array( $ability_name, $still_approved, true ) ) {
				self::disable_ability_in_runtime( $ability_name );
			}
		}

		wp_send_json_success(
			array(
				'message' => __( 'Integration revoked.', 'abilities-bridge' ),
				'profile' => $profile,
			)
		);
	}

	/**
	 * Render the Integrations admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'abilities-bridge' ) );
		}

		$integrations      = self::discover_integrations();
		$validation_notice = get_transient( self::NOTICE_TRANSIENT );
		$cleanup_notice    = self::get_beacon_send_cleanup_notice();
		$cleanup_done      = isset( $_GET['beacon_send_cleanup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['beacon_send_cleanup'] ) );

		include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/partials/integrations.php';
	}

	/**
	 * Cleanup old Beacon Send approvals.
	 *
	 * @return void
	 */
	public static function handle_cleanup_beacon_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'abilities-bridge' ), 403 );
		}

		check_admin_referer( 'abilities_bridge_cleanup_beacon_send' );

		$approved = get_option( 'abilities_bridge_approved_integrations', array() );
		if ( isset( $approved['beacon-send'] ) ) {
			unset( $approved['beacon-send'] );
			update_option( 'abilities_bridge_approved_integrations', $approved );
		}

		if ( class_exists( 'Abilities_Bridge_Database' ) ) {
			global $wpdb;
			$table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET enabled = %d, disabled_date = %s WHERE ability_name LIKE %s',
					$table,
					0,
					current_time( 'mysql' ),
					$wpdb->esc_like( 'beacon-send/' ) . '%'
				)
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-integrations&beacon_send_cleanup=1' ) );
		exit;
	}

	/**
	 * Get abilities that belong to a specific approval profile.
	 *
	 * @param array  $integration Integration data.
	 * @param string $profile_key Profile key.
	 * @return array Ability names.
	 */
	private static function get_ability_names_for_profile( $integration, $profile_key ) {
		$profile_key = sanitize_key( $profile_key );

		if ( 'all' === $profile_key ) {
			return wp_list_pluck( $integration['abilities'], 'name' );
		}

		if ( isset( $integration['approval_profiles'][ $profile_key ] ) && ! empty( $integration['approval_profiles'][ $profile_key ]['abilities'] ) ) {
			return array_values( array_unique( $integration['approval_profiles'][ $profile_key ]['abilities'] ) );
		}

		$names = array();
		foreach ( $integration['abilities'] as $ability ) {
			if ( isset( $ability['group'] ) && $ability['group'] === $profile_key ) {
				$names[] = $ability['name'];
			}
		}

		return $names;
	}

	/**
	 * Get the unique set of ability names represented by an integration.
	 *
	 * This includes declared abilities plus explicit profile references, which
	 * allows recipe-style integrations to approve abilities registered by other
	 * providers.
	 *
	 * @param array $integration Integration data.
	 * @return array
	 */
	private static function get_integration_ability_name_universe( $integration ) {
		$names = wp_list_pluck( $integration['abilities'], 'name' );

		foreach ( $integration['approval_profiles'] as $profile_key => $profile ) {
			if ( ! empty( $profile['abilities'] ) ) {
				$names = array_merge( $names, $profile['abilities'] );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Check whether the integration declares an ability definition.
	 *
	 * @param array  $integration Integration data.
	 * @param string $ability_name Ability name.
	 * @return bool
	 */
	private static function integration_declares_ability( $integration, $ability_name ) {
		foreach ( $integration['abilities'] as $ability ) {
			if ( $ability['name'] === $ability_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve an ability definition for runtime approval.
	 *
	 * @param array  $integration Integration data.
	 * @param string $ability_name Ability name.
	 * @return array|null
	 */
	private static function resolve_ability_for_approval( $integration, $ability_name ) {
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( $ability_name ) ) {
			return null;
		}

		foreach ( $integration['abilities'] as $ability ) {
			if ( $ability['name'] === $ability_name ) {
				return $ability;
			}
		}

		$wp_ability = wp_get_ability( $ability_name );

		return array(
			'name'        => $ability_name,
			'description' => ! empty( $wp_ability->description ) ? self::clean_text( $wp_ability->description, 500 ) : $ability_name,
			'risk_level'  => 'low',
			'permissions' => array(
				'max_per_day'  => 1000,
				'max_per_hour' => 100,
			),
		);
	}

	/**
	 * Register an ability in the runtime permissions system.
	 *
	 * @param array $ability Ability definition.
	 * @return bool
	 */
	private static function approve_ability_in_runtime( $ability ) {
		global $wpdb;

		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( $ability['name'] ) ) {
			return false;
		}

		$permissions  = isset( $ability['permissions'] ) ? $ability['permissions'] : array();
		$max_per_day  = isset( $permissions['max_per_day'] ) ? (int) $permissions['max_per_day'] : 1000;
		$max_per_hour = isset( $permissions['max_per_hour'] ) ? (int) $permissions['max_per_hour'] : 100;

		if ( class_exists( 'Abilities_Bridge_Ability_Permissions' ) ) {
			$result = Abilities_Bridge_Ability_Permissions::register_ability(
				$ability['name'],
				array(
					'enabled'                => 1,
					'max_per_day'            => $max_per_day,
					'max_per_hour'           => $max_per_hour,
					'risk_level'             => isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low',
					'requires_user_approval' => 0,
					'min_capability'         => 'manage_options',
					'description'            => $ability['description'],
					'reason_for_approval'    => 'Approved via Integrations page for ' . $ability['name'],
				)
			);

			return ! is_wp_error( $result );
		}

		if ( class_exists( 'Abilities_Bridge_Database' ) ) {
			$table    = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE ability_name = %s',
					$table,
					$ability['name']
				)
			);

			if ( $existing ) {
				$updated = $wpdb->update(
					$table,
					array(
						'enabled'      => 1,
						'max_per_day'  => $max_per_day,
						'max_per_hour' => $max_per_hour,
						'enabled_date' => current_time( 'mysql' ),
					),
					array( 'ability_name' => $ability['name'] ),
					array( '%d', '%d', '%d', '%s' ),
					array( '%s' )
				);

				return false !== $updated;
			}

			$inserted = $wpdb->insert(
				$table,
				array(
					'ability_name'            => $ability['name'],
					'enabled'                 => 1,
					'max_per_day'             => $max_per_day,
					'max_per_hour'            => $max_per_hour,
					'max_per_request'         => 1,
					'risk_level'              => isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low',
					'requires_user_approval'  => 0,
					'requires_admin_approval' => 0,
					'min_capability'          => 'manage_options',
					'description'             => $ability['description'],
					'reason_for_approval'     => 'Approved via Integrations page',
					'approved_by_user_id'     => get_current_user_id(),
					'approved_date'           => current_time( 'mysql' ),
					'enabled_date'            => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			return false !== $inserted;
		}

		return false;
	}

	/**
	 * Disable an ability in the runtime permissions table.
	 *
	 * @param string $ability_name Ability name.
	 * @return void
	 */
	private static function disable_ability_in_runtime( $ability_name ) {
		if ( class_exists( 'Abilities_Bridge_Ability_Permissions' ) ) {
			Abilities_Bridge_Ability_Permissions::disable_ability( $ability_name, 'Revoked via Integrations page' );
			return;
		}

		if ( class_exists( 'Abilities_Bridge_Database' ) ) {
			global $wpdb;
			$table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			$wpdb->update(
				$table,
				array(
					'enabled'       => 0,
					'disabled_date' => current_time( 'mysql' ),
				),
				array( 'ability_name' => $ability_name ),
				array( '%d', '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Get normalized approved state without writing migrations.
	 *
	 * @return array
	 */
	private static function get_approved_state() {
		$raw = get_option( 'abilities_bridge_approved_integrations', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$state = array();
		foreach ( $raw as $slug => $entry ) {
			$slug = sanitize_key( $slug );
			if ( ! self::is_valid_slug( $slug ) ) {
				continue;
			}

			if ( is_array( $entry ) && isset( $entry['profiles'] ) && is_array( $entry['profiles'] ) ) {
				$state[ $slug ] = array(
					'version'  => isset( $entry['version'] ) ? (int) $entry['version'] : self::APPROVED_STATE_VER,
					'profiles' => array(),
				);

				foreach ( $entry['profiles'] as $profile => $ability_names ) {
					$profile = sanitize_key( $profile );
					if ( ! self::is_valid_slug( $profile ) || ! is_array( $ability_names ) ) {
						continue;
					}
					$state[ $slug ]['profiles'][ $profile ] = self::sanitize_ability_name_list( $ability_names );
				}
			} elseif ( is_array( $entry ) ) {
				$state[ $slug ] = array(
					'version'  => self::APPROVED_STATE_VER,
					'profiles' => array(
						'all' => self::sanitize_ability_name_list( $entry ),
					),
				);
			}
		}

		return $state;
	}

	/**
	 * Persist a profile approval.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $profile Profile slug.
	 * @param array  $ability_names Approved ability names.
	 * @return void
	 */
	private static function persist_approved_profile( $plugin_slug, $profile, $ability_names ) {
		$state = self::get_approved_state();

		if ( ! isset( $state[ $plugin_slug ] ) ) {
			$state[ $plugin_slug ] = array(
				'version'  => self::APPROVED_STATE_VER,
				'profiles' => array(),
			);
		}

		$ability_names = self::sanitize_ability_name_list( $ability_names );
		if ( empty( $ability_names ) ) {
			unset( $state[ $plugin_slug ]['profiles'][ $profile ] );
		} else {
			$state[ $plugin_slug ]['profiles'][ $profile ] = $ability_names;
		}

		if ( empty( $state[ $plugin_slug ]['profiles'] ) ) {
			unset( $state[ $plugin_slug ] );
		}

		update_option( 'abilities_bridge_approved_integrations', $state );
	}

	/**
	 * Remove an approved profile.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $profile Profile slug.
	 * @return array Updated normalized state.
	 */
	private static function remove_approved_profile( $plugin_slug, $profile ) {
		$state = self::get_approved_state();

		if ( isset( $state[ $plugin_slug ] ) ) {
			if ( 'all' === $profile ) {
				unset( $state[ $plugin_slug ] );
			} else {
				unset( $state[ $plugin_slug ]['profiles'][ $profile ] );
				if ( empty( $state[ $plugin_slug ]['profiles'] ) ) {
					unset( $state[ $plugin_slug ] );
				}
			}
		}

		update_option( 'abilities_bridge_approved_integrations', $state );

		return $state;
	}

	/**
	 * Get approved ability names for one integration.
	 *
	 * @param array  $state Approved state.
	 * @param string $slug Integration slug.
	 * @return array
	 */
	private static function get_approved_ability_names( $state, $slug ) {
		if ( empty( $state[ $slug ]['profiles'] ) ) {
			return array();
		}

		$names = array();
		foreach ( $state[ $slug ]['profiles'] as $profile_names ) {
			$names = array_merge( $names, $profile_names );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Get every approved ability name across integrations.
	 *
	 * @param array $state Approved state.
	 * @return array
	 */
	private static function get_all_approved_ability_names( $state ) {
		$names = array();
		foreach ( $state as $slug => $entry ) {
			$names = array_merge( $names, self::get_approved_ability_names( $state, $slug ) );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Sanitize ability name list.
	 *
	 * @param array $ability_names Ability names.
	 * @return array
	 */
	private static function sanitize_ability_name_list( $ability_names ) {
		$names = array();
		foreach ( $ability_names as $ability_name ) {
			$ability_name = (string) $ability_name;
			if ( self::is_valid_ability_name( $ability_name ) ) {
				$names[] = $ability_name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Build a safe settings URL from an admin page slug or query.
	 *
	 * @param string $raw Raw page slug/query.
	 * @return string
	 */
	private static function build_settings_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9-]*$#', $raw ) ) {
			return admin_url( 'admin.php?page=' . rawurlencode( $raw ) );
		}

		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || ! empty( $parts['scheme'] ) || ! empty( $parts['host'] ) ) {
			self::debug_log( 'Rejected unsafe settings_admin_page: ' . $raw );
			return '';
		}

		$path = isset( $parts['path'] ) ? ltrim( $parts['path'], '/' ) : '';
		if ( 'admin.php' !== $path || empty( $parts['query'] ) ) {
			self::debug_log( 'Rejected unsupported settings_admin_page: ' . $raw );
			return '';
		}

		parse_str( $parts['query'], $query );
		if ( empty( $query['page'] ) || ! preg_match( '#^[a-z][a-z0-9-]*$#', $query['page'] ) ) {
			self::debug_log( 'Rejected settings_admin_page without safe page slug: ' . $raw );
			return '';
		}

		$safe_query = array( 'page' => sanitize_key( $query['page'] ) );
		unset( $query['page'] );
		foreach ( $query as $key => $value ) {
			if ( preg_match( '#^[a-z][a-z0-9_-]*$#', (string) $key ) && is_scalar( $value ) ) {
				$safe_query[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return add_query_arg( $safe_query, admin_url( 'admin.php' ) );
	}

	/**
	 * Get Beacon Send cleanup notice data.
	 *
	 * @return array|null
	 */
	private static function get_beacon_send_cleanup_notice() {
		$approved = get_option( 'abilities_bridge_approved_integrations', array() );
		$has_old  = is_array( $approved ) && isset( $approved['beacon-send'] );

		if ( ! $has_old && class_exists( 'Abilities_Bridge_Database' ) ) {
			global $wpdb;
			$table   = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			$has_old = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE enabled = %d AND ability_name LIKE %s LIMIT 1',
					$table,
					1,
					$wpdb->esc_like( 'beacon-send/' ) . '%'
				)
			);
		}

		if ( ! $has_old ) {
			return null;
		}

		return array(
			'url' => wp_nonce_url(
				admin_url( 'admin-post.php?action=abilities_bridge_cleanup_beacon_send' ),
				'abilities_bridge_cleanup_beacon_send'
			),
		);
	}

	/**
	 * Store validation notice transient.
	 *
	 * @return void
	 */
	private static function store_notice_transient() {
		$total = array_sum( self::$notice_counts );
		if ( $total <= 0 ) {
			delete_transient( self::NOTICE_TRANSIENT );
			return;
		}

		set_transient(
			self::NOTICE_TRANSIENT,
			array_merge(
				self::$notice_counts,
				array(
					'total' => $total,
				)
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Reset validation counters.
	 *
	 * @return void
	 */
	private static function reset_notice_counts() {
		self::$notice_counts = array(
			'callbacks'    => 0,
			'integrations' => 0,
			'abilities'    => 0,
			'profiles'     => 0,
		);
	}

	/**
	 * Increment dropped integration counter.
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	private static function drop_integration( $message ) {
		++self::$notice_counts['integrations'];
		_doing_it_wrong( __METHOD__, esc_html( $message ), esc_html( ABILITIES_BRIDGE_VERSION ) );
		self::debug_log( $message );
	}

	/**
	 * Debug log when WP_DEBUG is enabled.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Abilities Bridge integrations: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Describe a callback for logs.
	 *
	 * @param callable $callback Callback.
	 * @return array
	 */
	private static function describe_callback( $callback ) {
		$name     = 'unknown callback';
		$location = 'unknown location';

		try {
			if ( is_string( $callback ) ) {
				$name       = $callback;
				$reflection = new ReflectionFunction( $callback );
			} elseif ( $callback instanceof Closure ) {
				$name       = 'closure';
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				$name       = $class . '::' . $callback[1];
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$name       = get_class( $callback ) . '::__invoke';
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return array(
					'name'     => $name,
					'location' => $location,
				);
			}

			$location = $reflection->getFileName() . ':' . $reflection->getStartLine();
		} catch ( Exception $e ) {
			$location = 'unavailable';
		}

		return array(
			'name'     => $name,
			'location' => $location,
		);
	}

	/**
	 * Validate plugin/profile slug.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private static function is_valid_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '#^[a-z][a-z0-9]*(-[a-z0-9]+)*$#', $slug );
	}

	/**
	 * Validate ability name.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private static function is_valid_ability_name( $name ) {
		return is_string( $name ) && strlen( $name ) <= 255 && 1 === preg_match( '#^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)+$#', $name );
	}

	/**
	 * Strip tags and limit text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private static function clean_text( $value, $max_length ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		if ( strlen( $value ) > $max_length ) {
			$value = substr( $value, 0, $max_length );
		}

		return $value;
	}
}
