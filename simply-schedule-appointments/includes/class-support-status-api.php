<?php
/**
 * Simply Schedule Appointments Support Status Api.
 *
 * @since   2.1.6
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Support Status Api.
 *
 * @since 2.1.6
 */
class SSA_Support_Status_Api extends WP_REST_Controller {
	/**
	 * Widest window the external-events viewer will read in one request, in seconds.
	 * The viewer renders a 6-week grid plus a day of padding on each side.
	 */
	const EXTERNAL_EVENTS_MAX_RANGE = 62 * DAY_IN_SECONDS;

	/**
	 * Hard cap on rows returned by the external-events viewer. Bounds the read so a
	 * calendar with a pathological number of events cannot exhaust memory.
	 */
	const EXTERNAL_EVENTS_MAX_EVENTS = 2000;

	/**
	 * Parent plugin class
	 *
	 * @var   class
	 * @since 1.0.0
	 */
	protected $plugin = null;

	/**
	 * Constructor
	 *
	 * @since  1.0.0
	 * @param  object $plugin Main plugin object.
	 * @return void
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	/**
	 * Initiate our hooks
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		$this->register_routes();
	}


	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		$version = '1';
		$namespace = 'ssa/v' . $version;
		$base = 'support_status';
		register_rest_route( $namespace, '/' . $base, array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support_ticket', array(
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'create_support_ticket' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support_debug/wp', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_wp_debug_log_content' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support_debug/wp/delete', array(
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'empty_wp_debug_log_content' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );


		register_rest_route( $namespace, '/' . 'support_debug/ssa', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_ssa_debug_log_content' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support_debug/ssa/delete', array(
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'empty_ssa_debug_log_content' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support_debug/logs', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_debug_log_urls' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/export', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_export_code' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/import', array(
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'import_data_api' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(

				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/external_events', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_external_events' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(
					'appointment_type_id' => array(
						'required' => true,
					),
					'start' => array(
						'required' => true,
					),
					'end' => array(
						'required' => true,
					),
				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/external_event_probe', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_external_event_probe' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(
					'appointment_type_id' => array(
						'required' => true,
					),
					'calendar_id' => array(
						'required' => true,
					),
					'event_id' => array(
						'required' => true,
					),
				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/external_event_drift', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'scan_external_event_drift' ),
				'permission_callback' => array( $this, 'reclaim_external_event_permissions_check' ),
				'args'            => array(
					'appointment_type_id' => array(
						'required' => true,
					),
				),
			),
		) );

		register_rest_route( $namespace, '/' . 'support/external_event_reclaim', array(
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'reclaim_external_event' ),
				'permission_callback' => array( $this, 'reclaim_external_event_permissions_check' ),
				'args'            => array(
					'appointment_type_id' => array(
						'required' => true,
					),
					'calendar_id' => array(
						'required' => true,
					),
					'event_id' => array(
						'required' => true,
					),
					'row_id' => array(
						'required' => true,
					),
					'confirm' => array(
						'required' => true,
					),
				),
			),
		) );

		register_rest_route(
			$namespace,
			'/fetch-guides',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_guides' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route( $namespace, '/user/check', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'check_user_login_status' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function check_user_login_status( $request ) {

		$data = array(
			'is_user_logged_in' => is_user_logged_in(),
		);
		
		$response = array(
			'response_code' => 200,
			'error' => '',
			'data' => $data
		);

		return new WP_REST_Response( $response, 200 );
	}


	public function create_support_ticket( $request ) {
		// queue the minimal ticket sending here
		$params = $request->get_params();
		$debug_logs_hash = SSA_Debug::get_site_unique_hash_for_debug_logs();
		ssa_schedule_single_action( time() + 30, 'ssa/support/send_minimal_support_ticket', array( 'params' => [...$params, 'export_code' => '{}', 'debug_logs_hash' => $debug_logs_hash] ) );
		
		if ( ! empty( $params['include_active_plugins'] ) ) {
			$params['active_plugins'] = array();
			$active_plugins = get_option( 'active_plugins' );
			sort( $active_plugins );
			foreach ($active_plugins as $active_plugin) {
				if ( strpos( $active_plugin, '/' ) ) {
					$active_plugin = substr( $active_plugin, 0, strpos( $active_plugin, '/' ) );
				}
				$params['active_plugins'][] = $active_plugin;
			}
			unset( $params['include_active_plugins'] );
		}

		if ( ! empty( $params['include_settings'] ) ) {
			$params['site_hash_for_debug_logs'] = $debug_logs_hash;
			unset( $params['include_settings'] );
		}
		
		$result = self::ssa_send_support_ticket( $params, $debug_logs_hash );
		if ( !is_wp_error( $result ) ) {
			// remove the queued ticket from the queue
			ssa_unschedule_all_actions( 'ssa/support/send_minimal_support_ticket' );
		}
		return $result;
	}
	public static function ssa_send_support_ticket($params = array(), $debug_logs_hash = '') {
		$response = wp_remote_post( 'https://api.simplyscheduleappointments.com/support_ticket/', array(
		    'sslverify' => false,
			'headers' => array(
				'content-type' => 'application/json',
			),
			'body' => json_encode( $params ),
		) );
		
		$response_code = wp_remote_retrieve_response_code($response);
		if( $response_code > 299 || $response_code < 200 ) {
			ssa_debug_log( "Failed to submit support ticket - invalid response code - response: " .print_r ( $response, true), 100 ); //phpcs:ignore
			return new WP_Error( 'failed_submission', __( 'Your support ticket failed to be sent, please send details to support@ssaplugin.com',  'simply-schedule-appointments' ), $debug_logs_hash );
		}
		
		$response = wp_remote_retrieve_body( $response );
		if ( empty( $response ) ) {
			ssa_debug_log( "Failed to submit support ticket - response empty - response: " .print_r ( $response, true), 100 ); //phpcs:ignore
			return new WP_Error( 'empty_response', __( 'No response', 'simply-schedule-appointments' ), $debug_logs_hash );
		}
		$response = json_decode( $response, true );
		if ( ! is_array( $response ) ) {
			$response = json_decode( $response, true );
		}

		if ($response['status'] != 'success' ) {
			ssa_debug_log( "Failed to submit support ticket - status != success - response: " .print_r ( $response, true), 100 ); //phpcs:ignore
			return new WP_Error( 'failed_submission', __( 'Your support ticket failed to be sent, please send details to support@ssaplugin.com', 'simply-schedule-appointments' ), $debug_logs_hash );
		}
		return $response;
	}

	public function get_items_permissions_check( $request ) {
		return current_user_can( 'ssa_manage_site_settings' );
	}

	/**
	 * Return the cached external (Google) calendar events overlapping a date range,
	 * for the calendars a single appointment type actually checks.
	 *
	 * Reads only the availability_external cache table, which exists on every
	 * edition. Times are stored and returned in UTC.
	 */
	public function get_external_events( $request ) {
		$params = $request->get_params();

		$start = isset( $params['start'] ) ? sanitize_text_field( $params['start'] ) : '';
		$end   = isset( $params['end'] ) ? sanitize_text_field( $params['end'] ) : '';

		$appointment_type_id = isset( $params['appointment_type_id'] ) ? absint( $params['appointment_type_id'] ) : 0;
		if ( empty( $appointment_type_id ) ) {
			return new WP_Error(
				'ssa_external_events_missing_appointment_type',
				__( 'An appointment type is required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		$appointment_type = $this->plugin->appointment_type_model->get( $appointment_type_id );
		if ( empty( $appointment_type['id'] ) ) {
			return new WP_Error(
				'ssa_external_events_invalid_appointment_type',
				__( 'That appointment type could not be found.', 'simply-schedule-appointments' ),
				array( 'status' => 404 )
			);
		}

		$period = $this->build_external_events_period( $start, $end );
		if ( is_wp_error( $period ) ) {
			return $period;
		}

		$scope = $this->get_calendars_for_appointment_type( $appointment_type_id );
		if ( empty( $scope ) ) {
			return array(
				'response_code'    => 200,
				'count'            => 0,
				'total'            => 0,
				'truncated'        => false,
				'complete_through' => '',
				'events'           => array(),
				'calendars'        => array(),
			);
		}

		// Fetch one row past the cap purely as a probe: at exactly the cap a `>=` test
		// cannot tell "the window ended here" from "we ran out of room", and guessing
		// truncation greys out a swath of days the viewer had, in fact, fully loaded.
		$rows = $this->plugin->availability_external_model->query( array(
			'number'              => self::EXTERNAL_EVENTS_MAX_EVENTS + 1,
			'orderby'             => 'start_date',
			'order'               => 'ASC',
			'calendar_id_hash_IN' => array_map( 'ssa_int_hash', array_keys( $scope ) ),
			'intersects_period'   => $period,
		) );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$truncated = count( $rows ) > self::EXTERNAL_EVENTS_MAX_EVENTS;
		if ( $truncated ) {
			array_pop( $rows );
		}

		$events = array();
		foreach ( $rows as $row ) {
			// calendar_id_hash is a crc32, so the query can match a colliding calendar.
			if ( empty( $row['calendar_id'] ) || ! isset( $scope[ $row['calendar_id'] ] ) ) {
				continue;
			}

			$events[] = array(
				'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'service'      => isset( $row['service'] ) ? $row['service'] : '',
				'staff_id'     => isset( $row['staff_id'] ) ? (int) $row['staff_id'] : 0,
				'calendar_id'  => isset( $row['calendar_id'] ) ? $row['calendar_id'] : '',
				'start_date'   => isset( $row['start_date'] ) ? $row['start_date'] : '',
				'end_date'     => isset( $row['end_date'] ) ? $row['end_date'] : '',
				'is_all_day'   => ! empty( $row['is_all_day'] ) ? 1 : 0,
				'is_available' => ! empty( $row['is_available'] ) ? 1 : 0,
				'transparency' => isset( $row['transparency'] ) ? $row['transparency'] : '',
				'status'       => isset( $row['status'] ) ? $row['status'] : '',
				'event_id'     => isset( $row['event_id'] ) ? $row['event_id'] : '',
				'date_modified' => isset( $row['date_modified'] ) ? $row['date_modified'] : '',
			);
		}

		$calendars = $this->get_external_calendar_summary( $scope, $period );

		$total = 0;
		foreach ( $calendars as $calendar ) {
			$total += (int) $calendar['count'];
		}

		// The cap is applied after ORDER BY start_date ASC, so what gets dropped is always
		// the tail of the window. Report the last start_date we actually reached: without it
		// the viewer draws the dropped days as empty, which reads as "nothing blocks these
		// days" -- the opposite of the truth. Events sharing that start_date can straddle the
		// cap, so the boundary itself is only "possibly incomplete", not "complete".
		$complete_through = '';
		if ( $truncated && ! empty( $rows ) ) {
			$last_row         = end( $rows );
			$complete_through = isset( $last_row['start_date'] ) ? $last_row['start_date'] : '';
		}

		return array(
			'response_code'    => 200,
			'count'            => count( $events ),
			'total'            => $total,
			'truncated'        => $truncated,
			'complete_through' => $complete_through,
			'events'           => $events,
			'calendars'        => $calendars,
		);
	}

	/**
	 * Read one event straight from Google, live, and report whether SSA's own-event
	 * skip would have matched it.
	 *
	 * Touches no SSA table in either direction: the cached row is what is under
	 * suspicion, so answering from it would beg the question. The skip in
	 * SSA_Google_Calendar::get_events_from_calendar() turns on a single equality
	 * between the event's stored ssa_home_id and SSA_Utils::get_home_id(), and that
	 * marker is written once at booking time and never revisited -- so a site whose
	 * home URL or AUTH_SALT changed leaves every earlier event unmatchable. This
	 * reports both sides of that comparison rather than just its result.
	 */
	public function get_external_event_probe( $request ) {
		$params = $request->get_params();

		$calendar_id         = isset( $params['calendar_id'] ) ? sanitize_text_field( $params['calendar_id'] ) : '';
		$event_id            = isset( $params['event_id'] ) ? sanitize_text_field( $params['event_id'] ) : '';
		$appointment_type_id = isset( $params['appointment_type_id'] ) ? absint( $params['appointment_type_id'] ) : 0;
		// Which staff token reads the calendar is derived from scope below, never from
		// the request -- the client should not get to pick whose credentials are used.
		$staff_id            = 0;

		if ( empty( $calendar_id ) || empty( $event_id ) ) {
			return new WP_Error(
				'ssa_external_event_probe_missing_args',
				__( 'A calendar and an event are required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		// Google Calendar is stripped from the Basic build, leaving an SSA_Missing stub
		// whose every method returns null -- truthy, so empty() would not catch it.
		if ( ! class_exists( 'SSA_Google_Calendar_Client' ) || $this->plugin->google_calendar_client instanceof SSA_Missing ) {
			return new WP_Error(
				'ssa_external_event_probe_unavailable',
				__( 'Google Calendar is not available on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 501 )
			);
		}

		// Only calendars this appointment type actually checks, so the route cannot be
		// turned into a reader for arbitrary calendars on the connected account.
		$scope = $this->get_calendars_for_appointment_type( $appointment_type_id );
		if ( ! isset( $scope[ $calendar_id ] ) ) {
			return new WP_Error(
				'ssa_external_event_probe_calendar_out_of_scope',
				__( 'That calendar is not checked by this appointment type.', 'simply-schedule-appointments' ),
				array( 'status' => 403 )
			);
		}

		// A staff-owned calendar is readable only with that staff member's own token.
		if ( empty( $staff_id ) && 'site' !== $scope[ $calendar_id ]['owner'] && ! empty( $scope[ $calendar_id ]['staff_ids'] ) ) {
			$staff_id = (int) $scope[ $calendar_id ]['staff_ids'][0];
		}

		try {
			$client = $this->plugin->google_calendar_client->service_init( $staff_id );
		} catch ( Exception $e ) {
			return new WP_Error(
				'ssa_external_event_probe_auth_failed',
				$e->getMessage(),
				array( 'status' => 502 )
			);
		}

		$raw   = $client->get_event_from_calendar_raw( $calendar_id, $event_id );
		$event = isset( $raw['body'] ) ? $raw['body'] : null;

		$calendar_access = '';
		try {
			$calendar        = $client->get_calendar_from_calendar_list( $calendar_id );
			$calendar_access = isset( $calendar->accessRole ) ? $calendar->accessRole : '';
		} catch ( Exception $e ) {
			$calendar_access = '';
		}

		$marker       = '';
		$marker_scope = '';
		if ( ! empty( $event->extendedProperties->shared->ssa_home_id ) ) {
			$marker       = $event->extendedProperties->shared->ssa_home_id;
			$marker_scope = 'shared';
		} elseif ( ! empty( $event->extendedProperties->private->ssa_home_id ) ) {
			$marker       = $event->extendedProperties->private->ssa_home_id;
			$marker_scope = 'private';
		}

		$current_home_id = SSA_Utils::get_home_id();
		$marker_matches  = ( ! empty( $marker ) && $marker === $current_home_id );

		if ( ! empty( $raw['error'] ) ) {
			$verdict = 'request_failed';
		} elseif ( 404 === (int) $raw['http_code'] ) {
			$verdict = 'event_missing';
		} elseif ( (int) $raw['http_code'] > 299 || empty( $event ) ) {
			$verdict = 'request_failed';
		} elseif ( 'freeBusyReader' === $calendar_access ) {
			// Google strips extendedProperties at this access level and the skip is
			// gated off entirely, so SSA cannot recognise its own events here at all.
			$verdict = 'access_hides_marker';
		} elseif ( empty( $marker ) ) {
			$verdict = 'marker_absent';
		} elseif ( ! $marker_matches ) {
			$verdict = 'marker_mismatch';
		} else {
			$verdict = 'would_skip';
		}

		// The same evaluator the write route authorises against, so the button can
		// never be offered for an event the server would refuse to claim.
		$reclaim = $this->evaluate_external_event_reclaim( $calendar_id, $event_id, $appointment_type_id, $event, $calendar_access );

		// The probe is readable at the support-screen capability, but the reclaim write
		// needs the stricter pair. Report whether THIS user could actually reclaim, so
		// the UI never offers a button that the write route would 403.
		$can_reclaim = (bool) $this->reclaim_external_event_permissions_check( $request );

		return array(
			'response_code'    => 200,
			'verdict'          => $verdict,
			'calendar_id'      => $calendar_id,
			'event_id'         => $event_id,
			'staff_id'         => $staff_id,
			'http_code'        => (int) $raw['http_code'],
			'error'            => $raw['error'],
			'calendar_access'  => $calendar_access,
			'marker'           => $marker,
			'marker_scope'     => $marker_scope,
			'marker_matches'   => $marker_matches,
			'current_home_id'  => $current_home_id,
			'home_url'         => get_home_url(),
			'would_be_skipped' => ( 'freeBusyReader' !== $calendar_access ) && $marker_matches,
			'reclaimable'      => $reclaim['reclaimable'],
			'reclaim_reason'   => $reclaim['reason'],
			'can_reclaim'      => $can_reclaim,
			'appointment_id'   => ! empty( $reclaim['appointment']['id'] ) ? (int) $reclaim['appointment']['id'] : 0,
			'event'            => $event,
		);
	}

	/**
	 * Decide whether this site may claim ownership of a live Google event.
	 *
	 * Ownership is proven by a local appointment carrying this exact
	 * (calendar_id, event_id) pair -- never by the marker on the event. When a site
	 * defines neither SSA_AUTH_SALT nor AUTH_SALT, SSA_Utils::site_unique_hash()
	 * falls back to a salt shipped in the plugin source, so the marker cannot
	 * distinguish our own stale value from another SSA site's current one.
	 *
	 * Claiming an event SSA did not create is the dangerous direction: SSA would
	 * stop treating a real commitment as busy and book someone on top of it.
	 * Every check below therefore fails closed.
	 *
	 * The full flow (steps 10-20 here) is documented in
	 * documentation/external-event-reclaim-workflow.md.
	 *
	 * @return array reclaimable bool, reason string, appointment array
	 */
	protected function evaluate_external_event_reclaim( $calendar_id, $event_id, $appointment_type_id, $event, $calendar_access ) {
		$deny = array( 'reclaimable' => false, 'reason' => '', 'appointment' => array() );

		if ( ! is_string( $event_id ) || '' === $event_id || ! is_string( $calendar_id ) || '' === $calendar_id ) {
			$deny['reason'] = 'missing_identifiers';
			return $deny;
		}

		$scope = $this->get_calendars_for_appointment_type( $appointment_type_id );
		if ( ! isset( $scope[ $calendar_id ] ) ) {
			$deny['reason'] = 'calendar_out_of_scope';
			return $deny;
		}

		// Google omits extendedProperties at this access level, so the marker could
		// not be read back even if we wrote it.
		if ( 'freeBusyReader' === $calendar_access ) {
			$deny['reason'] = 'access_hides_marker';
			return $deny;
		}

		if ( empty( $event ) || empty( $event->id ) ) {
			$deny['reason'] = 'event_unreadable';
			return $deny;
		}

		// class-appointment-model.php:939 tests isset(), not ! empty(), so an empty
		// event id here would match every appointment that has never synced.
		$matches = $this->plugin->appointment_model->query( array(
			'google_calendar_event_id' => $event_id,
			'number'                   => 5,
		) );
		if ( ! is_array( $matches ) ) {
			$matches = array();
		}

		$confirmed = array();
		foreach ( $matches as $row ) {
			if ( ! isset( $row['google_calendar_event_id'], $row['google_calendar_id'] ) ) {
				continue;
			}
			if ( (string) $row['google_calendar_event_id'] !== (string) $event_id ) {
				continue;
			}
			if ( (string) $row['google_calendar_id'] !== (string) $calendar_id ) {
				continue;
			}
			$confirmed[] = $row;
		}

		if ( empty( $confirmed ) ) {
			$deny['reason'] = 'no_local_appointment';
			return $deny;
		}
		if ( count( $confirmed ) > 1 ) {
			$deny['reason'] = 'ambiguous_local_appointment';
			return $deny;
		}

		$appointment = $confirmed[0];

		if ( ! empty( $appointment['status'] ) && SSA_Appointment_Model::is_a_canceled_status( $appointment['status'] ) ) {
			$deny['reason'] = 'appointment_canceled';
			return $deny;
		}

		if ( empty( $appointment['start_date'] ) || strtotime( $appointment['start_date'] ) < ( time() - DAY_IN_SECONDS ) ) {
			$deny['reason'] = 'appointment_in_the_past';
			return $deny;
		}

		// SSA stamps shared.appointment_id on every event it writes, so a genuine SSA
		// event always carries it. Group events store the group id there instead
		// (class-google-calendar.php:881).
		if ( empty( $event->extendedProperties->shared->appointment_id ) ) {
			$deny['reason'] = 'event_not_marked_by_ssa';
			return $deny;
		}

		$claimed    = (int) $event->extendedProperties->shared->appointment_id;
		$acceptable = array( (int) $appointment['id'] );
		if ( ! empty( $appointment['group_id'] ) ) {
			$acceptable[] = (int) $appointment['group_id'];
		}
		if ( ! in_array( $claimed, $acceptable, true ) ) {
			$deny['reason'] = 'appointment_id_mismatch';
			return $deny;
		}

		if ( ! $this->external_event_times_match( $event, $appointment ) ) {
			$deny['reason'] = 'times_do_not_match';
			return $deny;
		}

		return array( 'reclaimable' => true, 'reason' => '', 'appointment' => $appointment );
	}

	/**
	 * Does the live Google event still sit where the appointment says it does?
	 *
	 * Catches a recycled or reassigned event id. An SSA appointment is never
	 * all-day, so an event with no dateTime is treated as a mismatch.
	 */
	protected function external_event_times_match( $event, $appointment ) {
		if ( empty( $event->start->dateTime ) || empty( $appointment['start_date'] ) ) {
			return false;
		}

		$event_start = strtotime( $event->start->dateTime );
		$appt_start  = strtotime( $appointment['start_date'] . ' UTC' );

		if ( empty( $event_start ) || empty( $appt_start ) ) {
			return false;
		}

		return abs( $event_start - $appt_start ) <= MINUTE_IN_SECONDS;
	}

	/**
	 * Find SSA's own appointments that leaked into the external busy-time cache for
	 * an appointment type, so the admin can re-stamp them in one place.
	 *
	 * A correctly-marked SSA event is skipped at sync and never reaches the external
	 * table, so any external row whose (calendar_id, event_id) matches a local
	 * appointment is a drifted event by construction. That DB join is the cheap
	 * pre-filter -- it excludes every genuine third-party event without a single
	 * Google call. Only the survivors are read live and run through the same
	 * ownership predicate the write route uses.
	 */
	public function scan_external_event_drift( $request ) {
		$params              = $request->get_params();
		$appointment_type_id = isset( $params['appointment_type_id'] ) ? absint( $params['appointment_type_id'] ) : 0;

		if ( empty( $appointment_type_id ) ) {
			return new WP_Error(
				'ssa_drift_missing_appointment_type',
				__( 'An appointment type is required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		$empty = array(
			'response_code' => 200,
			'available'     => true,
			'scanned'       => 0,
			'truncated'     => false,
			'drifted'       => array(),
		);

		// Basic strips class-google-calendar.php, leaving an SSA_Missing stub whose
		// methods return null -- truthy, so empty() would not catch it. Report
		// unavailable rather than fataling; the UI simply shows nothing.
		if ( ! class_exists( 'SSA_Google_Calendar_Client' )
			|| ! class_exists( 'SSA_Google_Calendar' )
			|| $this->plugin->google_calendar_client instanceof SSA_Missing
			|| $this->plugin->google_calendar instanceof SSA_Missing ) {
			$empty['available'] = false;
			return $empty;
		}

		$scope = $this->get_calendars_for_appointment_type( $appointment_type_id );
		if ( empty( $scope ) ) {
			return $empty;
		}

		global $wpdb;
		$ext_table  = $this->plugin->availability_external_model->get_table_name();
		$appt_table = $this->plugin->appointment_model->get_table_name();

		$hashes = array_map( 'ssa_int_hash', array_keys( $scope ) );
		$hash_placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%d' ) );

		// The join itself is the foreign-event filter: a real third-party event has
		// no row in the appointments table. LIMIT is a Google-call budget, not a
		// display cap -- one live read per surviving candidate below.
		$candidate_cap = 30;
		$sql = "SELECT e.id AS row_id, e.event_id, e.calendar_id, e.staff_id, e.start_date
			FROM {$ext_table} e
			JOIN {$appt_table} a
				ON a.google_calendar_event_id = e.event_id
				AND a.google_calendar_id = e.calendar_id
			WHERE e.calendar_id_hash IN ( {$hash_placeholders} )
				AND e.service = 'google'
				AND a.google_calendar_event_id != ''
			ORDER BY e.start_date ASC
			LIMIT %d";

		$query_args   = $hashes;
		$query_args[] = $candidate_cap + 1;
		$candidates   = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Interpolated parts are internal only: table names from get_table_name() and a list of literal %d placeholders; every value (crc32 int hashes and the row cap) is bound via prepare(). Read-only, on-demand admin diagnostic that must return fresh rows, so not cached.

		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}

		$truncated = count( $candidates ) > $candidate_cap;
		if ( $truncated ) {
			array_pop( $candidates );
		}

		$current_home_id = SSA_Utils::get_home_id();
		$drifted         = array();
		$unverified      = 0;

		// One authed client per staff and one accessRole per (staff, calendar), reused
		// across candidates. service_init() rebuilds the client and re-reads settings,
		// and get_calendar_from_calendar_list() is a 60s blocking read -- doing either
		// per event turns a handful of leaked rows into the wp_remote storm CLAUDE.md
		// warns about. A cached false marks a staff whose auth failed.
		$clients      = array();
		$access_roles = array();

		foreach ( $candidates as $candidate ) {
			$calendar_id = $candidate['calendar_id'];
			$event_id    = $candidate['event_id'];
			$staff_id    = (int) $candidate['staff_id'];

			// The hash filter is a crc32, so a colliding out-of-scope calendar can slip
			// through -- the same guard the sibling routes use.
			if ( ! isset( $scope[ $calendar_id ] ) ) {
				continue;
			}

			if ( empty( $staff_id ) && 'site' !== $scope[ $calendar_id ]['owner'] && ! empty( $scope[ $calendar_id ]['staff_ids'] ) ) {
				$staff_id = (int) $scope[ $calendar_id ]['staff_ids'][0];
			}

			if ( ! array_key_exists( $staff_id, $clients ) ) {
				try {
					$clients[ $staff_id ] = $this->plugin->google_calendar_client->service_init( $staff_id );
				} catch ( Exception $e ) {
					$clients[ $staff_id ] = false;
				}
			}
			$client = $clients[ $staff_id ];
			if ( false === $client ) {
				$unverified++;
				continue;
			}

			$raw   = $client->get_event_from_calendar_raw( $calendar_id, $event_id );
			$event = isset( $raw['body'] ) ? $raw['body'] : null;
			if ( 200 !== (int) $raw['http_code'] || empty( $event ) ) {
				// A 404 is a stale row, not an auth failure -- do not flag it as
				// "could not check". Anything else means the answer is incomplete.
				if ( 404 !== (int) $raw['http_code'] ) {
					$unverified++;
				}
				continue;
			}

			$access_key = $staff_id . '|' . $calendar_id;
			if ( ! array_key_exists( $access_key, $access_roles ) ) {
				try {
					$calendar                    = $client->get_calendar_from_calendar_list( $calendar_id );
					$access_roles[ $access_key ] = isset( $calendar->accessRole ) ? $calendar->accessRole : '';
				} catch ( Exception $e ) {
					$access_roles[ $access_key ] = '';
				}
			}
			$calendar_access = $access_roles[ $access_key ];

			$reclaim = $this->evaluate_external_event_reclaim( $calendar_id, $event_id, $appointment_type_id, $event, $calendar_access );
			if ( empty( $reclaim['reclaimable'] ) ) {
				continue;
			}

			$marker = isset( $event->extendedProperties->shared->ssa_home_id )
				? (string) $event->extendedProperties->shared->ssa_home_id
				: '';

			// A marker already equal to ours is not drift -- such an event would have
			// been skipped at sync and would not be here. Guard anyway.
			if ( $marker === $current_home_id ) {
				continue;
			}

			$drifted[] = array(
				'row_id'         => (int) $candidate['row_id'],
				'calendar_id'    => $calendar_id,
				'event_id'       => $event_id,
				'staff_id'       => $staff_id,
				'appointment_id' => ! empty( $reclaim['appointment']['id'] ) ? (int) $reclaim['appointment']['id'] : 0,
				'summary'        => isset( $event->summary ) ? (string) $event->summary : '',
				'start_date'     => isset( $candidate['start_date'] ) ? $candidate['start_date'] : '',
				'marker'         => $marker,
			);
		}

		return array(
			'response_code'   => 200,
			'available'       => true,
			'scanned'         => count( $candidates ),
			'truncated'       => $truncated,
			'unverified'      => $unverified,
			'current_home_id' => $current_home_id,
			'drifted'         => $drifted,
		);
	}

	/**
	 * This route writes to a live third-party calendar and deletes availability
	 * data. It is deliberately gated above the rest of the support screen and
	 * honours no token of any kind -- per CLAUDE.md, broader access would be a
	 * different check, and a weaker one here would be invisible to the next
	 * person who attaches a route to it.
	 */
	public function reclaim_external_event_permissions_check( $request ) {
		return current_user_can( 'ssa_manage_site_settings' )
			&& current_user_can( 'ssa_manage_others_appointments' );
	}

	/**
	 * Stamp this site's marker back onto an event SSA created but no longer
	 * recognises, then drop the cached busy row and recompute availability.
	 *
	 * The repair for a site whose home URL or AUTH_SALT changed after booking:
	 * SSA_Utils::get_home_id() now returns a different value than the one written
	 * into the event, so the self-skip in SSA_Google_Calendar::get_events_from_calendar()
	 * stops matching and SSA imports its own appointments as external busy time.
	 *
	 * Full step-by-step: documentation/external-event-reclaim-workflow.md.
	 */
	public function reclaim_external_event( $request ) {
		if ( ! apply_filters( 'ssa/external_event_reclaim/enabled', true ) ) {
			return new WP_Error(
				'ssa_reclaim_disabled',
				__( 'This action is disabled on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 403 )
			);
		}

		$params = $request->get_params();

		if ( 'reclaim' !== ( isset( $params['confirm'] ) ? $params['confirm'] : '' ) ) {
			return new WP_Error(
				'ssa_reclaim_not_confirmed',
				__( 'This action was not confirmed.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		// class-google-calendar.php is stripped from the Basic build, leaving an
		// SSA_Missing stub whose methods return null -- truthy, so empty() misses it.
		if ( ! class_exists( 'SSA_Google_Calendar_Client' )
			|| ! class_exists( 'SSA_Google_Calendar' )
			|| $this->plugin->google_calendar_client instanceof SSA_Missing
			|| $this->plugin->google_calendar instanceof SSA_Missing ) {
			return new WP_Error(
				'ssa_reclaim_unavailable',
				__( 'Google Calendar is not available on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 501 )
			);
		}

		$calendar_id         = isset( $params['calendar_id'] ) ? sanitize_text_field( $params['calendar_id'] ) : '';
		$event_id            = isset( $params['event_id'] ) ? sanitize_text_field( $params['event_id'] ) : '';
		$appointment_type_id = isset( $params['appointment_type_id'] ) ? absint( $params['appointment_type_id'] ) : 0;
		// Which staff token performs the write is derived from scope below, never from
		// the request -- the client should not get to pick whose credentials are used.
		$staff_id            = 0;
		$row_id              = isset( $params['row_id'] ) ? absint( $params['row_id'] ) : 0;

		// Not defensive padding: bulk_delete( array( 'id' => 0 ) ) passes the
		// empty-args guard in class-td-db-model.php, contributes no WHERE clause,
		// and deletes every row in the table.
		if ( $row_id < 1 ) {
			return new WP_Error(
				'ssa_reclaim_invalid_row',
				__( 'A cached event row is required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $calendar_id ) || empty( $event_id ) ) {
			return new WP_Error(
				'ssa_reclaim_missing_args',
				__( 'A calendar and an event are required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		$scope = $this->get_calendars_for_appointment_type( $appointment_type_id );
		if ( ! isset( $scope[ $calendar_id ] ) ) {
			return new WP_Error(
				'ssa_reclaim_refused',
				__( 'That calendar is not checked by this appointment type.', 'simply-schedule-appointments' ),
				array( 'status' => 403, 'reason' => 'calendar_out_of_scope' )
			);
		}

		if ( empty( $staff_id ) && 'site' !== $scope[ $calendar_id ]['owner'] && ! empty( $scope[ $calendar_id ]['staff_ids'] ) ) {
			$staff_id = (int) $scope[ $calendar_id ]['staff_ids'][0];
		}

		try {
			$client = $this->plugin->google_calendar_client->service_init( $staff_id );
		} catch ( Exception $e ) {
			return new WP_Error(
				'ssa_reclaim_auth_failed',
				$e->getMessage(),
				array( 'status' => 502 )
			);
		}

		// Read live. The cached row is the thing under suspicion, so it cannot be
		// the basis for a write.
		$raw   = $client->get_event_from_calendar_raw( $calendar_id, $event_id );
		$event = isset( $raw['body'] ) ? $raw['body'] : null;

		$calendar_access = '';
		try {
			$calendar        = $client->get_calendar_from_calendar_list( $calendar_id );
			$calendar_access = isset( $calendar->accessRole ) ? $calendar->accessRole : '';
		} catch ( Exception $e ) {
			$calendar_access = '';
		}

		// Re-derived from scratch. The client's opinion is a hint, never authority.
		$reclaim = $this->evaluate_external_event_reclaim( $calendar_id, $event_id, $appointment_type_id, $event, $calendar_access );
		if ( empty( $reclaim['reclaimable'] ) ) {
			return new WP_Error(
				'ssa_reclaim_refused',
				__( 'SSA will not claim this event.', 'simply-schedule-appointments' ),
				array( 'status' => 403, 'reason' => $reclaim['reason'] )
			);
		}

		$marker_before = isset( $event->extendedProperties->shared->ssa_home_id )
			? (string) $event->extendedProperties->shared->ssa_home_id
			: '';
		$home_id = SSA_Utils::get_home_id();

		$written = $this->write_reclaim_marker( $client, $calendar_id, $event_id, $event, $home_id );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Only now touch local state. Deleting first would leave a failed write
		// looking like a success until the next sync silently re-imported the event.
		$row_deleted = false;
		$row_stale   = false;

		$row = $this->plugin->availability_external_model->get( $row_id );
		if ( ! empty( $row['id'] )
			&& (string) $row['event_id'] === (string) $event_id
			&& (string) $row['calendar_id'] === (string) $calendar_id ) {
			$row_deleted = (bool) $this->plugin->availability_external_model->bulk_delete( array( 'id' => (int) $row['id'] ) );
		} else {
			// A sync between page load and click rewrites every row for the calendar,
			// so this id may now belong to a different event. Leave it alone.
			$row_stale = true;
		}

		$cache_flushed = false;
		if ( ! $this->plugin->availability_cache_invalidation instanceof SSA_Missing ) {
			$this->plugin->availability_cache_invalidation->invalidate_appointment_type( $appointment_type_id );
			$cache_flushed = true;
		}

		return array(
			'response_code'  => 200,
			'reclaimed'      => true,
			'calendar_id'    => $calendar_id,
			'event_id'       => $event_id,
			'appointment_id' => ! empty( $reclaim['appointment']['id'] ) ? (int) $reclaim['appointment']['id'] : 0,
			'marker_before'  => $marker_before,
			'marker_after'   => $home_id,
			'row_deleted'    => $row_deleted,
			'row_stale'      => $row_stale,
			'cache_flushed'  => $cache_flushed,
		);
	}

	/**
	 * Echo the event back to Google with exactly one property changed.
	 *
	 * update_event_in_calendar() is a full-body PUT, so the body must be the object
	 * Google just returned -- rebuilding it from SSA's own model would overwrite
	 * whatever the calendar owner has since edited.
	 *
	 * @return true|WP_Error
	 */
	protected function write_reclaim_marker( $client, $calendar_id, $event_id, $event, $home_id ) {
		$updated = json_decode( wp_json_encode( $event ) );
		if ( empty( $updated ) ) {
			return new WP_Error(
				'ssa_reclaim_encode_failed',
				__( 'The event could not be prepared for update.', 'simply-schedule-appointments' ),
				array( 'status' => 500 )
			);
		}

		if ( ! isset( $updated->extendedProperties ) || ! is_object( $updated->extendedProperties ) ) {
			$updated->extendedProperties = new stdClass();
		}
		if ( ! isset( $updated->extendedProperties->shared ) || ! is_object( $updated->extendedProperties->shared ) ) {
			$updated->extendedProperties->shared = new stdClass();
		}
		$updated->extendedProperties->shared->ssa_home_id = $home_id;

		// sendUpdates is a literal, never the ssa/google_calendar/send_attendee_updates
		// filter, which defaults to 'all' at every other write site. Nothing an
		// attendee can see is changing here, so nobody should be emailed about it.
		$options = array(
			'sendUpdates'           => 'none',
			'conferenceDataVersion' => 1,
			'quotaUser'             => $this->plugin->google_calendar->getQuotaUser(),
		);

		$result = $client->update_event_in_calendar( $calendar_id, $event_id, $updated, $options );

		// update_event_in_calendar() collapses a WP_Error, any HTTP > 299 and any
		// Throwable to false, so a read-back is the only way to tell a swallowed
		// failure from a success.
		if ( false === $result ) {
			return new WP_Error(
				'ssa_reclaim_write_failed',
				__( 'Google rejected the update. Nothing was changed on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 502 )
			);
		}

		$verify = $client->get_event_from_calendar_raw( $calendar_id, $event_id );
		$after  = isset( $verify['body'] ) ? $verify['body'] : null;
		if ( 200 !== (int) $verify['http_code']
			|| empty( $after->extendedProperties->shared->ssa_home_id )
			|| (string) $after->extendedProperties->shared->ssa_home_id !== (string) $home_id ) {
			return new WP_Error(
				'ssa_reclaim_verify_failed',
				__( 'The marker could not be confirmed on Google after the update. Nothing was changed on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/**
	 * Validate the requested window and turn it into a Period, or a WP_Error.
	 */
	protected function build_external_events_period( $start, $end ) {
		if ( ! class_exists( 'League\Period\Period' ) ) {
			return new WP_Error(
				'ssa_external_events_unavailable',
				__( 'External event lookup is unavailable on this site.', 'simply-schedule-appointments' ),
				array( 'status' => 500 )
			);
		}

		$start_timestamp = ! empty( $start ) ? strtotime( $start ) : false;
		$end_timestamp   = ! empty( $end ) ? strtotime( $end ) : false;

		if ( empty( $start_timestamp ) || empty( $end_timestamp ) ) {
			return new WP_Error(
				'ssa_external_events_invalid_range',
				__( 'A valid start and end date are required.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		if ( $end_timestamp <= $start_timestamp ) {
			return new WP_Error(
				'ssa_external_events_invalid_range',
				__( 'The end date must be after the start date.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		if ( ( $end_timestamp - $start_timestamp ) > self::EXTERNAL_EVENTS_MAX_RANGE ) {
			return new WP_Error(
				'ssa_external_events_range_too_large',
				__( 'The requested date range is too large.', 'simply-schedule-appointments' ),
				array( 'status' => 400 )
			);
		}

		// Build from the timestamps validated above, not the raw strings: Period runs
		// FILTER_VALIDATE_INT on its arguments first, so a bare numeric string would be
		// read as a unix timestamp and silently shift (or invert) the queried window.
		return new \League\Period\Period(
			gmdate( 'Y-m-d H:i:s', $start_timestamp ),
			gmdate( 'Y-m-d H:i:s', $end_timestamp )
		);
	}

	/**
	 * Resolve the set of external calendars an appointment type actually checks:
	 * its site-level excluded calendars plus (Business only) the excluded
	 * calendars of every staff member assigned to it.
	 *
	 * The same calendar can be excluded at both levels, so `owner` can be `both`
	 * and `staff_names` can hold more than one name.
	 *
	 * Returns calendar_id => array( owner, staff_ids, staff_names ).
	 */
	protected function get_calendars_for_appointment_type( $appointment_type_id ) {
		$scope = array();
		if ( empty( $appointment_type_id ) ) {
			return $scope;
		}

		$appointment_type = SSA_Appointment_Type_Object::instance( $appointment_type_id );

		$site_calendars = $appointment_type->google_calendars_availability;
		if ( ! empty( $site_calendars ) && is_array( $site_calendars ) ) {
			foreach ( $site_calendars as $calendar_id ) {
				$scope[ $calendar_id ] = array(
					'owner'       => 'site',
					'staff_ids'   => array(),
					'staff_names' => array(),
				);
			}
		}

		if ( ! $this->plugin->settings_installed->is_enabled( 'staff' ) ) {
			return $scope;
		}

		$staff_members = $this->plugin->staff_appointment_type_model->get_staff_for_appointment_type( $appointment_type );
		if ( empty( $staff_members ) || ! is_array( $staff_members ) ) {
			return $scope;
		}

		foreach ( $staff_members as $staff ) {
			$staff_calendars = $staff->get_google_excluded_calendars();
			if ( empty( $staff_calendars ) || ! is_array( $staff_calendars ) ) {
				continue;
			}

			foreach ( $staff_calendars as $calendar_id ) {
				if ( ! isset( $scope[ $calendar_id ] ) ) {
					$scope[ $calendar_id ] = array(
						'owner'       => 'staff',
						'staff_ids'   => array(),
						'staff_names' => array(),
					);
				} elseif ( 'site' === $scope[ $calendar_id ]['owner'] ) {
					$scope[ $calendar_id ]['owner'] = 'both';
				}

				$scope[ $calendar_id ]['staff_ids'][]   = (int) $staff->id;
				$scope[ $calendar_id ]['staff_names'][] = $staff->get_name();
			}
		}

		return $scope;
	}

	/**
	 * Flatten the per-account calendar-name cache written by
	 * SSA_Google_Calendar::get_calendar_list() into calendar_id => name.
	 */
	protected function get_cached_calendar_names() {
		$accounts = get_option( 'ssa_gcal_calendar_names', array() );
		if ( ! is_array( $accounts ) ) {
			return array();
		}

		$names = array();
		foreach ( $accounts as $account_names ) {
			if ( is_array( $account_names ) ) {
				$names = array_merge( $names, $account_names );
			}
		}

		return $names;
	}

	/**
	 * Per-calendar summary for the external-events cache: how many cached events
	 * fall inside the requested window, and when the calendar's events last changed.
	 *
	 * `last_updated` is NOT the last sync time. A sync that finds no change leaves the
	 * rows untouched (see SSA_Google_Calendar::refresh_external_events_for_appointment_type()
	 * and SSA_Staff_Object, which both bail on an unchanged event hash), so date_modified
	 * only advances when SSA actually rewrote the calendar. A healthy calendar that has not
	 * changed in months reports months ago -- surface it as "last changed", never as
	 * "last synced", or support reads a working sync as a broken one.
	 *
	 * `count` is window-scoped so it agrees with the events the viewer renders;
	 * `last_updated` is the MAX over all of the calendar's rows, because a sync
	 * rewrites a calendar as a unit and is not tied to the window being viewed.
	 */
	protected function get_external_calendar_summary( $scope, $period ) {
		if ( empty( $scope ) ) {
			return array();
		}

		global $wpdb;

		$calendar_names = $this->get_cached_calendar_names();

		$calendars  = array();
		$hash_to_id = array();
		foreach ( $scope as $calendar_id => $meta ) {
			$hash_to_id[ ssa_int_hash( $calendar_id ) ] = $calendar_id;

			// Seeded so a calendar with zero cached events still appears.
			$calendars[ $calendar_id ] = array(
				'calendar_id'  => $calendar_id,
				'service'      => 'google',
				'name'         => isset( $calendar_names[ $calendar_id ] ) ? $calendar_names[ $calendar_id ] : '',
				'owner'        => isset( $meta['owner'] ) ? $meta['owner'] : 'site',
				'staff_names'  => isset( $meta['staff_names'] ) ? array_values( array_unique( $meta['staff_names'] ) ) : array(),
				'count'        => 0,
				'last_updated' => '',
			);
		}

		$table_name           = $this->plugin->availability_external_model->get_table_name();
		$calendar_id_hash_csv = implode( ',', array_map( 'intval', array_keys( $hash_to_id ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct read of the plugin's own availability-external custom table (no core API, not object-cacheable); $table_name is the model's internal get_table_name() identifier and $calendar_id_hash_csv is built above via implode of array_map( 'intval', ... ), so it is a list of integers only; the user-supplied date range is bound with %s placeholders in prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT calendar_id, calendar_id_hash,
					SUM( CASE WHEN start_date <= %s AND end_date >= %s THEN 1 ELSE 0 END ) AS event_count,
					MAX(date_modified) AS last_updated
				FROM {$table_name}
				WHERE calendar_id_hash IN ( {$calendar_id_hash_csv} )
				GROUP BY calendar_id, calendar_id_hash",
				$period->getEndDate()->format( 'Y-m-d H:i:s' ),
				$period->getStartDate()->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array_values( $calendars );
		}

		foreach ( $rows as $row ) {
			// calendar_id_hash is a crc32, so group by the real id and ignore collisions.
			$calendar_id = isset( $row['calendar_id'] ) ? $row['calendar_id'] : '';
			if ( ! isset( $calendars[ $calendar_id ] ) ) {
				continue;
			}

			$calendars[ $calendar_id ]['count']        = isset( $row['event_count'] ) ? (int) $row['event_count'] : 0;
			$calendars[ $calendar_id ]['last_updated'] = isset( $row['last_updated'] ) ? (string) $row['last_updated'] : '';
		}

		return array_values( $calendars );
	}

	public function get_items( $request ) {
		$params = $request->get_params();

		return array(
			'response_code' => 200,
			'error' => '',
			'data' => array(
				'site_status' => $this->plugin->support_status->get_site_status(),
			),
		);
	}

	/**
	 * Gets the default debug.log contents.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_wp_debug_log_content( WP_REST_Request $request ) {
		$developer_settings = $this->plugin->developer_settings->get();
		if( $developer_settings && isset( $developer_settings['debug_mode'] ) && $developer_settings['debug_mode'] ) {
			$path = ini_get('error_log');
			// return $path;
			if ( file_exists( $path ) && is_writeable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- Pre-flight writability probe on a specific path; WP_Filesystem offers no equivalent non-destructive probe, and this reads no file itself.
				$content = file_get_contents( $path );

				return new WP_REST_Response( $content, 200 );
			}
		}

		return new WP_REST_Response( "", 200 );
	}


	/**
	 * Deletes the default debug.log file.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function empty_wp_debug_log_content( WP_REST_Request $request ) {
		$path = ini_get('error_log');
		if ( file_exists( $path ) && is_writeable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- Pre-flight writability probe on a specific path; WP_Filesystem offers no equivalent non-destructive probe, and deletion below uses wp_delete_file().
			wp_delete_file( $path );

			return new WP_REST_Response( __( 'Debug Log file successfully cleared.', 'simply-schedule-appointments' ), 200 );
		} else {
			return new WP_REST_Response( __( 'Debug Log file not found.', 'simply-schedule-appointments' ), 200 );
		}
	}

	/**
	 * Gets the ssa_debug.log contents.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */	
	public function get_ssa_debug_log_content( WP_REST_Request $request ) {
		$developer_settings = $this->plugin->developer_settings->get();
		if( $developer_settings && isset( $developer_settings['ssa_debug_mode'] ) && $developer_settings['ssa_debug_mode'] ) {
			$path = $this->plugin->support_status->get_log_file_path( 'debug' );
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$content = file_get_contents( $path );

				return new WP_REST_Response( $content, 200 );
			} 
		}

		return new WP_REST_Response( "", 200 );
	}

	/**
	 * Deletes the ssa_debug.log file.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public function empty_ssa_debug_log_content( WP_REST_Request $request ) {
		$path = $this->plugin->support_status->get_log_file_path( 'debug' );
		if ( file_exists( $path ) && is_writeable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- Pre-flight writability probe on a specific path; WP_Filesystem offers no equivalent non-destructive probe, and deletion below uses wp_delete_file().
			wp_delete_file( $path );

			return new WP_REST_Response( __( 'Debug Log file successfully cleared.', 'simply-schedule-appointments' ), 200 );
		} else {
			return new WP_REST_Response( __( 'Debug Log file not found or could not be removed.', 'simply-schedule-appointments' ), 200 );
		}

	}


	/**
	 * Returns the urls for all debug log files.
	 *
	 * @return WP_REST_Response
	 */
	public function get_debug_log_urls() {
		$logs = array(
			'wp' => null,
			'ssa' => null,
		);

		$path = ini_get('error_log');
		if ( file_exists( $path ) && is_readable( $path ) ) {
			$logs['wp'] = str_replace(
				wp_normalize_path( untrailingslashit( ABSPATH ) ),
				site_url(),
				wp_normalize_path( $path )
			);
		}

		$ssa_path = $this->plugin->support_status->get_log_file_path( 'debug' );
		if ( file_exists( $ssa_path ) && is_readable( $ssa_path ) ) {
			$logs['ssa'] = str_replace(
				wp_normalize_path( untrailingslashit( ABSPATH ) ),
				site_url(),
				wp_normalize_path( $ssa_path )
			);
		}

		return new WP_REST_Response( $logs, 200 );
	}

	/**
	 * Pulls plugin settings, Appointment Types and Appointments and returns a JSON payload to be imported into another SSA plugin.
	 *
	 * @param WP_REST_Request $request the Request payload.
	 * @return WP_REST_Response
	 */
	public function get_export_code( WP_REST_Request $request ) {
		$params = $request->get_params();

		$payload = array();

		if ( isset( $params['settings'] ) && 'true' === $params['settings'] ) {
			$payload['settings'] = $this->plugin->settings->get();
			foreach ( $payload['settings']['notifications']['notifications'] as &$notification ) {
				$subject = empty( $notification['subject'] ) ? null : $notification['subject'];
				$notification['subject'] = wp_strip_all_tags( $subject );
				$notification['message'] = str_ireplace(
					array(
						'&quot;',
					),
					array(
						'"',
					),
					$notification['message']
				);
				// TODO if this happens again: use html entities functions instead.
			}
		}

		if ( isset( $params['appointment_types'] ) && 'true' === $params['appointment_types'] ) {
			$payload['resource_groups'] = $this->plugin->resource_group_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['resources'] = $this->plugin->resource_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['resource_group_resources'] = $this->plugin->resource_group_resource_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['staff'] = $this->plugin->staff_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['appointment_types'] = $this->plugin->appointment_type_model->query(
				array(
					'order'  => 'ASC', // necessary for keeping integrity with the order of rows inserted on the database.
					'number' => -1,
				)
			);

			$payload['staff_appointment_types'] = $this->plugin->staff_appointment_type_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['resource_group_appointment_types'] = $this->plugin->resource_group_appointment_type_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['appointment_type_labels'] = $this->plugin->appointment_type_label_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);
		}

		if ( isset( $params['appointments'] ) && 'true' === $params['appointments'] ) {
			$appointments = $this->plugin->appointment_model->query(
				array(
					'order'          => 'ASC', // necessary for keeping integrity with the order of rows inserted on the database.
					'number'         => isset( $params['appointments_limit'] ) ? (int) $params['appointments_limit'] : -1,
					'start_date_min' => isset( $params['future_appointments_only'] ) && 'true' === $params['future_appointments_only'] ? gmdate( 'Y-m-d H:i:s' ) : null,
				)
			);

			if ( ! empty( $params['anonymize_customer_information'] ) && 'true' === $params['anonymize_customer_information'] ) {
				foreach ( $appointments as &$appointment ) {
					foreach ( $appointment['customer_information'] as $key => &$value ) {
						switch ( $key ) {
							case 'Phone':
								$value = '123-456-7890';
								break;
							case 'Email':
								$value = substr( sha1( $value ), 0, 10 ) . '@mailinator.com';
								break;
							default:
								if ( is_array( $value ) ) {
									$value = json_encode( $value );
								}
								$value = substr( sha1( $value ), 0, 10 );
								break;
						}
					}
				}
			}

			$payload['appointments'] = $appointments;
			// import meta data as well.
			$payload['appointment_meta'] = $this->plugin->appointment_meta_model->query( 
				array(
					'order'  => 'ASC', // necessary for keeping integrity with the order of rows inserted on the database.
					'number' => -1,
				)
			);

			$payload['staff_appointments'] = $this->plugin->staff_appointment_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

			$payload['resource_appointments'] = $this->plugin->resource_appointment_model->query(
				array(
					'number' => -1,
					'order'  => 'ASC',
				)
			);

		} elseif ( isset( $params['appointment_types'] ) && 'true' === $params['appointment_types'] ) {
			$payload['appointments']       = array();
			$payload['appointment_meta']   = array();
			$payload['staff_appointments'] = array();
		}

		// Backup export code. Conditional to avoid replacing a proper backup when generating export code to send to support.
		if ( ! isset( $params['backup'] ) || 'false' !== $params['backup'] ) {
			$this->plugin->support_status->save_export_backup( $payload );
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Receives a JSON formatted string via POST on our REST API endpoint, and runs the import process.
	 *
	 * @param WP_REST_Request $request the request object.
	 * @return WP_REST_Response
	 */
	public function import_data_api( WP_REST_Request $request ) {
		$json = $request->get_param( 'content' );

		// verify if JSON data is valid.
		$decoded = json_decode( $json, true );

		if ( ! is_object( $decoded ) && ! is_array( $decoded ) ) {
			return new WP_REST_Response( __( 'Invalid data format.', 'simply-schedule-appointments' ), 500 );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_REST_Response( __( 'Invalid data format.', 'simply-schedule-appointments' ), 500 );
		}

		$import = $this->plugin->support_status->import_data( $decoded );

		// if any error happens while trying to import appointment type data, return.
		if ( is_wp_error( $import ) ) {
			return new WP_REST_Response( $import->get_error_messages(), 500 );
		}

		// everything was successfully imported.
		return new WP_REST_Response( __( 'Data successfully imported!', 'simply-schedule-appointments' ), 200 );
	}

	/**
	 * Checks transients to see if a request to ssa.com/guides is cached. If not, calls the API and caches the response.
	 *
	 * @since 5.4.0
	 *
	 * @param WP_REST_Request $request the request object.
	 * @return WP_REST_Response
	 */
	public function get_guides( WP_REST_Request $request ) {
		$params = $request->get_params();

		// $build a string to use as a transient key.
		$transient_key = array();
		foreach ( $params as $key => $value ) {
			$transient_key[] .= $key . ':' . $value;
		}
		$transient_key  = implode( '|', $transient_key );
		$transient_name = 'ssa_guides_' . $transient_key;

		$cached_response = get_transient( $transient_name );

		if ( false === $cached_response ) {
			$response = wp_safe_remote_get(
				'https://simplyscheduleappointments.com/wp-json/ssa/v1/guides',
				array(
					'body' => $params,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_REST_Response( $response->get_error_messages(), 500 );
			}

			// check if the response is valid.
			if ( strpos( $response['body'], 'rest_forbidden' ) !== false ) {
				return new WP_REST_Response( __( 'Invalid data format.', 'simply-schedule-appointments' ), 500 );
			}

			$cached_response = json_decode( $response['body'], true );

			set_transient( $transient_name, $cached_response, WEEK_IN_SECONDS );
		}

		return new WP_REST_Response( $cached_response, 200 );
	}
}
