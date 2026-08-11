<?php
/**
 * Rate limit for the booking-page-facing REST routes.
 *
 * These routes are intentionally reachable by anonymous visitors and accept
 * a static site-wide public_nonce so full-page-cached booking pages still
 * work. The static token can't differentiate callers, so an attacker who
 * lifts it from the page HTML can replay any of these routes from a script.
 * This class makes that replay impractical by counting requests per
 * (client_ip, route) and rejecting with 429 once the per-route threshold
 * is exceeded inside a fixed window.
 *
 * Wired exclusively from TD_API_Model::public_booking_permissions_check —
 * the gate that already isolates "safe for any unauth booking-page visitor"
 * from the strict gates used by admin-facing routes. Never call this from
 * the strict gate; admin routes do not share this threat model and a 429
 * there would just confuse legitimate admins.
 *
 * Storage is WP transients so the implementation works on installs with
 * and without a persistent object cache. Configurable via three filters
 * (limits / client_ip / bypass) and emits one action on each block.
 *
 * The whole limiter is opt-in: every entry point is inert unless the site
 * owner enables the rate-limiting toggle in the admin app's Developer
 * Settings (see is_enabled()).
 *
 * @package Simply_Schedule_Appointments
 */

class SSA_Rate_Limit {

	/**
	 * Master switch for everything in this class, wired to the rate-limiting
	 * toggle in the admin app's Developer Settings. Defaults to off: the
	 * booking throttle silently quarantines over-limit bookings, and behind a
	 * large shared NAT (university, office egress) that can false-positive on
	 * legitimate bursts — so site owners opt in per site instead of every
	 * install inheriting silent trashing on update. Reads defensively: a
	 * missing settings container or a not-yet-migrated stored schema reads as
	 * disabled, never fatal.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = ssa()->developer_settings->get();
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return ! empty( $settings['rate_limiting'] );
	}

	/**
	 * Check the request against the per-route rate limit.
	 *
	 * Returns true to let the caller continue (admin, filter bypass, or
	 * under the limit). Returns a WP_Error with status 429 when the limit
	 * is exceeded, and as a side effect sets the Retry-After header on the
	 * outgoing response.
	 *
	 * Counts every request to a public booking route regardless of nonce
	 * validity — the point of this limiter is that the nonce alone is
	 * insufficient, so gating the counter behind it would let attackers
	 * probe the surface forever without consuming budget.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function check( $request ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		// Trusted authenticated users skip every limit in this class: the replay
		// threat is an anonymous script armed with the static public_nonce, and a
		// request recognized as logged-in carries a per-user wp_rest nonce that
		// script can't forge. Gated on the edit_posts capability rather than a
		// role-name list so custom roles a site owner trusts to author content
		// are covered automatically, while subscribers and plugin-created
		// zero-trust accounts (auto-registered customers, membership roles) stay
		// limited. Capability-less callers that need an exemption (service
		// accounts, monitoring) go through the ssa/rate_limit/bypass filter.
		if ( current_user_can( 'ssa_manage_site_settings' ) || current_user_can( 'edit_posts' ) ) {
			return true;
		}

		/**
		 * Short-circuit the rate limit for this request. Return true to
		 * skip counting entirely (e.g. site-owner-maintained IP allowlist,
		 * internal monitoring pings).
		 *
		 * @param bool             $bypass  Default false.
		 * @param WP_REST_Request  $request The current request.
		 */
		if ( apply_filters( 'ssa/rate_limit/bypass', false, $request ) ) {
			return true;
		}

		$client_ip = self::get_client_ip();
		if ( empty( $client_ip ) ) {
			return true;
		}

		$route_info = self::classify_route( $request );
		if ( $route_info['limit'] < 1 ) {
			// Tier intentionally disabled (filter returned 0/negative) or the
			// filter mangled the limits table. Degrade to "no rate limit on
			// this route" rather than "everything is 429".
			return true;
		}

		// Native, no-payment booking POSTs are owned by the silent booking
		// throttle in the create path (SSA_Appointment_Model::create_item via
		// booking_throttle_exceeded): over the limit they are accepted with a
		// 200 and quarantined as 'abandoned', never 429'd. Returning a 429
		// here would reveal the block we deliberately hide, so skip the gate
		// counter for exactly that shape. Payment bookings and pending_form
		// (form-integration) POSTs fall through to the write-tier 429 below.
		if ( 'appointments' === $route_info['key'] && self::is_native_no_payment_booking( $request ) ) {
			return true;
		}

		$key = self::counter_key( $route_info['key'], $client_ip );
		$now = time();

		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['expires_at'] ) || $state['expires_at'] <= $now ) {
			$state = array(
				'count'      => 0,
				'expires_at' => $now + $route_info['window'],
			);
		}
		$state['count']++;
		$ttl = max( 1, $state['expires_at'] - $now );
		set_transient( $key, $state, $ttl );

		if ( $state['count'] > $route_info['limit'] ) {
			return self::blocked_response( $route_info, $client_ip, $ttl );
		}

		return true;
	}

	/**
	 * Per-tier limits keyed by tier name. Tiers map to specific routes via
	 * classify_route(); the two tiers reflect the booking flow's actual
	 * traffic shape (bursty reads, rare writes).
	 *
	 * @return array<string,array{limit:int,window:int}>
	 */
	public static function get_limits() {
		$defaults = array(
			'read'  => array(
				'limit'  => 240,
				'window' => MINUTE_IN_SECONDS,
			),
			'write' => array(
				'limit'  => 5,
				'window' => MINUTE_IN_SECONDS,
			),
		);

		/**
		 * Override the per-tier rate limit table. Useful for sites behind
		 * large shared egress (university, corporate NAT) where the default
		 * read budget bites legitimate concurrent users.
		 *
		 * The shape MUST match the default: each tier key maps to an array
		 * with integer 'limit' and integer 'window' (seconds). Limits below
		 * 1 disable enforcement for that tier.
		 *
		 * @param array $limits
		 */
		return apply_filters( 'ssa/rate_limit/limits', $defaults );
	}

	/**
	 * Resolve the client IP for the rate-limit key. Cloudflare's
	 * CF-Connecting-IP is preferred because it is set by Cloudflare's edge
	 * and cannot be spoofed by an external client when Cloudflare is in
	 * front. Other reverse-proxy setups (NGINX, AWS ALB, etc.) are not
	 * trusted by default — site owners filter the result themselves
	 * via ssa/rate_limit/client_ip rather than us shipping a brittle
	 * "trusted proxies" list. IPv6 is bucketed by /64 so trivial host-part
	 * rotation doesn't defeat the counter.
	 *
	 * Trust boundary: the CF-Connecting-IP preference only holds when
	 * Cloudflare actually fronts the site. On an install NOT behind
	 * Cloudflare the header is just an attacker-supplied request field, so a
	 * script can forge a fresh value per request and the per-IP counter
	 * never trips (the per-email booking counter still bites, and the whole
	 * limiter is off by default). Such sites should return their own trusted
	 * resolution via ssa/rate_limit/client_ip below. The robust fix for the
	 * static-nonce replay this guards is the per-session signed booking
	 * token flagged as a follow-up, which needs no IP heuristics at all.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$raw = '';
		// Only trustworthy when Cloudflare fronts the site; on a non-CF install
		// this header is attacker-forgeable — see the trust-boundary note above.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$raw = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw = $_SERVER['REMOTE_ADDR'];
		}

		$ip = self::canonical_ip( $raw );

		/**
		 * Override the resolved client IP. Sites behind a non-Cloudflare
		 * reverse proxy should return their own trusted resolution
		 * (typically the left-most hop in X-Forwarded-For after validating
		 * REMOTE_ADDR is the known proxy). Returning an empty string
		 * disables counting for this request.
		 *
		 * @param string $ip Default resolution.
		 */
		return (string) apply_filters( 'ssa/rate_limit/client_ip', $ip );
	}

	private static function canonical_ip( $raw ) {
		$ip = trim( (string) $raw );
		if ( '' === $ip ) {
			return '';
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::ipv6_prefix64( $ip );
		}
		return $ip;
	}

	private static function ipv6_prefix64( $ip ) {
		$packed = @inet_pton( $ip );
		if ( false === $packed || strlen( $packed ) !== 16 ) {
			return $ip;
		}
		$packed = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
		$prefix = @inet_ntop( $packed );
		return false === $prefix ? $ip : $prefix;
	}

	/**
	 * Stable, salted, one-way hash of the resolved client IP, for persisting
	 * per booking without storing the raw address (GDPR). Salted with
	 * wp_salt so the value can't be reversed via a precomputed IP table and
	 * is not comparable across sites. Returns '' when no IP could be
	 * resolved.
	 *
	 * @return string
	 */
	public static function get_client_ip_hash() {
		return self::hash_ip( self::get_client_ip() );
	}

	/**
	 * Salted, one-way SHA-256 of a single resolved IP — the one hashing method
	 * for the client IP across this class (the persisted booking_ip_hash meta
	 * and both observability actions), so a subscriber that logs an action
	 * payload never holds a reversible bare-IPv4 hash. Salted with wp_salt so
	 * it can't be reversed via a precomputed IP table and isn't comparable
	 * across sites. Returns '' for an empty IP.
	 *
	 * @param string $ip
	 * @return string
	 */
	private static function hash_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . $ip );
	}

	/**
	 * Silent per-(IP|email) throttle for native no-payment booking writes.
	 *
	 * Distinct from check(): check() returns a 429 on the routes where a
	 * visible block is acceptable; this returns a boolean the create path
	 * turns into a silent quarantine (accept the request, return 200, store
	 * the appointment as 'abandoned'). The request is counted against two
	 * independent fixed-window counters — resolved client IP and normalized
	 * customer email — and "exceeded" is reported if EITHER is over the
	 * limit, so an attacker can't dodge by rotating only one of the two.
	 * Both counters are always incremented (no short-circuit) so a flood
	 * from one dimension still accrues against the other.
	 *
	 * On a trip it fires the ssa/rate_limit/booking_throttled action with a
	 * hashed IP — the only server-side signal of an otherwise-invisible
	 * quarantine, so owners can detect false positives and tune the limit.
	 *
	 * @param WP_REST_Request $request
	 * @return bool True when the request is over the booking limit.
	 */
	public static function booking_throttle_exceeded( $request ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		// Same trusted-user exemption as check() — the rationale lives there.
		if ( current_user_can( 'ssa_manage_site_settings' ) || current_user_can( 'edit_posts' ) ) {
			return false;
		}
		if ( apply_filters( 'ssa/rate_limit/bypass', false, $request ) ) {
			return false;
		}

		$limit = self::get_booking_limit();
		if ( $limit['limit'] < 1 ) {
			return false;
		}

		$ip_exceeded    = false;
		$email_exceeded = false;

		$client_ip = self::get_client_ip();
		if ( '' !== $client_ip ) {
			$ip_exceeded = self::register_hit( self::counter_key( 'booking_ip', $client_ip ), $limit['limit'], $limit['window'] );
		}

		$email = self::get_booking_email( $request );
		if ( '' !== $email ) {
			$email_exceeded = self::register_hit( self::counter_key( 'booking_email', $email ), $limit['limit'], $limit['window'] );
		}

		$throttled = $ip_exceeded || $email_exceeded;

		if ( $throttled ) {
			/**
			 * Fired once when a native no-payment booking trips the silent
			 * throttle and is about to be quarantined as 'abandoned'. The
			 * client still receives a 200 mirroring a real booking, so this is
			 * the only server-side signal that a silent block happened —
			 * subscribers can use it to spot false positives (e.g. a shared
			 * office/NAT IP) and tune ssa/rate_limit/booking_limit. The IP is
			 * salted-hashed (same method as the persisted booking_ip_hash) so a
			 * subscriber that logs it never stores a reversible address. No
			 * default subscriber is wired up, so on its own this changes no behavior.
			 *
			 * @param string $ip_hash    Salted SHA-256 of the resolved client IP ('' if none).
			 * @param array  $dimensions Which counter(s) tripped: 'ip' / 'email' booleans.
			 * @param array  $limit      limit / window in effect.
			 */
			do_action(
				'ssa/rate_limit/booking_throttled',
				self::hash_ip( $client_ip ),
				array(
					'ip'    => $ip_exceeded,
					'email' => $email_exceeded,
				),
				$limit
			);
		}

		return $throttled;
	}

	/**
	 * Limit + window for the native no-payment booking throttle: 5 bookings
	 * per 60 seconds, applied independently per IP and per email.
	 *
	 * @return array{limit:int,window:int}
	 */
	public static function get_booking_limit() {
		$default = array(
			'limit'  => 5,
			'window' => MINUTE_IN_SECONDS,
		);

		/**
		 * Override the native no-payment booking throttle. Same shape as the
		 * default: integer 'limit' and integer 'window' (seconds). A limit
		 * below 1 disables the throttle entirely.
		 *
		 * @param array $limit
		 */
		$limit = apply_filters( 'ssa/rate_limit/booking_limit', $default );

		return array(
			'limit'  => isset( $limit['limit'] ) ? (int) $limit['limit'] : 0,
			'window' => isset( $limit['window'] ) ? (int) $limit['window'] : MINUTE_IN_SECONDS,
		);
	}

	/**
	 * Whether this request is a native booking-app POST that will settle to
	 * a 'booked' appointment with no payment obligation — the only shape the
	 * silent booking throttle acts on. Form-integration bookings (status
	 * pending_form) and payment bookings are excluded; they keep the
	 * standard write-tier 429.
	 *
	 * Mirrors the payment-vs-free decision in
	 * SSA_Payments::filter_appointment_insert_status (the source of truth for
	 * the resulting appointment status) as a cheap pre-insert check, so the
	 * permission gate and the create path agree on "native no-payment
	 * booking" without running the full insert filter chain. The create-path
	 * trap additionally re-checks the resolved status before quarantining, so
	 * any drift here can only over-skip the gate, never trash a paid booking.
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public static function is_native_no_payment_booking( $request ) {
		if ( 'pending_form' === (string) $request->get_param( 'status' ) ) {
			return false;
		}
		return ! self::booking_requires_payment( $request );
	}

	private static function booking_requires_payment( $request ) {
		$type_id = $request->get_param( 'appointment_type_id' );
		if ( empty( $type_id ) || ! class_exists( 'SSA_Appointment_Type_Object' ) ) {
			return false;
		}

		$appointment_type = SSA_Appointment_Type_Object::instance( $type_id );
		// Read via the raw data array: the object has no __isset(), so
		// isset( $appointment_type->payments ) is always false, and __get()
		// can throw for a field missing from an existing row.
		$data     = $appointment_type->data;
		$payments = isset( $data['payments'] ) ? $data['payments'] : array();
		if ( ! is_array( $payments ) || empty( $payments['payment_required'] ) ) {
			return false;
		}

		$required = $payments['payment_required'];
		$price    = isset( $payments['price'] ) ? (float) $payments['price'] : 0.0;
		$method   = (string) $request->get_param( 'payment_method' );

		if ( 'required' === $required && $price > 0 ) {
			return true;
		}
		if ( 'optional' === $required && '' !== $method && 'pay_later' !== $method ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalized customer email for the per-email booking counter.
	 * Lowercased and trimmed so trivial case/whitespace variation can't fan
	 * the counter out; returns '' when absent or not a valid address. The
	 * email is only ever hashed into the counter key, never stored.
	 *
	 * @param WP_REST_Request $request
	 * @return string
	 */
	private static function get_booking_email( $request ) {
		$info = $request->get_param( 'customer_information' );
		if ( ! is_array( $info ) || empty( $info['Email'] ) || ! is_scalar( $info['Email'] ) ) {
			return '';
		}
		$email = strtolower( trim( (string) $info['Email'] ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Increment a fixed-window counter and report whether it is now over the
	 * limit. Mirrors the windowing in check() but kept separate so the
	 * shipping per-route limiter is left untouched.
	 *
	 * The get_transient/increment/set_transient sequence is deliberately not
	 * atomic: under concurrent bookings two requests can read the same count
	 * and both write count+1, letting a burst slip a few past the limit. That
	 * slack is acceptable for a throttle (this is a rate limiter, not a hard
	 * authz gate) and is preferred over a per-booking lock that would add
	 * contention on every write. Don't "fix" it with a lock without a reason.
	 */
	private static function register_hit( $key, $limit, $window ) {
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['expires_at'] ) || $state['expires_at'] <= $now ) {
			$state = array(
				'count'      => 0,
				'expires_at' => $now + $window,
			);
		}
		$state['count']++;
		$ttl = max( 1, $state['expires_at'] - $now );
		set_transient( $key, $state, $ttl );

		return $state['count'] > $limit;
	}

	/**
	 * Map a public booking request to a logical route key + tier. The key
	 * is the counter bucket (so /availability for type 5 and type 10 share
	 * a bucket — attackers can't pivot the resource id to dodge the cap).
	 * Unknown public routes fall through to the read tier as a safety net:
	 * this method is only reached from public_booking_permissions_check,
	 * so any caller here is on a public booking route by definition.
	 */
	private static function classify_route( $request ) {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		$limits = self::get_limits();

		$key  = 'public_booking';
		$tier = 'read';

		if ( preg_match( '#^/ssa/v1/appointment_types/[^/]+/availability/?$#', $route ) ) {
			$key  = 'availability';
			$tier = 'read';
		} elseif ( 0 === strpos( $route, '/ssa/v1/appointment_types' ) ) {
			$key  = 'appointment_types';
			$tier = 'read';
		} elseif ( 0 === strpos( $route, '/ssa/v1/settings' ) ) {
			$key  = 'settings';
			$tier = 'read';
		} elseif ( '/ssa/v1/appointments' === $route && 'POST' === $method ) {
			$key  = 'appointments';
			$tier = 'write';
		} elseif ( '/ssa/v1/payments' === $route && 'POST' === $method ) {
			$key  = 'payments';
			$tier = 'write';
		}

		$tier_limits = isset( $limits[ $tier ] ) ? $limits[ $tier ] : $limits['read'];

		return array(
			'key'    => $key,
			'tier'   => $tier,
			'limit'  => isset( $tier_limits['limit'] ) ? (int) $tier_limits['limit'] : 0,
			'window' => isset( $tier_limits['window'] ) ? (int) $tier_limits['window'] : MINUTE_IN_SECONDS,
		);
	}

	/**
	 * Transient name for one counter bucket. The identifier (client IP or
	 * normalized customer email) is folded in through the same salted one-way
	 * SHA-256 as the persisted booking_ip_hash, so the stored transient name
	 * never embeds a digest an attacker could reverse by enumerating the
	 * small IPv4/known-email keyspace — an unsalted hash of an IP is
	 * precomputable.
	 */
	private static function counter_key( $route_key, $identifier ) {
		return 'ssa_rl_' . substr( hash( 'sha256', wp_salt( 'auth' ) . '|' . $route_key . '|' . $identifier ), 0, 24 );
	}

	/**
	 * Build the 429 response, set Retry-After, and fire the observability
	 * action. The action payload salted-hashes the IP (same method as the
	 * persisted booking_ip_hash) so subscribers that pipe to logs don't store
	 * reversible addresses by accident.
	 */
	private static function blocked_response( $route_info, $client_ip, $retry_after ) {
		if ( ! headers_sent() ) {
			header( 'Retry-After: ' . (int) $retry_after );
		}

		/**
		 * Fired once per blocked request. Subscribers can log to whatever
		 * monitoring they already use; no default logger is wired up here
		 * because under attack a default error_log would fill the disk on
		 * shared hosts.
		 *
		 * @param string $route_key  Logical route bucket (e.g. 'settings').
		 * @param string $ip_hash    Salted SHA-256 of the resolved client IP.
		 * @param array  $meta       limit / window / tier.
		 */
		do_action(
			'ssa/rate_limit/exceeded',
			$route_info['key'],
			self::hash_ip( $client_ip ),
			array(
				'limit'  => $route_info['limit'],
				'window' => $route_info['window'],
				'tier'   => $route_info['tier'],
			)
		);

		return new WP_Error(
			'ssa_rate_limited',
			__( 'Too many requests. Please try again later.', 'simply-schedule-appointments' ),
			array( 'status' => 429 )
		);
	}
}
