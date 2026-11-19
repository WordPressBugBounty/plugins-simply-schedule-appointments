<?php
/**
 * Simply Schedule Appointments Trusted Email.
 *
 * @package Simply_Schedule_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides when an account's email may stand in for the account.
 *
 * Any user can change their own email through POST /wp/v2/users/me with no
 * confirmation, and a sign-up that sets its own password shows nothing about
 * its address either. So an email stops counting on an unconfirmed self-change
 * or a self sign-up, and counts again once the site has reached the address:
 * an admin's action, core's profile-page email confirmation, a password reset
 * through the emailed link, a multisite activation link, or the verification
 * link My Appointments emails. Accounts from before this rule carry no flag and
 * match as they always did.
 */
class SSA_Trusted_Email {
	const UNTRUSTED_META = 'ssa_untrusted_email';
	const LINK_META      = 'ssa_email_verification_link';

	const SEND_ACTION = 'ssa_send_email_verification';
	const KEY_ARG     = 'ssa_verify_email_key';
	const LOGIN_ARG   = 'ssa_verify_email_login';

	protected $plugin = null;

	// Sign-ups flagged during this request, for trust_after_activation().
	private $flagged_now = array();

	// Whether this request's Verify my email click sent the link, for the
	// template; null when there was no click.
	public $verification_sent = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	public function hooks() {
		// Priority 9: SSA_Staff_Model links by email on these hooks at the default
		// priority, and has to see the verdict.
		add_action( 'user_register', array( $this, 'distrust_self_signup' ), 9 );
		add_action( 'profile_update', array( $this, 'handle_email_change' ), 9, 2 );
		add_action( 'password_reset', array( $this, 'trust_after_password_reset' ) );
		add_action( 'after_password_reset', array( $this, 'trust_after_password_reset' ) );
		add_action( 'wpmu_activate_user', array( $this, 'trust_after_activation' ) );
		add_action( 'wpmu_activate_blog', array( $this, 'trust_after_blog_activation' ), 10, 2 );
		// On the front end, not admin-post.php: WooCommerce and lock-down plugins
		// keep customers out of wp-admin.
		add_action( 'init', array( $this, 'handle_verification_requests' ) );
	}

	public function handle_verification_requests() {
		// The link counts only while its own account is logged in: whoever reads
		// the inbox may be someone else, and their click must not vouch for it.
		$link_user = $this->get_link_user();
		if ( $link_user && get_current_user_id() === $link_user->ID ) {
			delete_user_meta( $link_user->ID, self::LINK_META );
			$this->trust( $link_user->ID );
		}

		if ( empty( $_POST[ self::SEND_ACTION ] ) || ! is_user_logged_in() ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::SEND_ACTION ) ) {
			return;
		}

		$return_url = isset( $_POST['ssa_return_url'] ) ? esc_url_raw( wp_unslash( $_POST['ssa_return_url'] ) ) : '';

		$this->verification_sent = $this->send_verification_email( wp_get_current_user(), wp_validate_redirect( $return_url, home_url( '/' ) ) );
	}

	// The link carries a token of SSA's own, not core's password reset key: core
	// wipes that key at every login, and a customer who opens the link logged out
	// has to log in first. The token is stored with the address it was sent to,
	// so it proves only that address.
	public function send_verification_email( WP_User $user, $return_url ) {
		$last_link = get_user_meta( $user->ID, self::LINK_META, true );
		if ( ! empty( $last_link['time'] ) && time() - $last_link['time'] < 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		$key = wp_generate_password( 32, false );
		update_user_meta(
			$user->ID,
			self::LINK_META,
			array(
				'key'   => wp_hash( $key ),
				'email' => $user->user_email,
				'time'  => time(),
			)
		);

		$link = add_query_arg(
			array(
				self::KEY_ARG   => $key,
				self::LOGIN_ARG => rawurlencode( $user->user_login ),
			),
			$return_url
		);

		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		/* translators: %s: the site's name */
		$subject = sprintf( __( '[%s] Verify your email address', 'simply-schedule-appointments' ), $site_name );
		$message = __( 'Open this link to verify your email address and see your appointments:', 'simply-schedule-appointments' ) . "\r\n\r\n"
			. $link . "\r\n\r\n"
			. __( 'If you did not ask for this, you can ignore this email.', 'simply-schedule-appointments' );

		return wp_mail( $user->user_email, $subject, $message );
	}

	// The account whose unused verification link this request carries. Null when
	// the link is missing, wrong, over a day old, or was sent to an address the
	// account no longer has.
	public function get_link_user() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the emailed token is the check.
		if ( ! isset( $_GET[ self::KEY_ARG ], $_GET[ self::LOGIN_ARG ] ) ) {
			return null;
		}

		$key  = sanitize_text_field( wp_unslash( $_GET[ self::KEY_ARG ] ) );
		$user = get_user_by( 'login', sanitize_text_field( wp_unslash( $_GET[ self::LOGIN_ARG ] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$link = $user ? get_user_meta( $user->ID, self::LINK_META, true ) : array();
		if (
			empty( $link['key'] )
			|| ! hash_equals( $link['key'], wp_hash( $key ) )
			|| time() - $link['time'] > DAY_IN_SECONDS
			|| strtolower( $link['email'] ) !== strtolower( $user->user_email )
		) {
			return null;
		}

		return $user;
	}

	public function is_trusted( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( empty( $user ) || empty( $user->user_email ) ) {
			return false;
		}

		return ! in_array( strtolower( $user->user_email ), get_user_meta( $user->ID, self::UNTRUSTED_META ), true );
	}

	// Whether a booking saved under this account got there only by an email the
	// account holds or held unproven: the booking code saves a booking under
	// whichever account has its email. A booking the account made itself while
	// logged in is its own either way.
	public function is_claimed_by_unproven_email( $user_id, array $appointment ) {
		$unproven = $user_id ? get_user_meta( $user_id, self::UNTRUSTED_META ) : array();
		if ( empty( $unproven ) ) {
			return false;
		}

		// A REST `fields` projection can hand over a row without the columns this
		// decides on, or with them still encoded, so read the stored row then.
		if ( ! isset( $appointment['author_id'] ) || ! is_array( $appointment['customer_information'] ?? null ) ) {
			$appointment = (array) $this->plugin->appointment_model->get( $appointment['id'] ?? 0 );
		}

		if ( ! isset( $appointment['author_id'] ) ) {
			return true;
		}

		// A row saved through the API can hold Email as a list. It matches no
		// address, so it counts as unproven.
		$email = $appointment['customer_information']['Email'] ?? '';

		return (int) $appointment['author_id'] !== (int) $user_id && ( ! is_string( $email ) || in_array( strtolower( $email ), $unproven, true ) );
	}

	// Leaves out, from the logged-in account's lists, the bookings that are its
	// own only by such an email.
	public function only_own_bookings( array $appointments ) {
		$user_id = get_current_user_id();

		return array_values(
			array_filter(
				$appointments,
				function ( $appointment ) use ( $user_id ) {
					return (int) $appointment['customer_id'] !== $user_id || ! $this->is_claimed_by_unproven_email( $user_id, $appointment );
				}
			)
		);
	}

	// The logged-in account's bookings for a customer list. A hidden booking must
	// not use up one of the list's places, so this reads on until the list is full.
	public function query_own_bookings( array $args ) {
		$number         = isset( $args['number'] ) ? (int) $args['number'] : 0;
		$args['offset'] = 0;
		$own            = array();

		do {
			$page = $this->plugin->appointment_model->query( $args );
			// Keyed by id: rows that tie on the sort column can repeat across reads.
			$own            += array_column( $this->only_own_bookings( $page ), null, 'id' );
			$args['offset'] += count( $page );
		} while ( $number > 0 && count( $own ) < $number && count( $page ) === $number );

		return array_slice( array_values( $own ), 0, $number > 0 ? $number : null );
	}

	public function distrust_self_signup( $user_id ) {
		if ( ! $this->is_operator_acting_on( $user_id ) ) {
			$this->distrust( get_userdata( $user_id ) );
			$this->flagged_now[ (int) $user_id ] = true;
		}
	}

	public function handle_email_change( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( empty( $user ) || empty( $old_user_data->user_email ) || strtolower( $user->user_email ) === strtolower( $old_user_data->user_email ) ) {
			return;
		}

		if ( $this->is_operator_acting_on( $user_id ) || $this->is_core_confirmed_change( $user ) ) {
			delete_user_meta( $user_id, self::UNTRUSTED_META, strtolower( $user->user_email ) );
		} elseif ( get_current_user_id() === (int) $user_id || ! $this->is_trusted( $old_user_data ) ) {
			// The account's own change, or one by no one that inherits the old address's standing.
			$this->distrust( $user );
		}
	}

	// The flag keeps the address, one row each. An address the account moves on
	// from without proving stays on record, for only_own_bookings().
	private function distrust( WP_User $user ) {
		if ( $this->is_trusted( $user ) ) {
			add_user_meta( $user->ID, self::UNTRUSTED_META, strtolower( $user->user_email ) );
		}
	}

	// Core's reset screen and WooCommerce's both reach this only after checking the
	// key they emailed, and core voids that key on any email change, so a reset
	// proves the current address. WooCommerce fires only password_reset before 10.9.0.
	public function trust_after_password_reset( $user ) {
		$this->trust( $user->ID );
	}

	// Multisite creates the account only from the activation link it emailed to
	// the sign-up's address, so an account this activation created is proven. One
	// that existed before may have changed its email since the link was sent.
	public function trust_after_activation( $user_id ) {
		if ( isset( $this->flagged_now[ (int) $user_id ] ) ) {
			$this->trust( $user_id );
		}
	}

	public function trust_after_blog_activation( $blog_id, $user_id ) {
		$this->trust_after_activation( $user_id );
	}

	// A proof that arrives after the sign-up or email change, so what waited on it
	// (a team member's record) can link now. An email change needs no signal:
	// profile_update already carries it.
	private function trust( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && delete_user_meta( $user_id, self::UNTRUSTED_META, strtolower( $user->user_email ) ) ) {
			do_action( 'ssa/user/email_trusted', (int) $user_id );
		}
	}

	// Someone allowed to edit the user, or a WP-CLI run, vouches for the address.
	private function is_operator_acting_on( $user_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		$current_user_id = get_current_user_id();

		return $current_user_id && (int) $user_id !== $current_user_id && current_user_can( 'edit_user', $user_id );
	}

	// Core fires no hook of its own for the profile-page change it confirms by
	// email, so this checks it the way user-edit.php does, before core deletes the hash.
	private function is_core_confirmed_change( WP_User $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the stored hash below is the check, as in core.
		if ( empty( $_GET['newuseremail'] ) || get_current_user_id() !== $user->ID ) {
			return false;
		}

		$new_email = get_user_meta( $user->ID, '_new_email', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the stored hash is the check.
		$hash = sanitize_text_field( wp_unslash( $_GET['newuseremail'] ) );

		return is_array( $new_email )
			&& ! empty( $new_email['hash'] )
			&& ! empty( $new_email['newemail'] )
			&& hash_equals( $new_email['hash'], $hash )
			&& strtolower( trim( $new_email['newemail'] ) ) === strtolower( $user->user_email );
	}
}
