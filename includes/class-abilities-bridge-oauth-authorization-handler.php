<?php
/**
 * OAuth Authorization Handler
 * Handles OAuth authorization flow and consent screens
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Authorization Handler class.
 *
 * Handles OAuth authorization flow and consent screens.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_OAuth_Authorization_Handler {

	/**
	 * OAuth option name
	 */
	const OPTION_NAME = 'abilities_bridge_mcp_oauth';

	/**
	 * Render OAuth admin authorization page
	 *
	 * Handles OAuth authorization through WordPress admin interface.
	 * OAuth parameters are always retrieved from transient storage to prevent
	 * parameter loss during WordPress login redirects.
	 *
	 * Security: Uses WordPress nonce verification tied to the transient key.
	 * The nonce is generated server-side and verified before granting access.
	 */
	public static function render_admin_authorize_page() {
		// Verify user has permission to authorize OAuth connections.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to authorize OAuth connections.', 'abilities-bridge' ),
				esc_html__( 'Permission Denied', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Apply rate limiting.
		$rate_limiter = new Abilities_Bridge_OAuth_Rate_Limiter();
		$rate_check   = $rate_limiter->check_rate_limit( 'authorize' );
		if ( is_wp_error( $rate_check ) ) {
			wp_die(
				esc_html( $rate_check->get_error_message() ),
				esc_html__( 'Rate Limit Exceeded', 'abilities-bridge' ),
				array( 'response' => 429 )
			);
		}

		// Get transient key from URL using filter_input (nonce verified below).
		$transient_key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$transient_key = ! empty( $transient_key ) ? sanitize_text_field( $transient_key ) : '';

		if ( empty( $transient_key ) ) {
			wp_die(
				esc_html__( 'Invalid authorization request. Missing authorization key.', 'abilities-bridge' ) .
				'<br><br>' .
				esc_html__( 'This usually happens when clicking an expired authorization link. Please start the authorization process again.', 'abilities-bridge' ) .
				'<br><br><a href="' . esc_url( home_url() ) . '">' . esc_html__( 'Return to Home', 'abilities-bridge' ) . '</a>',
				esc_html__( 'OAuth Authorization Error', 'abilities-bridge' ),
				array( 'response' => 400 )
			);
		}

		// Verify nonce for authorization page access using filter_input.
		$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$nonce = ! empty( $nonce ) ? sanitize_text_field( $nonce ) : '';

		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_oauth_authorize_' . $transient_key ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Invalid or expired authorization token.', 'abilities-bridge' ) .
				'<br><br>' .
				esc_html__( 'Please start the authorization process again from your application.', 'abilities-bridge' ) .
				'<br><br><a href="' . esc_url( home_url() ) . '">' . esc_html__( 'Return to Home', 'abilities-bridge' ) . '</a>',
				esc_html__( 'OAuth Security Error', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Retrieve OAuth params from transient.
		$oauth_params = get_transient( $transient_key );

		// Delete transient immediately (one-time use for security).
		delete_transient( $transient_key );

		if ( false === $oauth_params || ! is_array( $oauth_params ) ) {
			wp_die(
				esc_html__( 'Authorization request has expired or is invalid.', 'abilities-bridge' ) .
				'<br><br>' .
				esc_html__( 'Authorization requests expire after 10 minutes for security. Please start the authorization process again from your application.', 'abilities-bridge' ) .
				'<br><br><a href="' . esc_url( home_url() ) . '">' . esc_html__( 'Return to Home', 'abilities-bridge' ) . '</a>',
				esc_html__( 'OAuth Authorization Expired', 'abilities-bridge' ),
				array( 'response' => 400 )
			);
		}

		// Extract parameters from transient.
		$client_id             = $oauth_params['client_id'] ?? '';
		$redirect_uri          = $oauth_params['redirect_uri'] ?? '';
		$response_type         = $oauth_params['response_type'] ?? '';
		$code_challenge        = $oauth_params['code_challenge'] ?? '';
		$code_challenge_method = $oauth_params['code_challenge_method'] ?? '';
		$state                 = $oauth_params['state'] ?? '';
		$scope                 = $oauth_params['scope'] ?? '';

		// Validate required parameters.
		$validation_error = self::validate_authorize_params(
			$client_id,
			$redirect_uri,
			$response_type,
			$code_challenge,
			$code_challenge_method
		);

		if ( is_wp_error( $validation_error ) ) {
			// If redirect_uri is valid, redirect with error.
			if ( ! empty( $redirect_uri ) && self::is_valid_redirect_uri( $redirect_uri ) ) {
				$error_url = add_query_arg(
					array(
						'error'             => $validation_error->get_error_code(),
						'error_description' => $validation_error->get_error_message(),
						'state'             => $state,
					),
					$redirect_uri
				);
				wp_safe_redirect( $error_url );
				exit;
			}

			// Otherwise display error.
			wp_die(
				esc_html( $validation_error->get_error_message() ),
				esc_html__( 'OAuth Authorization Error', 'abilities-bridge' ),
				array( 'response' => 400 )
			);
		}

		// User is already authenticated (admin page requires login).
		// No need to check is_user_logged_in() - WordPress admin handles this.

		// Include consent screen template.
		include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/oauth-consent-screen.php';
	}

	/**
	 * Handle /authorize endpoint (GET request)
	 *
	 * Displays consent screen for user authorization.
	 * Always redirects to admin page to properly render HTML consent screen.
	 * - If user is NOT logged in: WordPress login intercepts, then redirects to admin page
	 * - If user is logged in: Goes directly to admin page
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void Redirects to admin page
	 */
	public static function handle_authorize_request( $request ) {
		// Add CORS headers for remote MCP OAuth.
		Abilities_Bridge_OAuth_Discovery_Handler::add_cors_headers();

		// Apply rate limiting.
		$rate_limiter = new Abilities_Bridge_OAuth_Rate_Limiter();
		$rate_check   = $rate_limiter->check_rate_limit( 'authorize' );
		if ( is_wp_error( $rate_check ) ) {
			wp_die(
				esc_html( $rate_check->get_error_message() ),
				esc_html__( 'Rate Limit Exceeded', 'abilities-bridge' ),
				array( 'response' => 429 )
			);
		}

		// Get OAuth parameters.
		$client_id             = $request->get_param( 'client_id' );
		$redirect_uri          = $request->get_param( 'redirect_uri' );
		$response_type         = $request->get_param( 'response_type' );
		$code_challenge        = $request->get_param( 'code_challenge' );
		$code_challenge_method = $request->get_param( 'code_challenge_method' );
		$state                 = $request->get_param( 'state' );
		$scope                 = $request->get_param( 'scope' );

		// Validate required parameters.
		$validation_error = self::validate_authorize_params(
			$client_id,
			$redirect_uri,
			$response_type,
			$code_challenge,
			$code_challenge_method
		);

		if ( is_wp_error( $validation_error ) ) {
			// If redirect_uri is valid, redirect with error.
			if ( ! empty( $redirect_uri ) && self::is_valid_redirect_uri( $redirect_uri ) ) {
				$error_url = add_query_arg(
					array(
						'error'             => $validation_error->get_error_code(),
						'error_description' => $validation_error->get_error_message(),
						'state'             => $state,
					),
					$redirect_uri
				);
				self::safe_redirect_to_oauth_client( $error_url );
				exit;
			}

			// Otherwise display error.
			wp_die(
				esc_html( $validation_error->get_error_message() ),
				esc_html__( 'OAuth Authorization Error', 'abilities-bridge' ),
				array( 'response' => 400 )
			);
		}

		// Always redirect to admin page for proper HTML rendering.
		// This prevents REST API from wrapping the HTML response.
		// Generate cryptographically secure transient key.
		$transient_key = 'abilities_bridge_oauth_params_' . bin2hex( random_bytes( 16 ) );

		// Generate nonce for authorization page access.
		$oauth_nonce = wp_create_nonce( 'abilities_bridge_oauth_authorize_' . $transient_key );

		// Store OAuth parameters in transient (10 minute expiry).
		$oauth_params = array(
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'response_type'         => $response_type,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'state'                 => $state,
			'scope'                 => $scope,
			'oauth_nonce'           => $oauth_nonce, // Store nonce with params for verification.
		);

		set_transient( $transient_key, $oauth_params, 600 ); // 10 minutes.

		// Build admin page URL with transient key and nonce.
		$admin_url = admin_url( 'admin.php?page=oauth-authorize&key=' . $transient_key . '&_wpnonce=' . $oauth_nonce );

		// Redirect to admin page.
		// - If user is logged in: Goes directly to consent screen.
		// - If user is NOT logged in: WordPress login intercepts, then continues to consent screen.
		wp_safe_redirect( $admin_url );
		exit;
	}

	/**
	 * Handle /authorize endpoint (POST request)
	 *
	 * Processes user consent and generates authorization code.
	 * Supports both REST API POST and admin page POST submissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void Redirects to redirect_uri
	 */
	public static function handle_authorize_approval( $request ) {
		$logger = new Abilities_Bridge_OAuth_Logger();

		// Check if user is logged in first (all authenticated users can authorize).
		if ( ! is_user_logged_in() ) {
			wp_die(
				esc_html__( 'You must be logged in to authorize applications.', 'abilities-bridge' ),
				esc_html__( 'Authentication Required', 'abilities-bridge' ),
				array( 'response' => 401 )
			);
		}

		// Verify nonce - check if this is coming from our consent form.
		// Use get_param() to retrieve from HTML form POST (matches old working code).
		$nonce = $request->get_param( 'oauth_nonce' );

		// Verify the nonce is valid.
		$nonce_valid = wp_verify_nonce( $nonce, 'abilities_bridge_oauth_authorize' );

		if ( ! $nonce_valid ) {
			// Log the failure for debugging.
			$logger->log(
				'nonce_verification_failed',
				Abilities_Bridge_OAuth_Logger::LEVEL_WARNING,
				array(
					'user_id'            => get_current_user_id(),
					'nonce_present'      => ! empty( $nonce ),
					'nonce_value_length' => strlen( $nonce ),
				)
			);

			wp_die(
				esc_html__( 'Security verification failed. Your session may have expired. Please try authorizing again.', 'abilities-bridge' ) .
				'<br><br><a href="' . esc_url( admin_url( 'admin.php?page=abilities-bridge-settings' ) ) . '">' .
				esc_html__( 'Return to Settings', 'abilities-bridge' ) . '</a>',
				esc_html__( 'Security Error', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Get parameters using get_param() to support HTML form POST (matches old working code).
		$client_id             = $request->get_param( 'client_id' );
		$redirect_uri          = $request->get_param( 'redirect_uri' );
		$code_challenge        = $request->get_param( 'code_challenge' );
		$code_challenge_method = $request->get_param( 'code_challenge_method' );
		$state                 = $request->get_param( 'state' );
		$scope                 = $request->get_param( 'scope' );
		$approved              = $request->get_param( 'approved' );

		// Debug: Log extracted parameter values.
		$logger->log(
			'parameters_extracted',
			Abilities_Bridge_OAuth_Logger::LEVEL_DEBUG,
			array(
				'client_id_length'      => strlen( $client_id ),
				'redirect_uri_length'   => strlen( $redirect_uri ),
				'has_code_challenge'    => ! empty( $code_challenge ),
				'code_challenge_method' => $code_challenge_method,
				'approved'              => $approved,
				'user_id'               => get_current_user_id(),
			)
		);

		// Check if user denied.
		if ( 'yes' !== $approved ) {
			$logger->log(
				'authorization_denied',
				Abilities_Bridge_OAuth_Logger::LEVEL_INFO,
				array(
					'client_id' => $client_id,
					'user_id'   => get_current_user_id(),
				)
			);

			$error_url = add_query_arg(
				array(
					'error'             => 'access_denied',
					'error_description' => 'User denied authorization',
					'state'             => $state,
				),
				$redirect_uri
			);
			self::safe_redirect_to_oauth_client( $error_url );
			exit;
		}

		// Generate authorization code.
		$code_manager = new Abilities_Bridge_OAuth_Authorization_Code();

		// Debug: Log before code generation attempt.
		$logger->log(
			'attempting_code_generation',
			Abilities_Bridge_OAuth_Logger::LEVEL_DEBUG,
			array(
				'client_id'             => $client_id,
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $redirect_uri,
				'has_code_challenge'    => ! empty( $code_challenge ),
				'code_challenge_method' => $code_challenge_method,
			)
		);

		$code = $code_manager->generate_code(
			$client_id,
			get_current_user_id(),
			$redirect_uri,
			$code_challenge,
			$code_challenge_method,
			$scope,
			$state
		);

		if ( is_wp_error( $code ) ) {
			// Enhanced error logging.
			$logger->log(
				'code_generation_failed',
				Abilities_Bridge_OAuth_Logger::LEVEL_ERROR,
				array(
					'client_id'     => $client_id,
					'error_code'    => $code->get_error_code(),
					'error_message' => $code->get_error_message(),
					'error_data'    => $code->get_error_data(),
					'user_id'       => get_current_user_id(),
				)
			);

			$error_url = add_query_arg(
				array(
					'error'             => 'server_error',
					'error_description' => $code->get_error_message(),
					'state'             => $state,
				),
				$redirect_uri
			);
			self::safe_redirect_to_oauth_client( $error_url );
			exit;
		}

		// Success - redirect with code.
		$success_url = add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);

		// Debug: Log successful code generation and redirect URL.
		$logger->log(
			'redirecting_with_code',
			Abilities_Bridge_OAuth_Logger::LEVEL_DEBUG,
			array(
				'code_length'  => strlen( $code ),
				'redirect_uri' => $redirect_uri,
				'has_state'    => ! empty( $state ),
				'success_url'  => $success_url,
				'client_id'    => $client_id,
			)
		);

		$logger->log_authorization_code_generated( $client_id, get_current_user_id(), $scope );

		self::safe_redirect_to_oauth_client( $success_url );
		exit;
	}

	/**
	 * Validate /authorize request parameters
	 *
	 * @param string $client_id Client ID.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $response_type Response type.
	 * @param string $code_challenge PKCE code challenge.
	 * @param string $code_challenge_method PKCE method.
	 * @return true|WP_Error True if valid, error otherwise
	 */
	private static function validate_authorize_params( $client_id, $redirect_uri, $response_type, $code_challenge, $code_challenge_method ) {
		// Validate client_id.
		if ( empty( $client_id ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing client_id parameter.', 'abilities-bridge' )
			);
		}

		$client = Abilities_Bridge_OAuth_Client_Manager::get_client( $client_id );
		if ( ! $client ) {
			return new WP_Error(
				'invalid_client',
				__( 'Invalid client_id. Use generated MCP client credentials from the Abilities Bridge settings page.', 'abilities-bridge' )
			);
		}

		// Validate redirect_uri.
		if ( empty( $redirect_uri ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing redirect_uri parameter.', 'abilities-bridge' )
			);
		}

		if ( ! self::is_valid_redirect_uri( $redirect_uri, $client_id ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Invalid redirect_uri. Must be a valid HTTPS URL.', 'abilities-bridge' )
			);
		}

		// Validate response_type.
		if ( 'code' !== $response_type ) {
			return new WP_Error(
				'unsupported_response_type',
				__( 'Only response_type=code is supported.', 'abilities-bridge' )
			);
		}

		// Validate PKCE parameters (required for security).
		if ( empty( $code_challenge ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing code_challenge parameter. PKCE is required.', 'abilities-bridge' )
			);
		}

		if ( empty( $code_challenge_method ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing code_challenge_method parameter.', 'abilities-bridge' )
			);
		}

		if ( 'S256' !== $code_challenge_method ) {
			return new WP_Error(
				'invalid_request',
				__( 'Invalid code_challenge_method. Supported method: S256', 'abilities-bridge' )
			);
		}

		return true;
	}

	/**
	 * Safe redirect to OAuth client with allowed hosts filter
	 *
	 * Uses wp_safe_redirect() with a filter to allow validated OAuth redirect URIs.
	 * This ensures WordPress coding standards compliance while supporting external redirects.
	 *
	 * @param string $url URL to redirect to (must be validated with is_valid_redirect_uri first).
	 * @return void
	 */
	private static function safe_redirect_to_oauth_client( $url ) {
		$parsed_url = wp_parse_url( $url );
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

		if ( ! empty( $host ) ) {
			add_filter(
				'allowed_redirect_hosts',
				function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return array_values( array_unique( $hosts ) );
				}
			);
		}

		wp_safe_redirect( $url );
	}

	/**
	 * Validate redirect URI
	 *
	 * @param string $redirect_uri Redirect URI to validate.
	 * @param string $client_id Client ID for profile-aware validation.
	 * @return bool True if valid
	 */
	private static function is_valid_redirect_uri( $redirect_uri, $client_id = '' ) {
		if ( ! filter_var( $redirect_uri, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parsed = wp_parse_url( $redirect_uri );
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';

		if ( empty( $host ) ) {
			return false;
		}

		if ( 'https' !== $parsed['scheme'] ) {
			if ( ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
				return false;
			}
		}

		$client  = ! empty( $client_id ) ? Abilities_Bridge_OAuth_Client_Manager::get_client( $client_id ) : null;
		$profile = $client && ! empty( $client['profile'] ) ? Abilities_Bridge_OAuth_Client_Manager::normalize_profile( $client['profile'] ) : Abilities_Bridge_OAuth_Client_Manager::PROFILE_ANTHROPIC;

		$allowed_hosts = array(
			'localhost',
			'127.0.0.1',
			'claude.ai',
			'chat.openai.com',
			'chatgpt.com',
		);


		$allowed_hosts = apply_filters( 'abilities_bridge_oauth_allowed_redirect_hosts', array_values( array_unique( $allowed_hosts ) ), $client_id, $profile );

		foreach ( $allowed_hosts as $allowed_host ) {
			if ( $host === $allowed_host || strpos( $host, '.' . $allowed_host ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
