<?php
/**
 * Results tab.
 *
 * Expects $view (array) from Sitelemetry_Audit_Admin::render_results().
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitelemetry_audit_model    = $view['model'];
$sitelemetry_audit_measured = $sitelemetry_audit_model && in_array( $sitelemetry_audit_model['status'], array( 'completed', 'partial' ), true );
?>
<?php if ( count( $view['results'] ) > 1 ) : ?>
	<ul class="subsubsub sitelemetry-audit-kinds">
		<?php $sitelemetry_audit_i = 0; ?>
		<?php foreach ( $view['results'] as $sitelemetry_audit_kind => $sitelemetry_audit_stored ) : ?>
			<li>
				<?php echo $sitelemetry_audit_i++ > 0 ? '| ' : ''; ?>
				<a href="<?php echo esc_url( Sitelemetry_Audit_Admin::results_url( $sitelemetry_audit_kind ) ); ?>" class="<?php echo $sitelemetry_audit_kind === $view['kind'] ? 'current' : ''; ?>"><?php echo esc_html( Sitelemetry_Audit_Labels::kind_label( $sitelemetry_audit_kind ) ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="clear"></div>
<?php endif; ?>

<?php if ( $view['job'] ) : ?>
	<div id="sitelemetry-audit-progress" class="sitelemetry-audit-card sitelemetry-audit-progress" data-state="running" data-retry-after="<?php echo esc_attr( $view['progress']['retry_after_ms'] ); ?>" data-elapsed="<?php echo esc_attr( $view['progress']['elapsed'] ); ?>" data-budget="<?php echo esc_attr( $view['progress']['budget'] ); ?>">
		<h2>
			<span class="sitelemetry-audit-spinner" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: 1: audit kind label, 2: target URL. */
				esc_html__( 'Running the %1$s audit of %2$s', 'sitelemetry-audit' ),
				esc_html( Sitelemetry_Audit_Labels::kind_label( $view['job']['kind'] ) ),
				'<code>' . esc_html( $view['job']['target'] ) . '</code>'
			);
			?>
		</h2>
		<p class="sitelemetry-audit-phase"><?php echo esc_html( '' !== $view['job']['phase'] ? $view['job']['phase'] : __( 'Starting the audit…', 'sitelemetry-audit' ) ); ?></p>
		<p class="sitelemetry-audit-elapsed">
			<?php
			printf(
				/* translators: 1: elapsed time (m:ss), 2: time budget in minutes. */
				esc_html__( 'Elapsed: %1$s (time budget: %2$d minutes)', 'sitelemetry-audit' ),
				esc_html( sprintf( '%d:%02d', floor( $view['progress']['elapsed'] / 60 ), $view['progress']['elapsed'] % 60 ) ),
				(int) ceil( $view['progress']['budget'] / 60 )
			);
			?>
		</p>
		<p class="sitelemetry-audit-progress-error notice notice-error inline" hidden></p>
		<p>
			<a class="button" href="<?php echo esc_url( $view['poll_url'] ); ?>"><?php esc_html_e( 'Check now', 'sitelemetry-audit' ); ?></a>
			<a class="button-link button-link-delete" href="<?php echo esc_url( $view['cancel_url'] ); ?>"><?php esc_html_e( 'Stop waiting', 'sitelemetry-audit' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( 'Polling a running audit does not use more allowance. If you leave this page, WordPress keeps checking in the background and the result appears here when it is ready.', 'sitelemetry-audit' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! $sitelemetry_audit_model && ! $view['job'] ) : ?>
	<div class="sitelemetry-audit-card">
		<h2><?php esc_html_e( 'No audit has run yet', 'sitelemetry-audit' ); ?></h2>
		<?php if ( $view['has_key'] ) : ?>
			<form method="post" action="<?php echo esc_url( $view['run_action'] ); ?>" class="sitelemetry-audit-run">
				<input type="hidden" name="action" value="sitelemetry_audit_run">
				<input type="hidden" name="kind" value="<?php echo esc_attr( $view['settings']['kind'] ); ?>">
				<?php wp_nonce_field( 'sitelemetry_audit_run' ); ?>
				<p>
					<?php
					printf(
						/* translators: 1: audit kind label, 2: target URL. */
						esc_html__( 'Run the %1$s audit of %2$s. A completed audit uses one unit of the monthly allowance.', 'sitelemetry-audit' ),
						'<strong>' . esc_html( Sitelemetry_Audit_Labels::kind_label( $view['settings']['kind'] ) ) . '</strong>',
						'<code>' . esc_html( $view['settings']['target'] ) . '</code>'
					);
					?>
				</p>
				<?php submit_button( __( 'Run audit', 'sitelemetry-audit' ), 'primary', 'sitelemetry_audit_run', false ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'Add your Sitelemetry API key to run the first audit.', 'sitelemetry-audit' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $view['settings_url'] ); ?>"><?php esc_html_e( 'Open settings', 'sitelemetry-audit' ); ?></a></p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $sitelemetry_audit_model ) : ?>
	<div class="sitelemetry-audit-banner sitelemetry-audit-banner--<?php echo esc_attr( $sitelemetry_audit_model['status'] ); ?>">
		<h2><?php echo esc_html( $view['status_heading'] ); ?></h2>
		<p class="sitelemetry-audit-meta">
			<span><?php echo esc_html( Sitelemetry_Audit_Labels::kind_label( $sitelemetry_audit_model['kind'] ) ); ?></span>
			<span><code><?php echo esc_html( $sitelemetry_audit_model['target'] ); ?></code></span>
			<?php if ( ! empty( $sitelemetry_audit_model['started_at'] ) ) : ?>
				<span>
					<?php
					printf(
						/* translators: %s: date and time. */
						esc_html__( 'Started %s', 'sitelemetry-audit' ),
						esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_model['started_at'] ) )
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $sitelemetry_audit_model['finished_at'] ) ) : ?>
				<span>
					<?php
					printf(
						/* translators: %s: date and time. */
						esc_html__( 'Finished %s', 'sitelemetry-audit' ),
						esc_html( Sitelemetry_Audit_Admin::format_time( $sitelemetry_audit_model['finished_at'] ) )
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( isset( $sitelemetry_audit_model['origin'] ) && 'scheduled' === $sitelemetry_audit_model['origin'] ) : ?>
				<span><?php esc_html_e( 'Weekly schedule', 'sitelemetry-audit' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $sitelemetry_audit_model['job_id'] ) ) : ?>
				<span>
					<?php
					printf(
						/* translators: %s: audit job id. */
						esc_html__( 'Job %s', 'sitelemetry-audit' ),
						'<code>' . esc_html( $sitelemetry_audit_model['job_id'] ) . '</code>'
					);
					?>
				</span>
			<?php endif; ?>
		</p>
		<?php if ( '' !== $view['reason_lead'] ) : ?>
			<p><?php echo esc_html( $view['reason_lead'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! $sitelemetry_audit_measured && '' !== $sitelemetry_audit_model['message'] ) : ?>
			<blockquote class="sitelemetry-audit-message"><?php echo nl2br( esc_html( $sitelemetry_audit_model['message'] ) ); ?></blockquote>
		<?php endif; ?>
		<?php if ( 'unauthorized' === $sitelemetry_audit_model['reason'] ) : ?>
			<p><a href="<?php echo esc_url( $view['settings_url'] ); ?>"><?php esc_html_e( 'Open settings', 'sitelemetry-audit' ); ?></a></p>
		<?php endif; ?>
		<?php if ( ! empty( $view['plan_box']['verification'] ) ) : ?>
			<p>
				<?php echo esc_html( $view['plan_box']['verification'] ); ?>
				<a href="<?php echo esc_url( $view['plan_box']['app_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the app', 'sitelemetry-audit' ); ?></a>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $sitelemetry_audit_model['report_url'] ) ) : ?>
			<p><a class="button" href="<?php echo esc_url( $sitelemetry_audit_model['report_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the full report', 'sitelemetry-audit' ); ?></a></p>
		<?php endif; ?>
	</div>

	<?php if ( $sitelemetry_audit_measured ) : ?>
		<div class="sitelemetry-audit-summary">
			<div class="sitelemetry-audit-card sitelemetry-audit-score">
				<?php if ( null !== $sitelemetry_audit_model['score'] ) : ?>
					<span class="sitelemetry-audit-score-value"><?php echo esc_html( $sitelemetry_audit_model['score'] ); ?></span>
					<span class="sitelemetry-audit-score-max">/100</span>
					<?php if ( ! empty( $sitelemetry_audit_model['grade'] ) ) : ?>
						<span class="sitelemetry-audit-grade"><?php echo esc_html( $sitelemetry_audit_model['grade'] ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<span class="sitelemetry-audit-score-value sitelemetry-audit-score-value--none"><?php esc_html_e( 'Not measured', 'sitelemetry-audit' ); ?></span>
				<?php endif; ?>
				<span class="sitelemetry-audit-score-label"><?php esc_html_e( 'Score', 'sitelemetry-audit' ); ?></span>
			</div>
			<div class="sitelemetry-audit-card sitelemetry-audit-counts">
				<h3>
					<?php
					printf(
						/* translators: %d: number of findings. */
						esc_html( _n( '%d finding', '%d findings', (int) $sitelemetry_audit_model['total'], 'sitelemetry-audit' ) ),
						(int) $sitelemetry_audit_model['total']
					);
					?>
				</h3>
				<p>
					<?php foreach ( $view['severities'] as $sitelemetry_audit_severity => $sitelemetry_audit_label ) : ?>
						<?php if ( ! empty( $sitelemetry_audit_model['counts'][ $sitelemetry_audit_severity ] ) ) : ?>
							<span class="sitelemetry-audit-chip sitelemetry-audit-chip--<?php echo esc_attr( $sitelemetry_audit_severity ); ?>"><?php echo esc_html( $sitelemetry_audit_model['counts'][ $sitelemetry_audit_severity ] . ' ' . $sitelemetry_audit_label ); ?></span>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( 0 === (int) $sitelemetry_audit_model['total'] ) : ?>
						<span class="sitelemetry-audit-chip sitelemetry-audit-chip--none"><?php esc_html_e( 'No findings were reported for the measured checks', 'sitelemetry-audit' ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $sitelemetry_audit_model['passing_checks'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of passing checks. */
							esc_html( _n( '%d passing check', '%d passing checks', (int) $sitelemetry_audit_model['passing_checks'], 'sitelemetry-audit' ) ),
							(int) $sitelemetry_audit_model['passing_checks']
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! empty( $sitelemetry_audit_model['pillars'] ) ) : ?>
			<table class="widefat striped sitelemetry-audit-pillars">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Pillar', 'sitelemetry-audit' ); ?></th>
						<th><?php esc_html_e( 'Score', 'sitelemetry-audit' ); ?></th>
						<th><?php esc_html_e( 'Findings', 'sitelemetry-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sitelemetry_audit_model['pillars'] as $sitelemetry_audit_name => $sitelemetry_audit_pillar ) : ?>
						<tr>
							<td><?php echo esc_html( $sitelemetry_audit_name ); ?></td>
							<td><?php echo esc_html( null === $sitelemetry_audit_pillar['score'] ? __( 'n/a', 'sitelemetry-audit' ) : $sitelemetry_audit_pillar['score'] ); ?></td>
							<td><?php echo esc_html( $sitelemetry_audit_pillar['findings'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( 'partial' === $sitelemetry_audit_model['status'] ) : ?>
			<div class="sitelemetry-audit-card sitelemetry-audit-not-measured">
				<h3><?php esc_html_e( 'What was not measured', 'sitelemetry-audit' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Unmeasured checks are not passes.', 'sitelemetry-audit' ); ?></p>
				<ul>
					<?php if ( count( $view['not_measured'] ) > 0 ) : ?>
						<?php foreach ( $view['not_measured'] as $sitelemetry_audit_line ) : ?>
							<li><?php echo esc_html( $sitelemetry_audit_line ); ?></li>
						<?php endforeach; ?>
					<?php else : ?>
						<li><?php esc_html_e( 'Some requested measurements were unavailable; the full result in the app lists them.', 'sitelemetry-audit' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( count( $sitelemetry_audit_model['findings'] ) > 0 ) : ?>
			<?php
			$sitelemetry_audit_order    = array_flip( Sitelemetry_Audit_Labels::severities() );
			$sitelemetry_audit_findings = $sitelemetry_audit_model['findings'];
			usort(
				$sitelemetry_audit_findings,
				function ( $a, $b ) use ( $sitelemetry_audit_order ) {
					$left  = isset( $sitelemetry_audit_order[ $a['severity'] ] ) ? $sitelemetry_audit_order[ $a['severity'] ] : 99;
					$right = isset( $sitelemetry_audit_order[ $b['severity'] ] ) ? $sitelemetry_audit_order[ $b['severity'] ] : 99;
					return $left - $right;
				}
			);
			?>
			<div class="sitelemetry-audit-findings-header">
				<h3><?php esc_html_e( 'Findings', 'sitelemetry-audit' ); ?></h3>
				<label for="sitelemetry-audit-severity-filter">
					<?php esc_html_e( 'Severity', 'sitelemetry-audit' ); ?>
					<select id="sitelemetry-audit-severity-filter">
						<option value=""><?php esc_html_e( 'All severities', 'sitelemetry-audit' ); ?></option>
						<?php foreach ( $view['severities'] as $sitelemetry_audit_severity => $sitelemetry_audit_label ) : ?>
							<option value="<?php echo esc_attr( $sitelemetry_audit_severity ); ?>"><?php echo esc_html( $sitelemetry_audit_label . ' (' . (int) $sitelemetry_audit_model['counts'][ $sitelemetry_audit_severity ] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<table class="widefat striped sitelemetry-audit-findings" id="sitelemetry-audit-findings">
				<thead>
					<tr>
						<th class="sitelemetry-audit-col-severity"><?php esc_html_e( 'Severity', 'sitelemetry-audit' ); ?></th>
						<th><?php esc_html_e( 'Finding', 'sitelemetry-audit' ); ?></th>
						<th class="sitelemetry-audit-col-location"><?php esc_html_e( 'Location', 'sitelemetry-audit' ); ?></th>
						<th class="sitelemetry-audit-col-fix"><?php esc_html_e( 'Fix', 'sitelemetry-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sitelemetry_audit_findings as $sitelemetry_audit_finding ) : ?>
						<tr data-severity="<?php echo esc_attr( $sitelemetry_audit_finding['severity'] ); ?>">
							<td><span class="sitelemetry-audit-chip sitelemetry-audit-chip--<?php echo esc_attr( $sitelemetry_audit_finding['severity'] ); ?>"><?php echo esc_html( Sitelemetry_Audit_Labels::severity_label( $sitelemetry_audit_finding['severity'] ) ); ?></span></td>
							<td>
								<strong><?php echo esc_html( $sitelemetry_audit_finding['title'] ); ?></strong>
								<?php if ( '' !== $sitelemetry_audit_finding['pillar'] || '' !== $sitelemetry_audit_finding['category'] ) : ?>
									<span class="sitelemetry-audit-tag"><?php echo esc_html( trim( $sitelemetry_audit_finding['pillar'] . ' ' . $sitelemetry_audit_finding['category'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $sitelemetry_audit_finding['evidence'] || '' !== $sitelemetry_audit_finding['impact'] ) : ?>
									<details>
										<summary><?php esc_html_e( 'Evidence and impact', 'sitelemetry-audit' ); ?></summary>
										<?php if ( '' !== $sitelemetry_audit_finding['evidence'] ) : ?>
											<p><?php echo esc_html( $sitelemetry_audit_finding['evidence'] ); ?></p>
										<?php endif; ?>
										<?php if ( '' !== $sitelemetry_audit_finding['impact'] ) : ?>
											<p><?php echo esc_html( $sitelemetry_audit_finding['impact'] ); ?></p>
										<?php endif; ?>
									</details>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== $sitelemetry_audit_finding['location'] ? '<code>' . esc_html( $sitelemetry_audit_finding['location'] ) . '</code>' : '&ndash;'; ?></td>
							<td><?php echo '' !== $sitelemetry_audit_finding['fix'] ? esc_html( $sitelemetry_audit_finding['fix'] ) : '&ndash;'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="sitelemetry-audit-no-matches description" hidden><?php esc_html_e( 'No findings match this filter.', 'sitelemetry-audit' ); ?></p>
			<?php if ( $sitelemetry_audit_model['truncated'] ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: number of findings shown, 2: total number of findings. */
						esc_html__( '%1$d of %2$d findings are shown; the full report in the app lists all of them.', 'sitelemetry-audit' ),
						(int) $sitelemetry_audit_model['returned_findings'],
						(int) $sitelemetry_audit_model['total']
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $sitelemetry_audit_model || $view['job'] ) : ?>
	<?php require SITELEMETRY_AUDIT_DIR . 'admin/views/plan-box.php'; ?>
<?php endif; ?>

<?php if ( $sitelemetry_audit_model && ! $view['job'] && $view['has_key'] ) : ?>
	<form method="post" action="<?php echo esc_url( $view['run_action'] ); ?>" class="sitelemetry-audit-run sitelemetry-audit-run--again">
		<input type="hidden" name="action" value="sitelemetry_audit_run">
		<input type="hidden" name="kind" value="<?php echo esc_attr( $sitelemetry_audit_model['kind'] ); ?>">
		<?php wp_nonce_field( 'sitelemetry_audit_run' ); ?>
		<p>
			<?php
			submit_button(
				/* translators: %s: audit kind label. */
				sprintf( __( 'Run the %s audit again', 'sitelemetry-audit' ), Sitelemetry_Audit_Labels::kind_label( $sitelemetry_audit_model['kind'] ) ),
				'secondary',
				'sitelemetry_audit_run',
				false
			);
			?>
			<a class="button-link" href="<?php echo esc_url( $view['settings_url'] ); ?>"><?php esc_html_e( 'Change settings', 'sitelemetry-audit' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( 'Uses the target saved in the settings. A completed audit uses one unit of the monthly allowance.', 'sitelemetry-audit' ); ?></p>
	</form>
<?php endif; ?>

<p class="description sitelemetry-audit-footnote"><?php esc_html_e( 'Only the target URL and the audit options are sent to Sitelemetry, authenticated with your API key.', 'sitelemetry-audit' ); ?></p>
