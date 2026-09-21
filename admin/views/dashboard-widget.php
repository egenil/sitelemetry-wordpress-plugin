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
			<strong><?php echo esc_html( Sitelemetry_Audit_Labels::kind_label( $sitelemetry_audit_model['kind'] ) ); ?></strong>:
			<?php echo esc_html( $view['status_heading'] ); ?>
			<?php if ( ! empty( $sitelemetry_audit_model['finished_at'] ) ) : ?>
				<span class="description">(<?php echo esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_model['finished_at'] ) ); ?>)</span>
			<?php endif; ?>
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
		<?php if ( null !== $sitelemetry_audit_model['remaining_scans'] ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of remaining security scans. */
					esc_html__( 'Remaining security scans this period: %d', 'sitelemetry-audit' ),
					(int) $sitelemetry_audit_model['remaining_scans']
				);
				?>
			</p>
		<?php endif; ?>
		<p><a class="button button-primary" href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'View results', 'sitelemetry-audit' ); ?></a></p>
	<?php endif; ?>
</div>
