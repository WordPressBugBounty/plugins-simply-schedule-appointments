<?php if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');} // phpcs:ignore ?>

<?php
$user_id = $this->ssa_mepr_get_user_id();
$user = new SSA_Mepr_User( $user_id );
$past_appointments = $user->get_prepared_past_appointments();

$memberships = $user->extract_memberships_details_from_appointments( $past_appointments );
$appointment_types = $user->extract_appointment_types_details_from_appointments( $past_appointments );

$appointment_statuses_keys = array_merge(
  SSA_Appointment_Model::get_booked_statuses(),
  SSA_Appointment_Model::get_canceled_statuses(),
  SSA_Appointment_Model::get_abandoned_statuses()
);

$statuses = array();
foreach ($appointment_statuses_keys as $key) {
  $statuses[ $key ] = $this->get_admin_app_translations()['appointments']['statuses'][$key];
}

$date_options = array(
  'last_30_days' => __('Last 30 days', 'simply-schedule-appointments'),
  'last_90_days' => __('Last 90 days', 'simply-schedule-appointments'),
  'all' => __('All', 'simply-schedule-appointments'),
);

// Start Section
echo '<section class="ssa-mepr__past-appointments" id="ssa-mepr__past-appointments-section">';

echo '<h2>'. esc_html__('Past Appointments', 'simply-schedule-appointments') .'</h2>';

// Start the form
echo '<form id="appointments-filter-form">';

// Membership select
echo '<div class="ssa_mepr_inner-filter-container">';
echo '<label for="ssa_mepr_select_membership">'. esc_html__('Membership', 'simply-schedule-appointments') .'</label>';
echo '<br>';
echo '<select id="ssa_mepr_select_membership" name="membership">';
echo '<option value="any">'. esc_html__('Any', 'simply-schedule-appointments') .'</option>';
foreach ($memberships as $membership) {
    echo '<option value="'.esc_attr($membership['id']).'">'.esc_html($membership['title']).'</option>';
}
echo '</select>';
echo '</div>';

// Appointment type select
echo '<div class="ssa_mepr_inner-filter-container">';
echo '<label for="ssa_mepr_select_appointment_types">'. esc_html__('Appointment Type', 'simply-schedule-appointments') .'</label>';
echo '<br>';
echo '<select id="ssa_mepr_select_appointment_types" name="appointment_type">';
echo '<option value="any">'. esc_html__('Any', 'simply-schedule-appointments') .'</option>';
foreach ($appointment_types as $appointmentType) {
    echo '<option value="'.esc_attr($appointmentType['id']).'">'.esc_html($appointmentType['title']).'</option>';
}
echo '</select>';
echo '</div>';

// Date options
echo '<div class="ssa_mepr_inner-filter-container">';
echo '<label for="ssa_mepr_select_date">'. esc_html__('Date', 'simply-schedule-appointments') .'</label>';
echo '<br>';
echo '<select id="ssa_mepr_select_date" name="date">';
foreach ($date_options as $key => $date_option) {
    echo '<option value="'.esc_attr($key).'">'.esc_html($date_option).'</option>';
}
echo '</select>';
echo '</div>';

// Status select
echo '<div class="ssa_mepr_inner-filter-container">';
echo '<label for="ssa_mepr_select_status">'. esc_html__('Status', 'simply-schedule-appointments') .'</label>';
echo '<br>';
echo '<select id="ssa_mepr_select_status" name="status">';
echo '<option value="any">'. esc_html__('Any', 'simply-schedule-appointments') .'</option>';
foreach ( $statuses as $key => $status ) {
    $selected = ($key === 'booked') ? 'selected' : '';
    echo '<option value="'.esc_attr($key).'" '.esc_attr($selected).'>'.esc_html($status).'</option>';
}
echo '</select>';
echo '</div>';

// End the form
echo '</form>';

// Render table
$this->render_past_appointments_table();

// End the section
echo '</section>';