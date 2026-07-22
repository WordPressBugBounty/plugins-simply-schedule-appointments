<?php

/**
 * Checks if wp_mail() works.
 *
 * @package Health Check
 */

/**
 * Class Mail Check
 */
class TD_Health_Check_Mail_Check {

	/**
	 * Checks if wp_mail() works.
	 *
	 * @uses sanitize_email()
	 * @uses wp_mail()
	 * @uses wp_send_json_success()
	 * @uses wp_die()
	 *
	 * @return void
	 */
	static function run_mail_check() {
		$output        = '';
		$sendmail      = false;
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Vendored Health Check library upstream code, unmodified; AJAX handler gated by the library's own capability flow, not WP nonces.
		$email_message = isset( $_POST['email_message'] ) ? sanitize_text_field( wp_unslash( $_POST['email_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Vendored Health Check library upstream code, unmodified; AJAX handler gated by the library's own capability flow, not WP nonces.
		$wp_address    = get_bloginfo( 'url' );
		$wp_name       = get_bloginfo( 'name' );
		$date          = gmdate( 'F j, Y' );
		$time          = gmdate( 'g:i a' );

		// translators: %s: website url.
		$email_subject = sprintf( esc_html__( 'Health Check – Test Message from %s', 'simply-schedule-appointments' ), $wp_address );

		$email_body = sprintf(
			// translators: %1$s: website name. %2$s: website url. %3$s: The date the message was sent. %4$s: The time the message was sent. %5$s: Additional custom message from the administrator.
			__( 'Hi! This test message was sent by the Health Check plugin from %1$s (%2$s) on %3$s at %4$s. Since you’re reading this, it obviously works. Additional message from admin: %5$s', 'simply-schedule-appointments' ),
			$wp_name,
			$wp_address,
			$date,
			$time,
			$email_message
		);

		$sendmail = wp_mail( $email, $email_subject, $email_body );

		if ( ! empty( $sendmail ) ) {
			$output .= '<div class="notice notice-success inline"><p>';
			$output .= __( 'We have just sent an e-mail using <code>wp_mail()</code> and it seems to work. Please check your inbox and spam folder to see if you received it.', 'simply-schedule-appointments' );
			$output .= '</p></div>';
		} else {
			$output .= '<div class="notice notice-error inline"><p>';
			$output .= esc_html__( 'It seems there was a problem sending the e-mail.', 'simply-schedule-appointments' );
			$output .= '</p></div>';
		}

		$response = array(
			'message' => $output,
		);

		wp_send_json_success( $response );

		wp_die();

	}

	/**
	 * Add the Mail Checker to the tools tab.
	 *
	 * @param array $tabs
	 *
	 * return array
	 */
	public static function tools_tab( $tabs ) {
		ob_start();
		?>

		<div>
			<p>
				<?php echo wp_kses( __( 'The Mail Check will invoke the <code>wp_mail()</code> function and check if it succeeds. We will use the E-mail address you have set up, but you can change it below if you like.', 'simply-schedule-appointments' ), array( 'code' => array() ) ); ?>
			</p>
			<form action="#" id="health-check-mail-check" method="POST">
				<table class="widefat tools-email-table">
					<tr>
						<td>
							<p>
								<?php
								$current_user = wp_get_current_user();
								?>
								<label for="email"><?php esc_html_e( 'E-mail', 'simply-schedule-appointments' ); ?></label>
								<input type="text" name="email" id="email" value="<?php echo esc_attr( $current_user->user_email ); ?>">
							</p>
						</td>
						<td>
							<p>
								<label for="email_message"><?php esc_html_e( 'Additional message', 'simply-schedule-appointments' ); ?></label>
								<input type="text" name="email_message" id="email_message" value="">
							</p>
						</td>
					</tr>
				</table>
				<input type="submit" class="button button-primary" value="<?php esc_html_e( 'Send test mail', 'simply-schedule-appointments' ); ?>">
			</form>

			<div id="tools-mail-check-response-holder">
				<span class="spinner"></span>
			</div>
		</div>

		<?php
		$tab_content = ob_get_clean();

		$tabs[] = array(
			'label'   => esc_html__( 'Mail Check', 'simply-schedule-appointments' ),
			'content' => $tab_content,
		);

		return $tabs;
	}
}
