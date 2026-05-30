<?php
/**
 * Integrations page template.
 *
 * @package Abilities_Bridge
 * @since   1.2.0
 *
 * @var array $integrations Array of discovered plugin integrations.
 * @var array $validation_notice Optional validation notice counts.
 * @var array $cleanup_notice Optional cleanup notice data.
 * @var bool  $cleanup_done Whether cleanup just completed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ab-integrations-wrap">

	<h1><?php esc_html_e( 'Connected Plugins', 'abilities-bridge' ); ?></h1>
	<p class="ab-page-description">
		<?php esc_html_e( 'Plugins that support Abilities Bridge can register their tools here. Approve integrations to allow AI agents to use their abilities.', 'abilities-bridge' ); ?>
	</p>

	<?php if ( ! empty( $validation_notice['total'] ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %d: number of dropped integration data items. */
					esc_html__( '%d integration data items were dropped due to invalid data. Enable WP_DEBUG to see details.', 'abilities-bridge' ),
					intval( $validation_notice['total'] )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $cleanup_notice['url'] ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Old Beacon Send approvals were found. They are not used by Beacon Campaign Sender and can be cleaned up safely.', 'abilities-bridge' ); ?>
				<a class="button button-small" href="<?php echo esc_url( $cleanup_notice['url'] ); ?>">
					<?php esc_html_e( 'Clean old Beacon Send approvals', 'abilities-bridge' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $cleanup_done ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Old Beacon Send approvals were cleaned up.', 'abilities-bridge' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="ab-notification" class="ab-notification" style="display: none;">
		<span class="ab-notification-message"></span>
	</div>

	<?php if ( empty( $integrations ) ) : ?>

		<div class="ab-empty-state">
			<span class="dashicons dashicons-plugins-checked"></span>
			<h2><?php esc_html_e( 'No plugins with Abilities Bridge support detected.', 'abilities-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Install and activate plugins that integrate with Abilities Bridge, or check that existing integrations are enabled in their settings.', 'abilities-bridge' ); ?></p>
		</div>

	<?php else : ?>

		<?php foreach ( $integrations as $slug => $integration ) : ?>
			<?php
			$icon             = isset( $integration['icon'] ) ? $integration['icon'] : 'dashicons-admin-plugins';
			$total_abilities  = isset( $integration['total_count'] ) ? (int) $integration['total_count'] : count( $integration['abilities'] );
			$declared_count   = count( $integration['abilities'] );
			$approved_count   = isset( $integration['approved_count'] ) ? (int) $integration['approved_count'] : 0;
			$status           = isset( $integration['status'] ) ? $integration['status'] : 'available';
			$settings_url     = isset( $integration['settings_url'] ) ? $integration['settings_url'] : '';
			$enabled          = ! empty( $integration['integration_enabled'] );
			$has_profiles     = ! empty( $integration['approval_profiles'] );
			$can_approve      = $enabled && ( $total_abilities > 0 || $has_profiles );
			$status_labels    = array(
				'available' => __( 'Available', 'abilities-bridge' ),
				'approved'  => __( 'Approved', 'abilities-bridge' ),
				'partial'   => __( 'Partially Approved', 'abilities-bridge' ),
				'disabled'  => __( 'Disabled', 'abilities-bridge' ),
				'empty'     => __( 'No Abilities', 'abilities-bridge' ),
			);
			$status_label     = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
			?>

			<div class="ab-plugin-card ab-plugin-card-<?php echo esc_attr( $status ); ?>" data-plugin-slug="<?php echo esc_attr( $slug ); ?>">

				<div class="ab-plugin-header">
					<div class="ab-plugin-icon">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
					</div>

					<div class="ab-plugin-meta">
						<h2 class="ab-plugin-name">
							<?php echo esc_html( $integration['plugin_name'] ); ?>
							<span class="ab-plugin-version">
								<?php
								printf(
									/* translators: %s: version number. */
									esc_html__( 'v%s', 'abilities-bridge' ),
									esc_html( $integration['plugin_version'] )
								);
								?>
							</span>
						</h2>
						<p class="ab-plugin-description"><?php echo esc_html( $integration['plugin_description'] ); ?></p>

						<?php if ( ! $enabled ) : ?>
							<p class="ab-plugin-note">
								<?php
								printf(
									/* translators: %s: plugin name. */
									esc_html__( 'Disabled in %s settings.', 'abilities-bridge' ),
									esc_html( $integration['plugin_name'] )
								);
								?>
								<?php if ( $settings_url ) : ?>
									<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open settings', 'abilities-bridge' ); ?></a>
								<?php endif; ?>
							</p>
						<?php elseif ( 0 === $total_abilities && ! $has_profiles ) : ?>
							<p class="ab-plugin-note">
								<?php esc_html_e( 'No abilities are available from this integration right now.', 'abilities-bridge' ); ?>
								<?php if ( $settings_url ) : ?>
									<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open settings', 'abilities-bridge' ); ?></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>

					<div class="ab-plugin-status">
						<span class="ab-status-badge ab-status-<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
						<span class="ab-ability-count">
							<?php
							printf(
								/* translators: 1: approved count, 2: total count. */
								esc_html__( '%1$d / %2$d abilities', 'abilities-bridge' ),
								intval( $approved_count ),
								intval( $total_abilities )
							);
							?>
						</span>
					</div>
				</div>

				<?php if ( $declared_count > 0 ) : ?>
					<div class="ab-plugin-body">
						<button type="button" class="ab-toggle-abilities" aria-expanded="false">
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<?php
							printf(
								/* translators: %d: number of abilities. */
								esc_html__( 'Show %d abilities', 'abilities-bridge' ),
								intval( $declared_count )
							);
							?>
						</button>

						<div class="ab-abilities-body" style="display: none;">
							<table class="ab-abilities-table widefat">
								<thead>
									<tr>
										<th class="ab-col-status"><?php esc_html_e( 'Status', 'abilities-bridge' ); ?></th>
										<th class="ab-col-name"><?php esc_html_e( 'Ability', 'abilities-bridge' ); ?></th>
										<th class="ab-col-description"><?php esc_html_e( 'Description', 'abilities-bridge' ); ?></th>
										<th class="ab-col-risk"><?php esc_html_e( 'Risk', 'abilities-bridge' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $integration['abilities'] as $ability ) : ?>
										<?php
										$risk        = isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low';
										$is_approved = ! empty( $ability['approved'] );
										?>
										<tr class="ab-ability-row" data-ability="<?php echo esc_attr( $ability['name'] ); ?>">
											<td class="ab-col-status">
												<?php if ( $is_approved ) : ?>
													<span class="ab-approved-check" title="<?php esc_attr_e( 'Approved', 'abilities-bridge' ); ?>">&#10003;</span>
												<?php else : ?>
													<span class="ab-pending-dash" title="<?php esc_attr_e( 'Not approved', 'abilities-bridge' ); ?>">&mdash;</span>
												<?php endif; ?>
											</td>
											<td class="ab-col-name">
												<code><?php echo esc_html( $ability['name'] ); ?></code>
											</td>
											<td class="ab-col-description">
												<?php echo esc_html( $ability['description'] ); ?>
											</td>
											<td class="ab-col-risk">
												<span class="ab-risk-badge ab-risk-<?php echo esc_attr( $risk ); ?>">
													<?php echo esc_html( ucfirst( $risk ) ); ?>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>

				<div class="ab-actions">
					<?php if ( $can_approve ) : ?>
						<?php if ( $has_profiles ) : ?>
							<div class="ab-profile-actions">
								<?php foreach ( $integration['approval_profiles'] as $profile_key => $profile ) : ?>
									<?php
									$profile_total    = isset( $profile['total'] ) ? (int) $profile['total'] : 0;
									$profile_approved = isset( $profile['approved'] ) ? (int) $profile['approved'] : 0;
									$profile_done     = ! empty( $profile['is_fully_approved'] );
									?>
									<div class="ab-profile-action">
										<div class="ab-profile-copy">
											<strong><?php echo esc_html( $profile['label'] ); ?></strong>
											<span><?php echo esc_html( $profile_approved . ' / ' . $profile_total ); ?></span>
											<?php if ( ! empty( $profile['description'] ) ) : ?>
												<p><?php echo esc_html( $profile['description'] ); ?></p>
											<?php endif; ?>
										</div>
										<?php if ( $profile_done ) : ?>
											<button type="button" class="button ab-btn-revoke" data-slug="<?php echo esc_attr( $slug ); ?>" data-profile="<?php echo esc_attr( $profile_key ); ?>">
												<?php esc_html_e( 'Revoke', 'abilities-bridge' ); ?>
											</button>
										<?php else : ?>
											<button type="button" class="button ab-btn-approve" data-slug="<?php echo esc_attr( $slug ); ?>" data-profile="<?php echo esc_attr( $profile_key ); ?>">
												<?php esc_html_e( 'Approve', 'abilities-bridge' ); ?>
											</button>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<?php if ( 'available' === $status || 'partial' === $status ) : ?>
								<button type="button" class="button ab-btn-approve" data-slug="<?php echo esc_attr( $slug ); ?>" data-profile="all">
									<?php esc_html_e( 'Approve All Abilities', 'abilities-bridge' ); ?>
								</button>
							<?php endif; ?>

							<?php if ( 'approved' === $status || 'partial' === $status ) : ?>
								<button type="button" class="button ab-btn-revoke" data-slug="<?php echo esc_attr( $slug ); ?>" data-profile="all">
									<?php esc_html_e( 'Revoke All', 'abilities-bridge' ); ?>
								</button>
							<?php endif; ?>
						<?php endif; ?>
					<?php elseif ( $settings_url ) : ?>
						<a class="button" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'abilities-bridge' ); ?></a>
					<?php else : ?>
						<span class="ab-no-actions"><?php esc_html_e( 'No actions available.', 'abilities-bridge' ); ?></span>
					<?php endif; ?>
				</div>

			</div>

		<?php endforeach; ?>

	<?php endif; ?>

</div>
