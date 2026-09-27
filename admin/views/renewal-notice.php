<?php
/**
 * Notice after a failed automatic renewal of the ownership verification.
 *
 * Expects $view (array) from Sitelemetry_Audit_Admin::render_renewal_notice().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice notice-error sitelemetry-audit-renewal-notice">
	<p>
		<strong>
			<?php
			printf(
				/* translators: %s: host name of this site. */
				esc_html__( 'Sitelemetry could not renew the ownership verification of %s automatically.', 'sitelemetry-audit' ),
				esc_html( $view['host'] )
			);
			?>
		</strong>
		<?php echo esc_html( $view['message'] ); ?>
	</p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( $view['verification_url'] ); ?>"><?php esc_html_e( 'Verify this site', 'sitelemetry-audit' ); ?></a>
		<a class="button-link" href="<?php echo esc_url( $view['dismiss_url'] ); ?>"><?php esc_html_e( 'Dismiss', 'sitelemetry-audit' ); ?></a>
	</p>
</div>
