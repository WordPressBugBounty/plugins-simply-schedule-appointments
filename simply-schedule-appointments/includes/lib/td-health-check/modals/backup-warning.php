<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="health-check-modal" id="health-check-backup-warning" data-modal-action="" data-parent-field="">
	<div class="modal-content">
		<h2>
			<?php esc_html_e( 'Remember to keep backups', 'simply-schedule-appointments' ); ?>
		</h2>

		<p>
			<?php esc_html_e( 'Because of how Troubleshooting Mode functions, unforeseen conflicts with other plugins or themes may in rare cases occur, leading to unexpected behaviors.', 'simply-schedule-appointments' ); ?>
		</p>

		<p>
			<?php echo wp_kses_post( __( 'We therefore strongly recommend <a href="https://codex.wordpress.org/WordPress_Backups">making a backup of your site</a> before you enable troubleshooting mode.', 'simply-schedule-appointments' ) ); ?>
		</p>

		<p>
			<?php echo wp_kses_post( __( 'Additionally, since we really want to make this plugin as safe as possible, if you should have any problems with the troubleshooting mode, please create a new topic in the <a href="https://wordpress.org/support/plugin/health-check">plugins support forum</a> with details about what theme and what plugins you’re using and the steps needed to reproduce the problem. This will help us to analyze and fix such problems.', 'simply-schedule-appointments' ) ); ?>
		</p>

		<p>
			<button class="button button-primary" id="health-check-accept-backup-warning"><?php esc_html_e( 'I understand', 'simply-schedule-appointments' ); ?></button>
		</p>
	</div>
</div>

<script type="text/javascript">
	jQuery( document ).ready(function( $ ) {
		if ( 'undefined' === typeof( health_check ) || false === health_check.warning.seen_backup ) {
			$( "#health-check-backup-warning" ).show();
		}

		$( "#health-check-accept-backup-warning" ).click(function( e ) {
			$( "#health-check-backup-warning" ).hide();

			var data = {
				action: 'health-check-confirm-warning',
				warning: 'backup'
			};

			$.post(
				ajaxurl,
				data
			);
		});
	});
</script>
