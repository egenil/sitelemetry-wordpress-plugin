<?php
/**
 * "Plan and usage" box: neutral facts, links to pricing and to the app.
 *
 * Expects $view['plan_box'] from Sitelemetry_Audit_Links::plan_box().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitelemetry_audit_box = $view['plan_box'];
?>
<div class="sitelemetry-audit-card sitelemetry-audit-plan">
	<h3><?php esc_html_e( 'Plan and usage', 'sitelemetry-audit' ); ?></h3>
	<p><?php echo esc_html( $sitelemetry_audit_box['lead'] ); ?></p>
	<?php if ( '' !== $sitelemetry_audit_box['remaining'] ) : ?>
		<p><strong><?php echo esc_html( $sitelemetry_audit_box['remaining'] ); ?></strong></p>
	<?php endif; ?>
	<?php if ( count( $sitelemetry_audit_box['paid'] ) > 0 ) : ?>
		<p><?php echo esc_html( $sitelemetry_audit_box['paid_intro'] ); ?></p>
		<table class="widefat striped sitelemetry-audit-plans">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plan', 'sitelemetry-audit' ); ?></th>
					<th><?php esc_html_e( 'Price', 'sitelemetry-audit' ); ?></th>
					<th><?php esc_html_e( 'Audit kinds', 'sitelemetry-audit' ); ?></th>
					<th><?php esc_html_e( 'Security modules', 'sitelemetry-audit' ); ?></th>
					<th><?php esc_html_e( 'Security scans / month', 'sitelemetry-audit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitelemetry_audit_box['paid'] as $sitelemetry_audit_row ) : ?>
					<tr>
						<td><?php echo esc_html( $sitelemetry_audit_row['label'] ); ?></td>
						<td><?php echo esc_html( $sitelemetry_audit_row['price'] ); ?></td>
						<td><?php echo esc_html( $sitelemetry_audit_row['kinds'] ); ?></td>
						<td><?php echo esc_html( $sitelemetry_audit_row['modules'] ); ?></td>
						<td><?php echo esc_html( $sitelemetry_audit_row['scans'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p>
		<a href="<?php echo esc_url( $sitelemetry_audit_box['pricing_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plan details and pricing', 'sitelemetry-audit' ); ?></a>
		&middot;
		<a href="<?php echo esc_url( $sitelemetry_audit_box['app_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verify ownership of your site, manage API keys and see full reports in the app', 'sitelemetry-audit' ); ?></a>
	</p>
</div>
