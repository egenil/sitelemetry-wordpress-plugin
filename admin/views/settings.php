<?php
/**
 * Settings tab.
 *
 * Expects $view (array) from Sitelemetry_Audit_Admin::render_settings().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( $view['job'] ) : ?>
	<div class="notice notice-info">
		<p>
			<?php esc_html_e( 'An audit is running.', 'sitelemetry-audit' ); ?>
			<a href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'View progress', 'sitelemetry-audit' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<form method="post" action="options.php" class="sitelemetry-audit-form">
	<?php settings_fields( $view['option_group'] ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sitelemetry-audit-api-key"><?php esc_html_e( 'API key', 'sitelemetry-audit' ); ?></label></th>
			<td>
				<?php if ( $view['has_key'] ) : ?>
					<p><code class="sitelemetry-audit-masked"><?php echo esc_html( $view['masked_key'] ); ?></code> <span class="description"><?php esc_html_e( 'A key is stored.', 'sitelemetry-audit' ); ?></span></p>
				<?php endif; ?>
				<input type="password" id="sitelemetry-audit-api-key" name="<?php echo esc_attr( $view['option_name'] ); ?>[api_key]" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $view['has_key'] ? __( 'Enter a new key to replace the stored one', 'sitelemetry-audit' ) : __( 'Paste your Sitelemetry MCP API key', 'sitelemetry-audit' ) ); ?>">
				<?php if ( $view['has_key'] ) : ?>
					<p>
						<label>
							<input type="checkbox" id="sitelemetry-audit-remove-key" name="<?php echo esc_attr( $view['option_name'] ); ?>[remove_api_key]" value="1">
							<?php esc_html_e( 'Remove the stored key', 'sitelemetry-audit' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the Sitelemetry app. */
						esc_html__( 'Sign in at %s (a Free account is enough), open API key and copy the MCP API key. The key is stored in the WordPress options table and is sent only to sitelemetry.com.', 'sitelemetry-audit' ),
						'<a href="' . esc_url( $view['app_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $view['app_url'] ) . '</a>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sitelemetry-audit-target"><?php esc_html_e( 'Target', 'sitelemetry-audit' ); ?></label></th>
			<td>
				<input type="url" id="sitelemetry-audit-target" name="<?php echo esc_attr( $view['option_name'] ); ?>[target]" value="<?php echo esc_attr( $view['settings']['target'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Defaults to this site\'s address. Audit only websites you own or are authorized to test; every audit is performed by Sitelemetry against the live target and recorded on the connected account.', 'sitelemetry-audit' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sitelemetry-audit-kind"><?php esc_html_e( 'Audit kind', 'sitelemetry-audit' ); ?></label></th>
			<td>
				<select id="sitelemetry-audit-kind" name="<?php echo esc_attr( $view['option_name'] ); ?>[kind]">
					<?php foreach ( $view['kinds'] as $kind => $label ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $view['settings']['kind'], $kind ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'The security audit is included in every plan. Other audit kinds need a paid plan; when the connected account does not include one, the audit is not started and no allowance is used.', 'sitelemetry-audit' ); ?>
					<a href="<?php echo esc_url( $view['pricing_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Compare plans', 'sitelemetry-audit' ); ?></a>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Weekly audit', 'sitelemetry-audit' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $view['option_name'] ); ?>[weekly_enabled]" value="1" <?php checked( $view['settings']['weekly_enabled'] ); ?>>
					<?php esc_html_e( 'Run the selected audit once a week in the background (WP-Cron)', 'sitelemetry-audit' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Each completed audit uses one unit of the monthly allowance of the connected account. WP-Cron runs when the site receives visits unless a system cron triggers it.', 'sitelemetry-audit' ); ?>
					<?php if ( $view['weekly_next'] ) : ?>
						<?php
						printf(
							/* translators: %s: date and time. */
							esc_html__( 'Next run: %s.', 'sitelemetry-audit' ),
							esc_html( Sitelemetry_Audit_Admin::format_time( $view['weekly_next'] ) )
						);
						?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>
	<?php submit_button( __( 'Save settings', 'sitelemetry-audit' ) ); ?>
</form>

<h2><?php esc_html_e( 'Run an audit now', 'sitelemetry-audit' ); ?></h2>
<form method="post" action="<?php echo esc_url( $view['run_action'] ); ?>" class="sitelemetry-audit-run">
	<input type="hidden" name="action" value="sitelemetry_audit_run">
	<input type="hidden" name="kind" value="<?php echo esc_attr( $view['settings']['kind'] ); ?>">
	<?php wp_nonce_field( 'sitelemetry_audit_run' ); ?>
	<p>
		<?php
		printf(
			/* translators: 1: audit kind label, 2: target URL. */
			esc_html__( 'Runs the %1$s audit of %2$s with the saved settings. A completed audit uses one unit of the monthly allowance.', 'sitelemetry-audit' ),
			'<strong>' . esc_html( Sitelemetry_Audit_Labels::kind_label( $view['settings']['kind'] ) ) . '</strong>',
			'<code>' . esc_html( $view['settings']['target'] ) . '</code>'
		);
		?>
	</p>
	<?php if ( ! $view['has_key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Save an API key first.', 'sitelemetry-audit' ); ?></p>
	<?php endif; ?>
	<p>
		<?php
		$sitelemetry_audit_disabled = ( ! $view['has_key'] || $view['job'] ) ? array( 'disabled' => 'disabled' ) : array();
		submit_button( __( 'Run audit', 'sitelemetry-audit' ), 'primary', 'sitelemetry_audit_run', false, $sitelemetry_audit_disabled );
		?>
		<?php if ( $view['job'] ) : ?>
			<a class="button" href="<?php echo esc_url( $view['results_url'] ); ?>"><?php esc_html_e( 'View progress', 'sitelemetry-audit' ); ?></a>
		<?php endif; ?>
	</p>
</form>
