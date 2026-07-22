<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * This file should be used to render each module instance.
 * You have access to two variables in this file:
 *
 * $module An instance of your module class.
 * $settings The module's settings.
 *
 * Example:
 */

?>

<div class="fl-module-ssa-upcoming-appointments-wrapper">
	<div class="ssa-upcoming-appointments">
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ssa_upcoming_appointments() is a shortcode callback that returns a rendered HTML fragment (ob_get_clean of templates/customer/upcoming-appointments.php); it is echoed as HTML and must not be run through an output escaper here, which would corrupt the markup. Escaping of the fragment's dynamic values is the template's responsibility.
		echo ssa()->shortcodes->ssa_upcoming_appointments( array(
			'no_results_message' => $settings->no_results_message
			) );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
</div>
