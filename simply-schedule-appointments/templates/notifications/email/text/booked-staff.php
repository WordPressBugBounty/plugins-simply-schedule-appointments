<?php
/**
 * Appointment Booked (to Staff)
 * *
 * This template can be overridden by copying it to wp-content/themes/your-theme/ssa/notifications/email/text/booked-staff.php
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
<?php  echo sprintf( /* translators: 1: customer name, 2: site URL */ __( '%1$s just booked an appointment on %2$s', 'simply-schedule-appointments' ), '{{ Appointment.customer_information.Name }}', '{{ Global.home_url }}' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 
 
<?php echo __( 'Appointment Details:', 'simply-schedule-appointments' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 
<?php echo sprintf( /* translators: %s: appointment start date and time */ __( 'Starting at %s', 'simply-schedule-appointments' ), '{{ Appointment.business_start_date }}' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 
 
{% if instructions %}
<?php echo sprintf( /* translators: %s: appointment instructions */ __( 'Instructions: %s', 'simply-schedule-appointments' ), '{{ instructions|raw }}' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 
{% endif %}

{% if Appointment.web_meeting_url %}
<?php echo sprintf( /* translators: %s: web meeting link URL */ __( 'At your appointment time, join the meeting using this link: %s', 'simply-schedule-appointments' ), '{{ Appointment.web_meeting_url }}' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?>
{% endif %}
 
<?php echo sprintf( /* translators: %s: appointment type name */ __( 'Type: %s', 'simply-schedule-appointments' ), '{{ Appointment.AppointmentType.title|raw }}' ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 

<?php echo __( 'Customer details:', 'simply-schedule-appointments' ) /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body; HTML escaping would corrupt the plain-text output */ ?> 
{{ Appointment.customer_information_summary }}