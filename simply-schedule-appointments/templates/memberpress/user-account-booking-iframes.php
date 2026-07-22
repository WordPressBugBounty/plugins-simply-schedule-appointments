<?php if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');} // phpcs:ignore ?>

<div id="ssa-mepr-booking-app-container" style="display:none;">
    <button id="ssa-mepr-back-appointments" class="ssa-btn-raised-bordered">
      &larr; &nbsp;<?php echo esc_html__('Back To Appointments', 'simply-schedule-appointments'); ?>
    </button>
    <?php foreach ($bookable_memberships as $membership): 
        $membership_id = $membership->get_product_id();
        $bookable_types = $user->get_bookable_types_for_membership($membership_id);
        foreach ($bookable_types as $appointment_type_id): ?>
            <div 
                class="ssa-mepr-booking-app-iframe-container" 
                data-type-id="<?php echo esc_attr($appointment_type_id); ?>"
                data-product-id="<?php echo esc_attr($membership_id); ?>"
                style="display:none"
            >
                <!-- The iframe goes here  -->
                <?php
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ssa_booking() returns the booking-app HTML markup (see includes/class-shortcodes.php); escaping it would render the markup as text and break the iframe
                echo $this->plugin->shortcodes->ssa_booking([
                    'mepr_membership_id' => $membership_id,
                    'types' => $appointment_type_id
                ]);
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>