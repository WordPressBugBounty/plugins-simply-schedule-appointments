<?php if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');} // phpcs:ignore ?>

<div id="ssa_mepr_past_table_container" class="mp_wrapper">
  <table id="mepr-account-past-appointments-table" class="mepr-account-table">
    <thead>
      <tr>
        <th><?php echo esc_html__('Membership', 'simply-schedule-appointments'); ?></th>
        <th><?php echo esc_html__('Appointment type', 'simply-schedule-appointments'); ?></th>
        <th><?php echo esc_html__('Appointments', 'simply-schedule-appointments'); ?></th>
      </tr>
    </thead>
    <tbody id="ssa-mepr-past-appointments-table-body" >
      <!-- The body will be populated with javascript  -->
    </tbody>
  </table>
</div>