<?php
/**
 * Dashboard widget body.
 *
 * Expects $view (array) from Sitelemetry_Audit_Dashboard::render().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitelemetry_audit_model = $view['model'];
?>
<div class="sitelemetry-audit-widget">
	<?php if ( ! $view['has_key'] ) : ?>
		<p><?php esc_html_e( 'Connect your Sitelemetry account to audit this site for security, SEO and performance issues.', 'sitelemetry-audit' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( $view['settings_url'] ); ?>"><?php esc_html_e( 'Add API key', 'sitelemetry-audit' ); ?></a></p>
	<?php elseif ( ! $sitelemetry_audit_model ) : ?>
		<?php if ( $view['job'] ) : ?>
			<p><span class="sitelemetry-audit-spinner" aria-hidden="true"></span> <?php esc_html_e( 'An audit is running.', 'sitelemetry-audit' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No audit has run yet.', 'sitelemetry-audit' ); ?></p>
		<?php endif; ?>
		<p><a class="button button-primary" href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'Open Sitelemetry', 'sitelemetry-audit' ); ?></a></p>
	<?php else : ?>
		<?php if ( $view['job'] ) : ?>
			<p><span class="sitelemetry-audit-spinner" aria-hidden="true"></span> <?php esc_html_e( 'An audit is running.', 'sitelemetry-audit' ); ?></p>
		<?php endif; ?>
		<div class="sitelemetry-audit-widget-score">
			<?php if ( null !== $sitelemetry_audit_model['score'] ) : ?>
				<span class="sitelemetry-audit-score-value"><?php echo esc_html( $sitelemetry_audit_model['score'] ); ?></span><span class="sitelemetry-audit-score-max">/100</span>
				<?php if ( ! empty( $sitelemetry_audit_model['grade'] ) ) : ?>
					<span class="sitelemetry-audit-grade"><?php echo esc_html( $sitelemetry_audit_model['grade'] ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<span class="sitelemetry-audit-score-value sitelemetry-audit-score-value--none"><?php esc_html_e( 'Not measured', 'sitelemetry-audit' ); ?></span>
			<?php endif; ?>
		</div>
		<p class="sitelemetry-audit-widget-status">
			<?php
			$sitelemetry_audit_status = sprintf(
				/* translators: 1: audit kind label, 2: status of the audit. */
				esc_html__( '%1$s: %2$s', 'sitelemetry-audit' ),
				'<strong>' . esc_html( Sitelemetry_Audit_Labels::kind_label( $sitelemetry_audit_model['kind'] ) ) . '</strong>',
				esc_html( $view['status_heading'] )
			);
			if ( ! empty( $sitelemetry_audit_model['finished_at'] ) ) {
				$sitelemetry_audit_status = sprintf(
					/* translators: 1: audit kind and status, 2: date and time the audit finished. Translate the parentheses as your language writes them. */
					esc_html__( '%1$s (%2$s)', 'sitelemetry-audit' ),
					$sitelemetry_audit_status,
					'<span class="description">' . esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_model['finished_at'] ) ) . '</span>'
				);
			}
			echo wp_kses(
				$sitelemetry_audit_status,
				array(
					'strong' => array(),
					'span'   => array( 'class' => array() ),
				)
			);
			?>
		</p>
		<?php if ( in_array( $sitelemetry_audit_model['status'], array( 'completed', 'partial' ), true ) ) : ?>
			<p class="sitelemetry-audit-widget-counts">
				<?php $sitelemetry_audit_any = false; ?>
				<?php foreach ( $view['severities'] as $sitelemetry_audit_severity => $sitelemetry_audit_label ) : ?>
					<?php if ( ! empty( $sitelemetry_audit_model['counts'][ $sitelemetry_audit_severity ] ) ) : ?>
						<?php $sitelemetry_audit_any = true; ?>
						<span class="sitelemetry-audit-chip sitelemetry-audit-chip--<?php echo esc_attr( $sitelemetry_audit_severity ); ?>"><?php echo esc_html( $sitelemetry_audit_model['counts'][ $sitelemetry_audit_severity ] . ' ' . $sitelemetry_audit_label ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ! $sitelemetry_audit_any ) : ?>
					<span class="sitelemetry-audit-chip sitelemetry-audit-chip--none"><?php esc_html_e( 'No findings', 'sitelemetry-audit' ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php if ( $view['verification'] ) : ?>
			<div class="sitelemetry-audit-widget-verify">
				<p><?php echo esc_html( $view['verification']['text'] ); ?></p>
				<?php if ( ! $view['verification']['verified'] ) : ?>
					<p>
						<?php if ( $view['verification']['helper'] ) : ?>
							<a href="<?php echo esc_url( $view['verification_url'] ); ?>"><?php echo esc_html( $view['verification']['renew'] ? __( 'Renew the verification', 'sitelemetry-audit' ) : __( 'Verify this site', 'sitelemetry-audit' ) ); ?></a>
						<?php else : ?>
							<a href="<?php echo esc_url( $view['app_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verified Domains in the app', 'sitelemetry-audit' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( $view['verification']['renews'] ) : ?>
					<p class="description"><?php esc_html_e( 'A verification lasts 30 days; the plugin renews it automatically.', 'sitelemetry-audit' ); ?></p>
				<?php elseif ( ! $view['verification']['verified'] ) : ?>
					<p class="description"><?php esc_html_e( 'A verification lasts 30 days and is not renewed automatically.', 'sitelemetry-audit' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<p><a class="button button-primary" href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'View results', 'sitelemetry-audit' ); ?></a></p>
	<?php endif; ?>
</div>
