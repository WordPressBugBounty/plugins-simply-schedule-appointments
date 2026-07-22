<?php
/**
 * Appointment Booked (to Customer)
 * *
 * This template can be overridden by copying it to wp-content/themes/your-theme/ssa/notifications/email/text/canceled-customer.php
 * Note: this is just the default template that is used as a starting pont.
 * Once the user makes edits in the SSA Settings interface,
 * the template stored in the database will be used instead
 *
 * @see         https://simplyscheduleappointments.com
 * @author      Simply Schedule Appointments
 * @package     SSA/Templates
 * @version     2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>
<?php  echo sprintf( /* translators: %s: customer name */ __( 'Hi %s,', 'simply-schedule-appointments' ), '{{ Appointment.customer_information.Name }}' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email template; echoed value is a hard-coded literal placeholder string, not user input, so HTML-escaping is unnecessary and would corrupt the plain-text body. ?>

<?php  echo sprintf( /* translators: 1: appointment type name, 2: site URL */ __( 'Your appointment "%1$s" (booked on %2$s) has been canceled', 'simply-schedule-appointments' ), '{{ Appointment.AppointmentType.title|raw }}', '{{ Global.home_url }}' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email template; echoed value is a hard-coded literal placeholder string, not user input, so HTML-escaping is unnecessary and would corrupt the plain-text body. ?>