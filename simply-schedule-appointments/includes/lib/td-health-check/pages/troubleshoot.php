<?php
/**
 * The Troubleshooting tab contents.
 *
 * @package Health Check
 */

// Make sure the file is not directly accessible.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'We\'re sorry, but you can not directly access this file.' );  // phpcs:ignore
}

?>
<div class="notice notice-warning inline">
	<p>
		<?php esc_html_e( 'When troubleshooting issues on your site, you are likely to be told to disable all plugins and switch to the default theme.', 'simply-schedule-appointments' ); ?>
		<?php esc_html_e( 'Understandably, you do not wish to do so as it may affect your site visitors, leaving them with lost functionality.', 'simply-schedule-appointments' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'By enabling the Troubleshooting Mode, all plugins will appear inactive and your site will switch to the default theme only for you. All other users will see your site as usual.', 'simply-schedule-appointments' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'A Troubleshooting Mode menu is added to your admin bar, which will allow you to enable plugins individually, switch back to your current theme, and disable Troubleshooting Mode.', 'simply-schedule-appointments' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Please note, that due to how Must Use plugins work, any such plugin will not be disabled for the troubleshooting session.', 'simply-schedule-appointments' ); ?>
	</p>
</div>

<?php
TD_Health_Check_Troubleshoot::show_enable_troubleshoot_form();

if ( ! TD_Health_Check_Troubleshoot::has_seen_warning() ) {
	include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/modals/backup-warning.php' );
}
