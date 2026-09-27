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
				<?php else : ?>
					<div class="sitelemetry-audit-onboarding">
						<p><?php esc_html_e( 'Sign in to Sitelemetry, or create an account on the same page: Sitelemetry has free and paid plans, and the Free plan includes the security audit. Then copy your API key and paste it below.', 'sitelemetry-audit' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $view['sign_in_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Sign in or create an account', 'sitelemetry-audit' ); ?></a>
							<a class="sitelemetry-audit-api-key-link" href="<?php echo esc_url( $view['api_key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get your API key', 'sitelemetry-audit' ); ?></a>
						</p>
					</div>
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
					<?php esc_html_e( 'In the app, open My account and expand Manual connection and API key to copy the API key. The key is stored in the WordPress options table and is sent only to sitelemetry.com.', 'sitelemetry-audit' ); ?>
					<?php if ( $view['has_key'] ) : ?>
						<a href="<?php echo esc_url( $view['api_key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get your API key', 'sitelemetry-audit' ); ?></a>
					<?php endif; ?>
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
					<?php foreach ( $view['kinds'] as $sitelemetry_audit_kind => $sitelemetry_audit_label ) : ?>
						<option value="<?php echo esc_attr( $sitelemetry_audit_kind ); ?>" <?php selected( $view['settings']['kind'], $sitelemetry_audit_kind ); ?>><?php echo esc_html( $sitelemetry_audit_label ); ?></option>
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

<?php $sitelemetry_audit_verify = $view['verification']; ?>
<h2 id="sitelemetry-audit-verify" tabindex="-1"><?php esc_html_e( 'Verify this site', 'sitelemetry-audit' ); ?></h2>
<div class="sitelemetry-audit-card sitelemetry-audit-verify">
	<p><?php esc_html_e( 'Some security checks (for example exposed files, HTTP methods and, on paid plans, the WordPress checks) run only on sites whose ownership is verified in Sitelemetry. The plugin can publish the verification file for this site, so you need no FTP access or hosting panel.', 'sitelemetry-audit' ); ?></p>
	<p>
		<?php
		printf(
			/* translators: %s: host name of this site. */
			esc_html__( 'Host of this site: %s', 'sitelemetry-audit' ),
			'<code>' . esc_html( $sitelemetry_audit_verify['host'] ) . '</code>'
		);
		?>
	</p>
	<?php if ( '' !== $sitelemetry_audit_verify['www_host'] ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: host name of this site, 2: the same host with or without www. */
				esc_html__( 'Sitelemetry verifies one exact host name: %1$s and %2$s are different hosts. Add the host of the address the audit uses; a redirect from one to the other makes the file check fail.', 'sitelemetry-audit' ),
				'<code>' . esc_html( $sitelemetry_audit_verify['host'] ) . '</code>',
				'<code>' . esc_html( $sitelemetry_audit_verify['www_host'] ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $sitelemetry_audit_verify['target_host'] && $sitelemetry_audit_verify['target_host'] !== $sitelemetry_audit_verify['host'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %s: host name of the audit target. */
					esc_html__( 'The audit target in the settings is a different host (%s). The plugin publishes the file only for this site\'s own host; verify the target in the app.', 'sitelemetry-audit' ),
					'<code>' . esc_html( $sitelemetry_audit_verify['target_host'] ) . '</code>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php foreach ( $sitelemetry_audit_verify['warnings'] as $sitelemetry_audit_warning ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $sitelemetry_audit_warning ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( ! $sitelemetry_audit_verify['can_publish'] ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'On a multisite network only a network administrator can publish the verification file: the web server that answers for this host serves every site of the network. Ask a network administrator to verify this site, or use one of the other ways below.', 'sitelemetry-audit' ); ?></p>
		</div>
	<?php else : ?>
		<?php $sitelemetry_audit_one_click = in_array( $sitelemetry_audit_verify['one_click'], array( 'on', 'unknown' ), true ); ?>
		<div class="sitelemetry-audit-one-click">
			<?php if ( '' !== $sitelemetry_audit_verify['verified_text'] ) : ?>
				<div class="notice notice-success inline sitelemetry-audit-verified" role="status"><p><?php echo esc_html( $sitelemetry_audit_verify['verified_text'] ); ?></p></div>
			<?php elseif ( $sitelemetry_audit_verify['stale'] && $sitelemetry_audit_verify['renewal_off'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: host name. */
							esc_html__( 'The ownership verification of %s has expired. Verification lasts 30 days and is not renewed automatically: click Reverify in the app, then publish the new token and verify again.', 'sitelemetry-audit' ),
							esc_html( $sitelemetry_audit_verify['host'] )
						);
						?>
					</p>
				</div>
			<?php elseif ( $sitelemetry_audit_verify['stale'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The verification of this site has expired. The plugin renews it automatically; click Verify this site to renew it now.', 'sitelemetry-audit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $sitelemetry_audit_verify['failure_text'] ) : ?>
				<div class="notice notice-error inline sitelemetry-audit-one-click-result" role="alert">
					<p>
						<strong><?php echo esc_html( $sitelemetry_audit_verify['failure_renewal'] ? __( 'The automatic renewal did not complete.', 'sitelemetry-audit' ) : __( 'The verification did not complete.', 'sitelemetry-audit' ) ); ?></strong>
						<?php echo esc_html( $sitelemetry_audit_verify['failure_text'] ); ?>
						<?php if ( $sitelemetry_audit_verify['failure_key'] ) : ?>
							<a href="<?php echo esc_url( $sitelemetry_audit_verify['api_key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get your API key', 'sitelemetry-audit' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'You can also verify with a DNS record or Google Search Console, as described under Other ways to verify.', 'sitelemetry-audit' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( 'off' === $sitelemetry_audit_verify['one_click'] ) : ?>
				<p class="description sitelemetry-audit-one-click-note"><?php esc_html_e( 'One-click verification is not available yet. Verify with a token from the app as described below.', 'sitelemetry-audit' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $sitelemetry_audit_verify['action'] ); ?>" class="sitelemetry-audit-one-click-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( $sitelemetry_audit_verify['one_click_action'] ); ?>">
					<?php wp_nonce_field( 'sitelemetry_audit_verification_one_click' ); ?>
					<p>
						<?php
						$sitelemetry_audit_button = array( 'aria-describedby' => 'sitelemetry-audit-one-click-hint' );
						if ( 'no_key' === $sitelemetry_audit_verify['one_click'] ) {
							$sitelemetry_audit_button['disabled'] = 'disabled';
						}
						submit_button( __( 'Verify this site', 'sitelemetry-audit' ), 'primary', 'sitelemetry_audit_one_click', false, $sitelemetry_audit_button );
						?>
					</p>
					<p class="description" id="sitelemetry-audit-one-click-hint">
						<?php if ( 'no_key' === $sitelemetry_audit_verify['one_click'] ) : ?>
							<?php esc_html_e( 'Save your API key above to verify this site with one click.', 'sitelemetry-audit' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: URL of the verification file, 2: number of days a verification lasts. */
								esc_html__( 'The plugin asks Sitelemetry for a verification code, publishes it at %1$s, tests the file and asks Sitelemetry to verify it. A verification lasts %2$d days; the plugin renews it automatically.', 'sitelemetry-audit' ),
								'<code>' . esc_html( $sitelemetry_audit_verify['file_url'] ) . '</code>',
								(int) $sitelemetry_audit_verify['renewal_days']
							);
							?>
						<?php endif; ?>
					</p>
				</form>
			<?php endif; ?>
		</div>

		<?php if ( $sitelemetry_audit_one_click ) : ?>
			<details class="sitelemetry-audit-manual"<?php echo $sitelemetry_audit_verify['manual_open'] ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Verify manually with a token from the app', 'sitelemetry-audit' ); ?></summary>
		<?php endif; ?>
		<ol class="sitelemetry-audit-steps">
			<li>
				<?php
				printf(
					/* translators: %s: host name of this site. */
					esc_html__( 'In the app, open Verified Domains, enter exactly %s and click Start verification.', 'sitelemetry-audit' ),
					'<code>' . esc_html( $sitelemetry_audit_verify['host'] ) . '</code>'
				);
				?>
				<a href="<?php echo esc_url( $sitelemetry_audit_verify['app_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Verified Domains in the app', 'sitelemetry-audit' ); ?></a>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: URL of the verification file. */
					esc_html__( 'Copy the verification code shown under HTTP verification file (it starts with sitelemetry-) and save it below as the token. The plugin then answers %s with the token.', 'sitelemetry-audit' ),
					'<code>' . esc_html( $sitelemetry_audit_verify['file_url'] ) . '</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Click Test the file to check that the file is reachable.', 'sitelemetry-audit' ); ?></li>
			<li><?php esc_html_e( 'In the app, click Verify HTTP for this domain, then run the audit again.', 'sitelemetry-audit' ); ?></li>
		</ol>

		<form method="post" action="<?php echo esc_url( $sitelemetry_audit_verify['action'] ); ?>" class="sitelemetry-audit-token-form">
			<input type="hidden" name="action" value="sitelemetry_audit_verification_save">
			<?php wp_nonce_field( 'sitelemetry_audit_verification_save' ); ?>
			<p>
				<label for="sitelemetry-audit-token"><?php esc_html_e( 'Verification token', 'sitelemetry-audit' ); ?></label><br>
				<?php if ( $sitelemetry_audit_verify['token_error'] ) : ?>
					<input type="text" id="sitelemetry-audit-token" name="sitelemetry_audit_token" value="" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="sitelemetry-…" aria-invalid="true" aria-describedby="sitelemetry-audit-token-error">
				<?php else : ?>
					<input type="text" id="sitelemetry-audit-token" name="sitelemetry_audit_token" value="" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="sitelemetry-…">
				<?php endif; ?>
				<?php submit_button( __( 'Save token', 'sitelemetry-audit' ), 'secondary', 'sitelemetry_audit_save_token', false ); ?>
				<?php if ( '' !== $sitelemetry_audit_verify['token'] ) : ?>
					<?php submit_button( __( 'Remove token', 'sitelemetry-audit' ), 'delete', 'sitelemetry_audit_remove_token', false ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $sitelemetry_audit_verify['token_error'] ) : ?>
				<div class="notice notice-error inline sitelemetry-audit-token-error" role="alert">
					<p id="sitelemetry-audit-token-error"><?php esc_html_e( 'That is not a Sitelemetry verification token. Copy the whole token shown under HTTP verification file in the Verified Domains section of the app; it starts with sitelemetry- and has 32 more characters.', 'sitelemetry-audit' ); ?></p>
				</div>
			<?php endif; ?>
		</form>
		<?php if ( $sitelemetry_audit_one_click ) : ?>
			</details>
		<?php endif; ?>

		<?php if ( '' !== $sitelemetry_audit_verify['token'] ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: verification token, 2: date and time. */
					esc_html__( 'Stored token: %1$s (saved %2$s).', 'sitelemetry-audit' ),
					'<code>' . esc_html( $sitelemetry_audit_verify['token'] ) . '</code>',
					esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_verify['saved_at'] ) )
				);
				?>
				<?php if ( $sitelemetry_audit_verify['served'] ) : ?>
					<a href="<?php echo esc_url( $sitelemetry_audit_verify['site_file_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the file', 'sitelemetry-audit' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! $sitelemetry_audit_verify['served'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: host name the token was saved for. */
							esc_html__( 'The file is not published: the token was saved for %s, which is not this site\'s current host. Add the current host in the app and save its token.', 'sitelemetry-audit' ),
							'<code>' . esc_html( $sitelemetry_audit_verify['stored_host'] ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $sitelemetry_audit_verify['action'] ); ?>">
				<input type="hidden" name="action" value="sitelemetry_audit_verification_test">
				<?php wp_nonce_field( 'sitelemetry_audit_verification_test' ); ?>
				<p><?php submit_button( __( 'Test the file', 'sitelemetry-audit' ), 'secondary', 'sitelemetry_audit_test_file', false ); ?></p>
			</form>
			<?php if ( $sitelemetry_audit_verify['last_test'] ) : ?>
				<div class="notice notice-<?php echo esc_attr( $sitelemetry_audit_verify['last_test']['ok'] ? 'success' : 'error' ); ?> inline sitelemetry-audit-test-result">
					<p><?php echo esc_html( $sitelemetry_audit_verify['last_message'] ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date and time. */
							esc_html__( 'Tested %s.', 'sitelemetry-audit' ),
							esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_verify['last_test']['at'] ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Renewal', 'sitelemetry-audit' ); ?></h3>
		<p>
			<?php // After Remove token the plugin does not renew by itself until the next click on Verify this site. ?>
			<?php if ( $sitelemetry_audit_one_click && ! $sitelemetry_audit_verify['renewal_off'] ) : ?>
				<?php
				printf(
					/* translators: %d: number of days a verification lasts. */
					esc_html__( 'A verification lasts %d days. The plugin renews it automatically in the background (WP-Cron) when it expires, or when an audit reports that it must be renewed, and shows a notice only if the renewal fails. WP-Cron runs when the site receives visits unless a system cron triggers it.', 'sitelemetry-audit' ),
					(int) $sitelemetry_audit_verify['renewal_days']
				);
				?>
			<?php else : ?>
				<?php
				printf(
					/* translators: %d: number of days a verification lasts. */
					esc_html__( 'A verification lasts %d days and Sitelemetry does not re-check it by itself. Before it expires, click Reverify for this domain in the app, save the new token here and click Verify HTTP again. When an audit reports that the verification must be renewed, the results page and the dashboard widget remind you.', 'sitelemetry-audit' ),
					(int) $sitelemetry_audit_verify['renewal_days']
				);
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Other ways to verify', 'sitelemetry-audit' ); ?></h3>
	<ul class="sitelemetry-audit-alternatives">
		<?php if ( '' !== $sitelemetry_audit_verify['dns_name'] ) : ?>
			<li>
				<?php
				printf(
					/* translators: %s: DNS record name. */
					esc_html__( 'DNS record: add a TXT record named %s whose value is the token, then click Verify DNS in the app. This works when the file cannot be served, for example for a subdirectory install or a server that handles /.well-known/ itself.', 'sitelemetry-audit' ),
					'<code>' . esc_html( $sitelemetry_audit_verify['dns_name'] ) . '</code>'
				);
				?>
			</li>
		<?php endif; ?>
		<li><?php esc_html_e( 'Google Search Console: in the app, connect the Google account that owns the site\'s Search Console property and synchronize the properties. A Domain property covers the host and its subdomains; a URL-prefix property must be the root of the host for the host-level checks.', 'sitelemetry-audit' ); ?></li>
	</ul>
	<p><a href="<?php echo esc_url( $sitelemetry_audit_verify['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How verification and renewal work', 'sitelemetry-audit' ); ?></a></p>
</div>
