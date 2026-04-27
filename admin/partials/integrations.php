<?php
/**
 * Integrations page template.
 *
 * @package Abilities_Bridge
 * @since   1.2.0
 *
 * @var array $integrations Array of discovered plugin integrations.
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
			$icon           = isset( $integration['icon'] ) ? $integration['icon'] : 'dashicons-admin-plugins';
			$total_abilities = count( $integration['abilities'] );
			$approved_count = $integration['approved_count'];
			$status         = $integration['status'];

			// Status badge classes and labels.
			$status_labels = array(
				'available' => __( 'Available', 'abilities-bridge' ),
				'approved'  => __( 'Approved', 'abilities-bridge' ),
				'partial'   => __( 'Partially Approved', 'abilities-bridge' ),
			);
			$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
			?>

			<div class="ab-plugin-card" data-plugin-slug="<?php echo esc_attr( $slug ); ?>">

				<div class="ab-plugin-header">
					<div class="ab-plugin-icon">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
					</div>

					<div class="ab-plugin-meta">
						<h2 class="ab-plugin-name">
							<?php echo esc_html( $integration['plugin_name'] ); ?>
							<span class="ab-plugin-version">
								<?php
								/* translators: %s: version number */
								printf( 'v%s', esc_html( $integration['plugin_version'] ) );
								?>
							</span>
						</h2>
						<p class="ab-plugin-description"><?php echo esc_html( $integration['plugin_description'] ); ?></p>
					</div>

					<div class="ab-plugin-status">
						<span class="ab-status-badge ab-status-<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
						<span class="ab-ability-count">
							<?php
							printf(
								/* translators: 1: approved count, 2: total count */
								esc_html__( '%1$d / %2$d abilities', 'abilities-bridge' ),
								intval( $approved_count ),
								intval( $total_abilities )
							);
							?>
						</span>
					</div>
				</div>

				<div class="ab-plugin-body">
					<button type="button" class="ab-toggle-abilities" aria-expanded="false">
						<span class="dashicons dashicons-arrow-down-alt2"></span>
						<?php
						printf(
							/* translators: %d: number of abilities */
							esc_html__( 'Show %d abilities', 'abilities-bridge' ),
							intval( $total_abilities )
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
									$risk       = isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low';
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

				<div class="ab-actions">
					<?php if ( 'available' === $status || 'partial' === $status ) : ?>
						<button type="button" class="button ab-btn-approve" data-slug="<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( 'Approve All Abilities', 'abilities-bridge' ); ?>
						</button>
					<?php endif; ?>

					<?php if ( 'approved' === $status || 'partial' === $status ) : ?>
						<button type="button" class="button ab-btn-revoke" data-slug="<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( 'Revoke All', 'abilities-bridge' ); ?>
						</button>
					<?php endif; ?>
				</div>

			</div>

		<?php endforeach; ?>

	<?php endif; ?>

</div>
