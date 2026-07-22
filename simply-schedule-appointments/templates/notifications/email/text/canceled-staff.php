<?php
/**
 * Appointment Booked (to Staff)
 * *
 * This template can be overridden by copying it to wp-content/themes/your-theme/ssa/notifications/email/text/canceled-staff.php
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
<?php  echo sprintf( /* translators: 1: appointment type name, 2: customer name */ __( 'Your appointment "%1$s" with %2$s has been canceled', 'simply-schedule-appointments' ), '{{ Appointment.AppointmentType.title|raw }}', '{{ Appointment.customer_information.Name }}' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email template; esc_html would corrupt the plain-text body ?> 
 
<?php echo __( '*** Canceled ***', 'simply-schedule-appointments' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email template; esc_html would corrupt the plain-text body ?> 
<?php echo __( 'Appointment Details:', 'simply-schedule-appointments' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email template; esc_html would corrupt the plain-text body ?> 
<?php echo sprintf( /* translators: %s: appointment start date and time */ __( '%s', 'simply-schedule-appointments' ), '{{ Appointment.business_start_date }}' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.I18n.NoEmptyStrings -- plain-text email template; esc_html would corrupt the plain-text body, and the lone '%s' is filled by sprintf with the appointment start-date Twig placeholder — changing the literal would alter the rendered email body. ?> 
  
<?php echo sprintf( /* translators: %s: appointment type name */ __( 'Type: %s', 'simply-schedule-appointments' ), '{{ Appointment.AppointmentType.title|raw }}' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email template; esc_html would corrupt the plain-text body ?> 

<?php echo __( 'Customer details:', 'simply-schedule-appointments' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email template; esc_html would corrupt the plain-text body ?> 
{{ Appointment.customer_information_summary }}