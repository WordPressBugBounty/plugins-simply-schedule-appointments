<?php
/**
 *
 * @since   6.6.5
 * @package Simply_Schedule_Appointments
 * 
 */

 class SSA_Google_Calendar_Client {
	
	private $access_token = false;
	
	private $client_id = false;
	
	private $client_secret = false;
	
	private $redirect_uri = false;
	
	private $quotaUser = null;

	// Set when Google rejects a refresh with error=invalid_grant (dead refresh token).
	private $refresh_token_invalid = false;

	// Flipped the moment we ATTEMPT a force-refresh after a 401 on a data call -- on the
	// attempt, not on success. The rule is deliberately outcome-independent: exactly ONE
	// refresh+replay per client instance, whether it succeeds, fails, or 401s again. That is
	// what stops a token Google keeps rejecting (or a refresh that fails) from spinning the
	// refresh/replay into a loop or API storm. A fresh client (each service_init()) resets it.
	private $did_force_refresh = false;


	/**
	 * Parent plugin class.
	 *
	 * @since 0.6.0
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;
	
	protected $staff_id = 0;
	
	/**
	 * @since 6.6.5
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}
	
	/**
	 * Initiate our hooks.
	 *
	 * @since  0.6.0
	 */
	public function hooks() {
		//
	}
	
	public function client_init() {
		if ( !empty($this->client_id) || !empty($this->client_secret) ) {
			return $this;
		}
		
		$settings = ssa()->settings->get();
		$google_calendar_settings = $settings['google_calendar'];

		// Only initialize if we're not using the new ssa_quick_connect auth flow
		// any method besides this one should access the client_id and client_secret directly on this class
		if( !$google_calendar_settings['quick_connect_gcal_mode'] ){
			$this->client_id = $this->plugin->google_calendar->get_client_id();
			$this->client_secret = $this->plugin->google_calendar->get_client_secret();
		} else {
			// if ssa_quick_connect enabled get our own client_id
			if( !defined( 'SSA_QUICK_CONNECT_GCAL_CLIENT_ID' ) ){
				ssa_debug_log( 'SSA_QUICK_CONNECT_GCAL_CLIENT_ID not defined!', 10 );
				return false;
			}
			$this->client_id = SSA_QUICK_CONNECT_GCAL_CLIENT_ID;
		}
		
		return $this;
	}

	public function service_init( $staff_id = 0 ) {
		$client = (new self( $this->plugin ))->client_init();
		$client->staff_id = $staff_id;
		$client->authorize();
		return $client;
	}
	
	/**
	 * Call this to authorize the client
	 * updates the access token in the settings as well
	 * 
	 * @since 6.6.5
	 * 
	 * @return void
	 */
	private function authorize() {
		$staff_access_token = $this->get_access_token_for_staff_id();
		if( $staff_access_token != $this->access_token ) {
			$this->access_token = $staff_access_token;
		}
		
		// check also if the access token is the correct one
		if( !$this->is_access_token_expired( $this->access_token ) ) {
			// no need to refresh access token
			return;
		}
		
		// if quick connect enabled, get quick connect access token
		
		$google_calendar_settings = $this->plugin->google_calendar_settings->get();
		
		$google_quick_connect_gcal_mode = $google_calendar_settings['quick_connect_gcal_mode'] == true;
		
		if(  true == $google_quick_connect_gcal_mode ){
			$this->authorize_with_quick_connect( $this->staff_id );
		} else {
			$this->authorize_with_client_id_and_secret();
		}

		if ( empty( $this->access_token ) ) {
			ssa_debug_log( 'missing_access_token for staff id '.$this->staff_id, 10 );
			return;
		}
		
		// if still expired
		if( $this->is_access_token_expired( $this->access_token ) ) {
			ssa_debug_log( 'expired_access_token for staff id '.$this->staff_id, 10 );
			ssa_debug_log( ssa_get_stack_trace(), 10 );
			throw new Exception( 'Failed to authorize with Google Calendar' );
		}
	}
	
	private function authorize_with_client_id_and_secret() {
		$access_token = $this->get_access_token_for_staff_id();
		// throwing the exception here to avoid fatal error of accessing an offset on a non-array
		if( empty( $access_token ) || !is_array( $access_token ) ) {
			throw new Exception( 'Empty access token for staff id '.esc_html( $this->staff_id ) );
		}

		// Refresh token already rejected (invalid_grant); skip the blocking retry until reconnect.
		if( ! empty( $access_token['ssa_invalid_grant'] ) ) {
			throw new Exception( 'Google Calendar reconnect required for staff id '.esc_html( $this->staff_id ) );
		}

		if( $this->is_access_token_expired( $access_token ) ) {
			$this->access_token = $this->refresh_access_token( $access_token );
			$this->update_token_in_database();
		} else {
			$this->access_token = $access_token;
		}
	}
	
	private function get_access_token_for_staff_id() {
		// get access token from settings
		if( empty( $this->staff_id ) ) {
			$google_calendar_settings = $this->plugin->google_calendar_settings->get();
			return $google_calendar_settings['access_token'];
		} else {
			$staff = SSA_Staff_Object::instance( $this->staff_id );
			return $staff->google_access_token;
		}
	}
	/**
	 * Quick Connect is assumed to always return a valid access token
	 * This should shortcut the method that sets the access token and just set the access token directly
	 *
	 * @param [type] $staff_id
	 * @return void
	 */
	private function authorize_with_quick_connect() {
		$this->access_token = $this->plugin->google_calendar->get_quick_connect_access_token( $this->staff_id );
		// no need to update the token in database, because get_quick_connect_access_token handles that
	}
	
	private function get_request_headers( ){
		if( empty( $this->access_token ) ) {
			$this->authorize();
		}
		$headers =  array(
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $this->access_token['access_token'],
		);
		return $headers;
	}

	/**
	 * Single point of contact for every Google Calendar data call.
	 *
	 * Since is_access_token_expired() trusts the local mint stamp instead of validating
	 * over the network on each call, an access token that Google kills *inside* its
	 * ~1h local window is only discovered here -- as a 401 on the real request. When
	 * that happens we force ONE refresh (using the still-valid refresh token) and replay
	 * the request once, so the operation completes in the same PHP request with no
	 * disruption and no wait for the next authorize() cycle. Exactly one retry: if the
	 * refresh fails (e.g. invalid_grant, which marks the token for the reconnect banner)
	 * or the replayed request is rejected too, we return that response and let the
	 * caller's existing error handling run unchanged.
	 *
	 * @param string $method   HTTP verb (GET/POST/PUT/DELETE).
	 * @param string $endpoint Fully-built request URL.
	 * @param array  $args     wp_remote_request args EXCEPT headers/method (added here).
	 * @return array|WP_Error  The wp_remote_request() response.
	 */
	private function request( $method, $endpoint, $args = array() ) {
		$args['method']  = $method;
		$args['headers'] = $this->get_request_headers();

		$response = wp_remote_request( $endpoint, $args );

		// Only a 401 (rejected bearer token) is worth a refresh + replay. 403 is
		// quota/permission and 5xx/network are transient -- refreshing fixes neither,
		// so we leave those to the caller exactly as before.
		if ( ! $this->is_response_unauthorized( $response ) ) {
			return $response;
		}

		if ( ! $this->reauthorize_after_rejection() ) {
			return $response;
		}

		// Replay once with the freshly-minted bearer token.
		$args['headers'] = $this->get_request_headers();
		return wp_remote_request( $endpoint, $args );
	}

	/**
	 * True only when Google actively rejected the access token (HTTP 401).
	 *
	 * @param array|WP_Error $response
	 * @return bool
	 */
	private function is_response_unauthorized( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return 401 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Force a single access-token refresh after Google rejected the current one.
	 *
	 * Mirrors the branch authorize() takes, but WITHOUT the local expiry check -- the
	 * token looks fresh locally (that is exactly why the 401 slipped through), so we
	 * must refresh unconditionally.
	 *
	 * We try exactly ONCE per client instance, no matter the outcome. $did_force_refresh
	 * is flipped up front, before the refresh runs, on purpose: whether the refresh
	 * succeeds, fails transiently, or the replay 401s again, this instance never attempts
	 * a second refresh. The one-shot rule -- not the result -- is the guardrail that keeps
	 * a persistently-rejected token from spinning into a refresh/replay loop or API storm.
	 *
	 * @return bool True if a new token was obtained and is ready to replay with.
	 */
	private function reauthorize_after_rejection() {
		// One attempt per instance, outcome-independent -- see the docblock/property above.
		if ( $this->did_force_refresh ) {
			return false;
		}
		$this->did_force_refresh = true;

		$google_calendar_settings = $this->plugin->google_calendar_settings->get();

		// Quick Connect: the relay owns refresh, backoff and persistence. Force a
		// re-fetch (bypassing the "looks fresh locally" cache) so a token the relay or
		// Google killed inside its window is actually replaced. The relay's own backoff
		// still bounds how often this can hit the service.
		if ( ! empty( $google_calendar_settings['quick_connect_gcal_mode'] ) ) {
			$token = $this->plugin->google_calendar->get_quick_connect_access_token( $this->staff_id, true );
			if ( empty( $token ) || empty( $token['access_token'] ) ) {
				return false;
			}
			$this->access_token = $token;
			return true;
		}

		// Own client_id/secret: refresh with the stored refresh token.
		$current = $this->get_access_token_for_staff_id();
		if ( empty( $current ) || ! is_array( $current ) || empty( $current['refresh_token'] ) ) {
			return false;
		}

		// Refresh token already known dead -- the reconnect banner is up; don't re-hit
		// Google (authorize_with_client_id_and_secret() throws on this same flag).
		if ( ! empty( $current['ssa_invalid_grant'] ) ) {
			return false;
		}

		try {
			$this->access_token = $this->refresh_access_token( $current );
			$this->update_token_in_database();
		} catch ( \Throwable $th ) {
			// refresh_access_token() already logged and, on invalid_grant, marked the token.
			return false;
		}

		return true;
	}
	
	/**
	 * Test and confirm that the access token
	 * Makes an API call and confirms that the access token is valid
	 *
	 * @param array $options
	 * @return void
	 */
	public function validate_access_token( array $access_token ) {
		$gcal_api_endpoint = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';
		
		$response = wp_remote_get(
			$gcal_api_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $access_token['access_token'],
				),
				'timeout' => 60
			)
		);
		
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) > 299 ) {
			if( wp_remote_retrieve_response_code( $response ) == 401 ){
				// expired token
				return false;
			}
			ssa_debug_log( print_r( $response, true ), 10); // phpcs:ignore
			throw new Exception( 'Failed to validate Google Calendar access token' );
		}

		return true;
	}

	/**
	 * use in place of ->calendarList->listCalendarList( $options = array() ) {}
	 * this method will return all calendars, not just the first page
	 * 
	 * @since 6.6.5
	 * 
	 * @return array
	 */
	public function get_calendar_list( $options = array() ) {
		$calendar_list = array();
		$gcal_api_endpoint = 'https://www.googleapis.com/calendar/v3/users/me/calendarList' . '?' . $this->get_params_from_options( $options );
		$current_endpoint = $gcal_api_endpoint;
		
		// get all pages of calendar list
		while(true){
			try {
				$response = $this->request(
					'GET',
					$current_endpoint,
					array(
						'timeout' => 60
					)
				);

				if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
					ssa_debug_log( print_r( $response, true ), 10); // phpcs:ignore
					return false;
				}
				
				$data = json_decode( wp_remote_retrieve_body( $response ) );
				
				// add calendar list to array
				$calendar_list = array_merge( $calendar_list, $data->items );
				
				if(empty($data->items)){
					ssa_debug_log( 'No calendars found in calendar list', 10 );
					ssa_debug_log( print_r( $response, true ), 10); // phpcs:ignore
				}
				
				if ( empty( $data->nextPageToken ) ) {
					break;
				} else {
					$current_endpoint = $gcal_api_endpoint . '&pageToken=' . $data->nextPageToken;
				}
			} catch ( \Throwable $th ) {
				ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
				break;
			}
		}
		
		// Success
		// return calendar list
		return $calendar_list;
	}
	
	/**
	 * 
	 * use in place of ->calendarList->get( $calendar_id, $options = array() ) {}
	 */
	public function get_calendar_from_calendar_list ( $calendar_id, $options = array() ) {
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/users/me/calendarList/" . urlencode( $calendar_id ) . "?" . $this->get_params_from_options( $options );
		try {
			$response = $this->request(
				'GET',
				$gcal_api_endpoint,
				array(
					'timeout' => 60
				)
			);

			// we don't want to log 404 errors, because we expect them if the calendar is not found
			if ( is_wp_error( $response ) || ( wp_remote_retrieve_response_code( $response ) > 299 && wp_remote_retrieve_response_code( $response ) != 404 ) ) {
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}
			
			$data = json_decode( wp_remote_retrieve_body( $response ) );
			// Success
			return $data;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}
	
	/**
	 * use in place of ->events->listEvents( $calendar_id, $options = array() ) {}
	 
	 */
	public function get_events_from_calendar( $calendar_id, $options = array() ) {
		// if is a holiday caledar, pull events in english locale so that we have a way to identiy public holidays
		if( false !== strpos( $calendar_id, 'holiday' ) ){
			$calendar_id_parts = explode( '.', $calendar_id );
			array_shift( $calendar_id_parts );
			$calendar_id = 'en.' . implode( '.', $calendar_id_parts );
		}
		
		// exclude workingLocation events - these are not useful for availability
		$event_types_query = 'eventTypes=default&eventTypes=outOfOffice&eventTypes=focusTime&eventTypes=fromGmail';
		
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events?" . $event_types_query . '&' . $this->get_params_from_options( $options );

		try {
			$response = $this->request(
				'GET',
				$gcal_api_endpoint,
				array(
					'timeout' => 60
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				if( wp_remote_retrieve_response_code($response) == 404 ){
					ssa_debug_log( 'Received 404, getting events for ' . $calendar_id . " from " . $gcal_api_endpoint . " working with staff id " . $this->staff_id ); // phpcs:ignore
					ssa_debug_log( ssa_get_stack_trace(), 10 );
				} else {
					ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				}
				return [];
			}
			
			$data = json_decode( wp_remote_retrieve_body( $response ) );
			
			// Success
			return $data->items;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return [];
		}
	}
	
	/**
	 * 
	 * use in place of ->events->insert( $calendar_id, $event, $options = array() ) {}
	 * 
	 */
	public function insert_event_into_calendar( $calendar_id, $event, $options = array() ) {
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events?" . $this->get_params_from_options( $options );

		try {
			$response = $this->request(
				'POST',
				$gcal_api_endpoint,
				array(
					'timeout' => 60,
					'body' => json_encode($event),
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}

			$event = json_decode(wp_remote_retrieve_body($response) );
			
			// Success
			// return event ID
			return $event;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}
	
	
	/**
	 * 
	 * use in place of ->events->get( $calendar_id, $event_id, $options = array() ) {}
	 * 
	 */
	public function get_event_from_calendar( $calendar_id, $event_id, $options = array() ) {
		if(empty($calendar_id) || empty($event_id)){
			ssa_debug_log( 'Warning: called get_event_from_calendar with calendar_id:' . $calendar_id . ' & event_id:' . $event_id , 10 );
			return false;
		}
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events/" . $event_id . "?" . $this->get_params_from_options( $options );

		try {
			$response = $this->request(
				'GET',
				$gcal_api_endpoint,
				array(
					'timeout' => 60
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response) );

			// Success
			return $data;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}

	/**
	 * Same read as get_event_from_calendar(), but keeps the status code and body.
	 *
	 * For diagnostics an HTTP error IS the answer -- a 404 means the event is gone
	 * from Google while a cached row still claims it blocks time -- so collapsing
	 * every failure to false, as get_event_from_calendar() does, discards the result.
	 */
	public function get_event_from_calendar_raw( $calendar_id, $event_id, $options = array() ) {
		$result = array(
			'http_code' => 0,
			'body'      => null,
			'error'     => '',
		);

		if ( empty( $calendar_id ) || empty( $event_id ) ) {
			$result['error'] = 'Missing calendar_id or event_id.';
			return $result;
		}

		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events/" . urlencode( $event_id ) . "?" . $this->get_params_from_options( $options );

		try {
			// Through request() so a token Google killed inside its local window (401) is
			// refreshed and the read replayed once -- otherwise the reclaim loop would count
			// a recoverable event as unverified. request() only retries on 401, so the 404
			// (event gone) this function reports on purpose is passed straight through.
			$response = $this->request(
				'GET',
				$gcal_api_endpoint,
				array(
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				return $result;
			}

			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			$result['body']      = json_decode( wp_remote_retrieve_body( $response ) );
		} catch ( \Throwable $th ) {
			$result['error'] = $th->getMessage();
		}

		return $result;
	}


	/**
	 *
	 * use in place of ->events->update( $calendar_id, $event_id, $event_updated, $options = array() ) { }
	 */
	public function update_event_in_calendar( $calendar_id, $event_id, $event_updated, $options = array() ) {
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events/" . $event_id . "?" . $this->get_params_from_options( $options );

		try {
			$response = $this->request(
				'PUT',
				$gcal_api_endpoint,
				array(
					'timeout' => 60,
					'body' => json_encode( $event_updated )
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response) );

			// Success
			return $data;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}
	
	
	/**
	 * use in place of ->events->delete( $calendar_id, $event_id, $options = array() ) {}
	 */
	public function delete_event_from_calendar( $calendar_id, $event_id, $options = array() ) {
		$gcal_api_endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode( $calendar_id ) . "/events/" . $event_id . "?" . $this->get_params_from_options( $options );

		try {
			$response = $this->request(
				'DELETE',
				$gcal_api_endpoint,
				array(
					'timeout' => 60
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}
			
			// Success
			// the delete method returns an empty body
			return true;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}
	
	/**
	 * description: this is the same logic used by the PHP OAuth client
	 * with a minor difference, this takes the $token as an argument
	 * 
	 * @param array $token
	 * @return bool Returns True if the access_token is expired.
	 */
	public function is_access_token_expired( $token ) {
		if ( !$token ) {
			return true;
		}
		
		if ( is_object( $token ) ) {
			$token = (array) $token;
		}

		// if less than 300 seconds remaining, refresh the token anyways
		$buffer = 300;
		$expires_in = 3599; // access tokens usually expire in 3599 seconds

		// Fast path: a LOCAL mint stamp lets us decide validity in BOTH directions with
		// no Google round-trip. `ssa_fetched_at` is written on every path a token enters
		// the plugin (refresh, initial OAuth exchange, quick-connect cache) using the same
		// local clock as the `time()` comparison below, so it is safe to declare a token
		// valid from it. Gate STRICTLY on this local stamp — never on `created` / the
		// id_token `iat` (remote-clock values) — because trusting a remote timestamp to
		// declare a token "valid" can re-open the clock-skew bug in the dangerous
		// direction. Strict when declaring "valid"; liberal when declaring "expired" (a
		// refresh is cheap and safe). Restores parity with the standard Google OAuth
		// client, which trusts local expiry instead of validating over the network on
		// every call (the per-call validate_access_token() below was the deviation).
		if ( isset( $token['ssa_fetched_at'] ) ) {
			return ( $token['ssa_fetched_at'] + $expires_in - $buffer ) < time();
		}

		// Legacy tokens (no local stamp): keep prior behavior — short-circuit obvious
		// expiry from the remote-clock `created` / id_token `iat`, else fall back to a
		// one-off network validation. These self-heal on the next refresh (<=1h), which
		// stamps `ssa_fetched_at` and moves them onto the fast path above.
		$created = 0;
		if ( isset( $token['created'] ) ) {
			$created = $token['created'];
		} elseif ( isset( $token['id_token'] ) ) {
			// check the ID token for "iat"
			// signature verification is not required here, as we are just
			// using this for convenience to save a round trip request
			// to the Google API server
			$idToken = $token['id_token'];
			if ( substr_count( $idToken, '.' ) == 2 ) {
				$parts   = explode( '.', $idToken );
				$payload = json_decode( base64_decode( $parts[1] ), true );
				if ( $payload && isset( $payload['iat'] ) ) {
					$created = $payload['iat'];
				}
			}
		}

		if( $created > 0 ){
			if( $created + $expires_in - $buffer < time() ){
				// consider expired to stay on the safe side
				return true;
			}
		}

		// invert
		return ! $this->validate_access_token( $token );
	}
	
	/**
	 * Exchange the refresh token for an access token
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $refresh_token
	 * @return bool|array
	 */
	private function exchange_refresh_token( $client_id, $client_secret, $refresh_token ){
		$gcal_api_endpoint = 'https://www.googleapis.com/oauth2/v4/token';
		
		try {
			$response = wp_remote_post(
				$gcal_api_endpoint,
				array(
					'body' => array(
						'refresh_token' => $refresh_token,
						'client_id' => $client_id,
						'client_secret' => $client_secret,
						'grant_type' => 'refresh_token',
						// return also the refresh token
						'access_type' => 'offline',
					),
					'timeout' => 60
				)
			);

			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				if ( ! is_wp_error( $response ) ) {
					// invalid_grant = dead refresh token, not a transient failure.
					$error_body = json_decode( wp_remote_retrieve_body( $response ), true );
					if ( ! empty( $error_body['error'] ) && 'invalid_grant' === $error_body['error'] ) {
						$this->refresh_token_invalid = true;
					}
				}
				ssa_debug_log( print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);

			if( empty( $data['refresh_token'] ) ) {
				// attach the refresh token to the access token
				$data['refresh_token'] = $refresh_token;
			}
			
			// Success
			return $data;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}
	}
	
	/**
	 * We never call this with the quick connect flow, because we don't have a refresh token
	 *
	 * @return void
	 */
	private function refresh_access_token($access_token) {
		$client_id = $this->client_id;
		$client_secret = $this->client_secret;
		$refresh_token = $access_token['refresh_token'];
		$response = $this->exchange_refresh_token( $client_id, $client_secret, $refresh_token );
		if( empty( $response ) || ! is_array( $response ) || empty( $response['access_token'] ) ) {
			ssa_debug_log( 'Failed to refresh access token for staff id ' . (string) $this->staff_id . print_r($response, true), 10); // phpcs:ignore
			if ( $this->refresh_token_invalid ) {
				$this->mark_invalid_grant();
			}
			throw new Exception( 'Failed to refresh access token' );
		}
		// Local mint stamp for clock-drift-tolerant expiry — see is_access_token_expired().
		$response['ssa_fetched_at'] = time();
		return $response;
	}

	// Stamp the stored token so it surfaces the "needs reconnect" notice and skips further
	// retries. Lives inside access_token, so it clears when the token is replaced/emptied.
	private function mark_invalid_grant() {
		if ( empty( $this->staff_id ) ) {
			$google_calendar_settings = $this->plugin->google_calendar_settings->get();
			if ( ! empty( $google_calendar_settings['access_token'] ) && is_array( $google_calendar_settings['access_token'] ) ) {
				$google_calendar_settings['access_token']['ssa_invalid_grant'] = time();
				$this->plugin->google_calendar_settings->update( array( 'access_token' => $google_calendar_settings['access_token'] ) );
			}
			return;
		}

		if ( empty( $this->plugin->staff_model ) || $this->plugin->staff_model instanceof SSA_Missing ) {
			return;
		}

		$staff = $this->plugin->staff_model->get( $this->staff_id );
		if ( ! empty( $staff['google_access_token'] ) && is_array( $staff['google_access_token'] ) ) {
			$staff['google_access_token']['ssa_invalid_grant'] = time();
			$this->plugin->staff_model->update( $this->staff_id, array( 'google_access_token' => $staff['google_access_token'] ) );
		}
	}

	private function update_token_in_database(){
		$staff_id = $this->staff_id;
		$access_token = $this->access_token;
		if(empty($staff_id)){
			if(empty($access_token['refresh_token'])){
				// log that we received an access token without a refresh token
				ssa_debug_log('Received an access token without a refresh token ' . ' for staff id ' . (string) $staff_id . print_r($access_token, true), 10 ); // phpcs:ignore
				$google_calendar_settings = $this->plugin->google_calendar_settings->get();
				$access_token['refresh_token'] = !empty($google_calendar_settings['access_token']['refresh_token']) ? $google_calendar_settings['access_token']['refresh_token'] : '';
			}
			$this->plugin->google_calendar_settings->update( array( 'access_token' => $access_token ) );
		} else {
			if(empty($access_token['refresh_token'])){
				// log that we received an access token without a refresh token
				ssa_debug_log('Received an access token without a refresh token ' . ' for staff id ' . (string) $staff_id . print_r($access_token, true), 10 ); // phpcs:ignore
				$staff = $this->plugin->staff_model->get( $staff_id );
				$access_token['refresh_token'] = !empty($staff['google_access_token']['refresh_token']) ? $staff['google_access_token']['refresh_token'] : '';
			}
			$this->plugin->staff_model->update( $staff_id, array(
				'google_access_token' => $access_token,
			) );
		}
	}
	
	public function get_auth_url( $staff_id, $wp_next_ssa_uri = null, $wp_next_base_uri = null ) {
		$this->client_init();
		$gcal_api_endpoint = 'https://accounts.google.com/o/oauth2/auth?';
		// need to store the exact home url returned at this point
		// because some plugins can affect the home url, causing the quick-connect domain to be invalid
		$site_home_url = get_home_url();
		$this->plugin->google_calendar_settings->update( array(
			'quick_connect_home_url' => $site_home_url,
		) );
		
		$license_settings =	$this->plugin->license_settings->get();
		$license = '';
		
		// https://accounts.google.com/o/oauth2/auth?
		$params = array(
			'response_type'=>'code',
			'client_id'=> $this->client_id,
			'redirect_uri'=> $this->plugin->google_calendar->get_redirect_uri(),
			'scope'=> 'https://www.googleapis.com/auth/calendar openid',
			'approval_prompt'=>'force',
			'access_type'=>'offline',
		);
		
		if ( empty( $wp_next_ssa_uri ) ) {
			$wp_next_ssa_uri = 'ssa/settings/google-calendar';
		}
		if ( empty( $wp_next_base_uri ) ) {
			$wp_next_base_uri = $this->plugin->wp_admin->url();
		}
		
		$params['state'] = strtr( base64_encode( json_encode( array(
			'authorize' => 'google',
			'staff_id' => $staff_id,
			'staff_token' => SSA_Utils::site_unique_hash( $staff_id ),
			'token' => $license,
			'redirect_uri' => $this->plugin->google_calendar->get_redirect_uri(),
			'wp_callback_uri' => $this->plugin->google_calendar::get_wp_callback_uri(),
			'wp_next_ssa_uri' => $wp_next_ssa_uri,
			'wp_next_base_uri' => $wp_next_base_uri, // grab from the parent page (example: /my-account/), like we do for booking_url in booking-app
			// used for ssa_quick_connect - staff_id as well
			'domain' => $site_home_url,
			'license_key'=> $license_settings['license_filtered'],
		) ) ), '+/=', '-_,' );
		
		return $gcal_api_endpoint . $this->get_params_from_options( $params );
	}
	
	/**
	 * 
	 * @since 6.6.5
	 * 
	 * @return bool
	 
	 */
	public function exchange_auth_code( $code ) {
		$this->client_init();
		$gcal_api_endpoint = 'https://www.googleapis.com/oauth2/v4/token?';
		$params = array(
			'code' => $code,
			'grant_type' => 'authorization_code',
			'client_id' => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri' => $this->plugin->google_calendar->get_redirect_uri(),
		);
		
		
		try {
			$response = wp_remote_post( $gcal_api_endpoint . $this->get_params_from_options( $params ), array(
				'timeout' => 20
			));
			
			if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) > 299 ) {
				throw new \Throwable( $response );
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);

			if ( empty( $data ) || ! is_array( $data ) || empty( $data['access_token'] ) ) {
				ssa_debug_log( 'Failed to exchange auth code for staff id ' . (string) $this->staff_id . print_r( $response, true ), 10 ); // phpcs:ignore
				return false;
			}

			// Local mint stamp for clock-drift-tolerant expiry — see is_access_token_expired().
			$data['ssa_fetched_at'] = time();
			$this->access_token = $data;

			return true;
		} catch ( \Throwable $th ) {
			ssa_debug_log( print_r( $th, true ), 10 ); // phpcs:ignore
			return false;
		}

	}
	
	public function get_exchange_response() {
		return $this->access_token;
	}
	
	public function get_access_token()
	{
	  return $this->access_token;
	}
	
	public function get_params_from_options ( $options ) {
		if( empty( $options ) ) {
			return '';
		}
		$params_string = '';
		// avoid $this->get_params_from_options( $options ); it will convert true to 1, and google api does not like that
		foreach( $options as $key => $value ) {
			// if boolean replace with string equivalent
			if( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$params_string .= http_build_query([$key => $value]) . '&';
		}
		return $params_string;
	}
	
	public function revoke_token( $token ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/revoke',
			array(
				'body' => array(
					'token' => $token,
				),
			)
		);
		
	}
}
