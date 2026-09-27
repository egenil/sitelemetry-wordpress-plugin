<?php
/**
 * Notice after a weekly audit that needs ownership verification.
 *
 * Expects $view (array) from Sitelemetry_Audit_Admin::render_weekly_notice().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitelemetry_audit_cta = $view['cta'];
?>
<div class="notice notice-warning sitelemetry-audit-weekly-notice">
	<p>
		<strong>
			<?php
			printf(
				/* translators: %s: audit kind label. */
				esc_html__( 'Weekly Sitelemetry audit (%s)', 'sitelemetry-audit' ),
				esc_html( Sitelemetry_Audit_Labels::kind_label( $view['kind'] ) )
			);
			?>
		</strong>
		<?php echo esc_html( $sitelemetry_audit_cta['text'] ); ?>
	</p>
	<p>
		<?php if ( $sitelemetry_audit_cta['helper'] ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $view['verification_url'] ); ?>"><?php echo esc_html( $sitelemetry_audit_cta['renew'] ? __( 'Renew the verification', 'sitelemetry-audit' ) : __( 'Verify this site', 'sitelemetry-audit' ) ); ?></a>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'View results', 'sitelemetry-audit' ); ?></a>
		<a class="button-link" href="<?php echo esc_url( $view['dismiss_url'] ); ?>"><?php esc_html_e( 'Dismiss', 'sitelemetry-audit' ); ?></a>
	</p>
</div>
