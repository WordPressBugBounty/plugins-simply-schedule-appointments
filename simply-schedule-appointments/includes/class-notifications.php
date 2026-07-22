<?php
/**
 * Simply Schedule Appointments Notifications.
 *
 * @since   0.0.3
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Notifications.
 *
 * @since 0.0.3
 */
class SSA_Notifications {
	/**
	 * Parent plugin class.
	 *
	 * @since 0.0.3
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  0.0.3
	 *
	 * @param  Simply_Schedule_Appointments $plugin Main plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	/**
	 * Initiate our hooks.
	 *
	 * @since  0.0.3
	 */
	public function hooks() {
		add_action( 'ssa/appointment/after_delete', array( $this, 'cleanup_notifications_corresponding_to_appointment' ), 1000, 1 );
		add_action( 'ssa/appointment/booked', array( $this, 'queue_booked_notifications' ), 1000, 4 );
		add_action( 'ssa/appointment/rescheduled', array( $this, 'queue_rescheduled_notifications' ), 1000, 4 );
		add_action( 'ssa/appointment/rescheduled', array( $this, 'cleanup_outdated_notifications'), 10, 4 );
		add_action( 'ssa/appointment/rescheduled', array( $this, 'queue_start_date_notifications'), 10, 4 );
		add_action( 'ssa/appointment/booked', array( $this, 'queue_start_date_notifications' ), 1000, 4 );
		add_action( 'ssa/appointment/customer_information_edited', array( $this, 'queue_customer_information_edited_notifications' ), 1000, 4 );
		add_action( 'ssa/appointment/canceled', array( $this, 'queue_canceled_notifications' ), 1000, 4 );
		add_filter( 'ssa/appointment/after_insert', array( $this, 'maybe_save_optin_notifications_settings' ), 1, 3 );

		add_action( 'ssa_fire_appointment_rescheduled_notifications', array( $this, 'maybe_fire_notification'), 10, 2 );
		add_action( 'ssa_fire_appointment_booked_notifications', array( $this, 'maybe_fire_notification'), 10, 2 );
		add_action( 'ssa_fire_appointment_start_date_notifications', array( $this, 'maybe_fire_notification'), 10, 2 );
		add_action( 'ssa_fire_appointment_customer_information_edited_notifications', array( $this, 'maybe_fire_notification'), 10, 2 );
		add_action( 'ssa_fire_appointment_canceled_notifications', array( $this, 'maybe_fire_notification'), 10, 2 );
		add_action( 'ssa/async/send_notifications', array( $this, 'fire_notification' ), 10, 2 );

		add_action( 'ssa/settings/notifications/updated', array( $this, 'schedule_reminder_drift_fix' ), 10, 2 );
		add_action( 'ssa/notifications/fix_reminder_drift', array( $this, 'run_reminder_drift_fix' ), 10, 2 );
	}

	public function maybe_save_optin_notifications_settings( $appointment_id, $data ) {
		// if notification disabled globally bail
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return $data;
		}

		// check appointment type settings for optin notifications
		$appointment_type_id = $data['appointment_type_id'];
		$appointment_type = new SSA_Appointment_Type_Object( $appointment_type_id );
		$is_enabled = $appointment_type->is_notifications_optin_enabled();

		if ( empty( $is_enabled ) ) {
			return $data;
		}

		$meta_keys_and_values = array();
		$meta_keys_and_values['opt_in_notifications'] = ! empty( $data['opt_in_notifications'] );
		$this->plugin->appointment_meta_model->bulk_meta_update( $appointment_id, $meta_keys_and_values );

		if( empty( $data['opt_in_notifications'] )){
			$this->plugin->revision_model->insert_revision_opt_out_notification($appointment_id, $data );
		}

		return $data;
	}

	public function get_payload( $hook, $appointment_id, $data, $data_before = array(), $response = null ) {
		$appointment_object = new SSA_Appointment_Object( $appointment_id );

		$action_pieces = explode( '_', $hook );
		$action_verb = array_pop( $action_pieces );
		$action_noun = implode( '_', $action_pieces );

		$payload = array(
			'action' => $hook,
			'action_noun' => $action_noun,
			'action_verb' => $action_verb,
			'appointment' => $appointment_object->get_data( 0 ),
			'data_before' => $data_before,
		);

		return $payload;

	}

	public function queue_booked_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_booked', 'ssa_fire_appointment_booked_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_rescheduled_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_booked', 'ssa_fire_appointment_rescheduled_notifications', $appointment_id, $data, $data_before, $response );
	}
	
	public function queue_start_date_notifications( $appointment_id, $data, $data_before = array(), $response = null, $notification_ids_filter = null ) {
		$this->queue_notifications( 'appointment_start_date', 'ssa_fire_appointment_start_date_notifications', $appointment_id, $data, $data_before, $response, $notification_ids_filter );
	}

	public function queue_customer_information_edited_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_customer_information_edited', 'ssa_fire_appointment_customer_information_edited_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_canceled_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_canceled', 'ssa_fire_appointment_canceled_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_notifications( $hook, $action_to_fire, $appointment_id, $data, $data_before = array(), $response = null, $notification_ids_filter = null ) {
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return false;
		}

		$notifications = $this->plugin->notifications_settings->get_notifications();
		if ( empty( $notifications ) ) {
			return;
		}

		// When provided (resync flow), restrict queueing to the given notification ids so
		// already-correct pending rows aren't duplicated. Booking/reschedule pass null.
		$filter_lookup = null;
		if ( is_array( $notification_ids_filter ) ) {
			$filter_lookup = array();
			foreach ( $notification_ids_filter as $fid ) {
				$filter_lookup[ (int) $fid ] = true;
			}
		}

		$appointment_object = new SSA_Appointment_Object( $appointment_id );
		foreach ($notifications as $key => $notification) {
			if ( $notification['trigger'] !== $hook ) {
				continue;
			}

			if ( null !== $filter_lookup && ! isset( $filter_lookup[ (int) $notification['id'] ] ) ) {
				continue;
			}

			if ( ! $this->notification_applies_to_appointment( $notification, $appointment_object ) ) {
				continue;
			}

			$meta = array();
			if ( 'appointment_start_date' === $notification['trigger'] ) {
				$date_queued_datetime = $this->compute_start_date_queue_datetime( $notification, $appointment_object );
				if ( null === $date_queued_datetime ) {
					continue; // Don't schedule reminders if they would be sent before the appointment was actually booked
				}
			} else {
				$date_queued_datetime = ssa_datetime();
				// Add 5 seconds to notification date_queued to allow the web_meeting_url to return
				if ( 'appointment_booked' === $notification['trigger'] ) {
					$date_queued_datetime = $date_queued_datetime->add( new DateInterval( 'PT5S' ) );
				}
				if ( ! empty( $notification['duration'] ) ) {
					$interval_string = 'PT' . absint( $notification['duration'] ) . 'M';
					if ( 'after' === $notification['when'] ) {
						$date_queued_datetime = $date_queued_datetime->add( new DateInterval( $interval_string ) );
					} else {
						$date_queued_datetime = $date_queued_datetime->sub( new DateInterval( $interval_string ) );
					}
				}
			}
			$date_queued_string = $date_queued_datetime->format( 'Y-m-d H:i:s' );
			$meta['date_queued'] = $date_queued_string;
			$payload = $this->get_payload( $hook, $appointment_id, $data, $data_before, $response );
			$payload['notification'] = array(
				'id' => $notification['id'],
			);
			// ssa_debug_log( $notification, 1, 'Notification queued' );
			ssa_queue_action( $hook, $action_to_fire, 10, $payload, 'appointment', $appointment_id, 'notifications', $meta );
			$action_noun = $payload['action_noun'];
			$action_verb = $payload['action_verb'];
			$data_after = $payload['appointment'];
			$data_before = $payload['data_before'];
			// Formatting the appointment date
			$date_format = SSA_Utils::localize_default_date_strings( 'F j, Y' );
			$notification_date = ssa_datetime( $date_queued_datetime);
			$notification_date = $this->plugin->utils->get_datetime_as_local_datetime( $notification_date)->format( $date_format );
			$notification_date = SSA_Utils::translate_formatted_date( $notification_date );
			// Formatting the appointment time
			$time_format = SSA_Utils::localize_default_date_strings( 'g:i a' );
			$notification_time = ssa_datetime( $date_queued_datetime);
			$notification_time = $this->plugin->utils->get_datetime_as_local_datetime( $notification_time)->format( $time_format );
			$notification_time = SSA_Utils::translate_formatted_date( $notification_time );
			$duration = isset($notification['duration']) ? $notification['duration'] : 0;
			$recipients = !empty( $notification['sent_to'] ) ? $notification['sent_to'] : $notification['sms_to'];
			if( ! is_array( $recipients ) ) {
				ssa_debug_log( 'Invalid recipients for notification:' . "\n" . var_export( $notification ), 10 ); // phpcs:ignore
				return;
			}
			$recipient_type = ssa_get_recipient_type_for_recipients_array( $recipients );
			$notification_title = isset( $notification['title'] ) ? (string) $notification['title'] : '';
			do_action( 'ssa/notification/scheduled', $appointment_id, $action_noun, $action_verb, $notification_date, $notification_time, $duration, $recipient_type, $data_after, $data_before, $notification_title );
		}

	}

	/**
	 * Shared eligibility filters for a notification against a given appointment.
	 * Excludes the trigger match — callers compare trigger against different values.
	 *
	 * @param array $notification
	 * @param SSA_Appointment_Object $appointment_object
	 * @return bool
	 */
	protected function notification_applies_to_appointment( $notification, $appointment_object ) {
		if ( ! empty( $notification['appointment_types'] ) && is_array( $notification['appointment_types'] )
			&& ! in_array( $appointment_object->appointment_type_id, $notification['appointment_types'] ) ) {
			return false;
		}
		if ( isset( $notification['active'] ) && empty( $notification['active'] ) ) {
			return false;
		}
		if ( 'sms' === $notification['type'] && ! empty( $notification['sms_to'] ) ) {
			if ( ! $this->plugin->settings_installed->is_enabled( 'sms' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve the queue datetime for a start_date-triggered notification.
	 *
	 * @param array $notification
	 * @param SSA_Appointment_Object $appointment_object
	 * @return DateTimeImmutable|null Null if the computed time is already past.
	 */
	protected function compute_start_date_queue_datetime( $notification, $appointment_object ) {
		$date_queued_datetime = $appointment_object->start_date_datetime;
		if ( ! empty( $notification['duration'] ) ) {
			$interval = new DateInterval( 'PT' . absint( $notification['duration'] ) . 'M' );
			$when     = isset( $notification['when'] ) ? $notification['when'] : 'before';
			$date_queued_datetime = ( 'after' === $when )
				? $date_queued_datetime->add( $interval )
				: $date_queued_datetime->sub( $interval );
		}
		if ( $date_queued_datetime <= ssa_datetime() ) {
			return null;
		}
		return $date_queued_datetime;
	}

	/**
	 * Compute the start_date notifications that SHOULD currently be queued for an appointment.
	 *
	 * Pure read — no side effects. Shares filter logic with queue_notifications() via
	 * notification_applies_to_appointment() and compute_start_date_queue_datetime().
	 *
	 * @param int $appointment_id
	 * @return array<int, array{notification_id:int, date_queued:string}>
	 */
	public function get_expected_start_date_notifications( $appointment_id ) {
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return array();
		}

		$notifications = $this->plugin->notifications_settings->get_notifications();
		if ( empty( $notifications ) ) {
			return array();
		}

		try {
			$appointment_object = new SSA_Appointment_Object( $appointment_id );
			$appointment_object->get();
		} catch ( Exception $e ) {
			return array();
		}

		$expected = array();

		foreach ( $notifications as $notification ) {
			if ( empty( $notification['id'] ) || empty( $notification['trigger'] ) ) {
				continue;
			}
			if ( 'appointment_start_date' !== $notification['trigger'] ) {
				continue;
			}
			if ( ! $this->notification_applies_to_appointment( $notification, $appointment_object ) ) {
				continue;
			}

			$date_queued_datetime = $this->compute_start_date_queue_datetime( $notification, $appointment_object );
			if ( null === $date_queued_datetime ) {
				continue;
			}

			$expected[] = array(
				'notification_id' => (int) $notification['id'],
				'date_queued'     => $date_queued_datetime->format( 'Y-m-d H:i:s' ),
			);
		}

		return $expected;
	}

	/**
	 * Bulk-load pending start_date async actions for many appointments in one query.
	 * The async_action_model's query() only supports single object_id; this bypasses
	 * it so we avoid N+1 queries when scanning/resyncing. The pending filter mirrors
	 * what async_action_model->query() does for `'date_processed' => '0000-00-00 00:00:00'`.
	 *
	 * @param int[] $appointment_ids
	 * @return array<int, array[]> map of appointment_id => pending rows (payload already decoded)
	 */
	protected function bulk_query_pending_start_date_actions( array $appointment_ids ) {
		if ( empty( $appointment_ids ) ) {
			return array();
		}

		global $wpdb;
		$table = $this->plugin->async_action_model->get_table_name();
		$ids   = implode( ',', array_map( 'intval', $appointment_ids ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom async_action table, direct query required (async_action_model->query() only supports a single object_id); not cacheable because this reads live pending-queue state that inserts/deletes/fires mutate, and a stale snapshot would resync against outdated rows; {$table} is an internal identifier from get_table_name() ($wpdb->prefix + hardcoded slug) and {$ids} is a comma-joined list of intval-cast integers, while all value params are bound via $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, object_id, date_queued, payload FROM {$table}
			 WHERE object_type = %s
			   AND action      = %s
			   AND object_id IN ({$ids})
			   AND ( date_processed = %s OR date_processed IS NULL )",
			'appointment',
			'ssa_fire_appointment_start_date_notifications',
			'0000-00-00 00:00:00'
		), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['payload'] ) && is_string( $row['payload'] ) ) {
				$decoded = json_decode( $row['payload'], true );
				if ( is_array( $decoded ) ) {
					$row['payload'] = $decoded;
				}
			}
			$oid = (int) $row['object_id'];
			$grouped[ $oid ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Compare expected start_date notifications against what's currently pending in the async queue.
	 *
	 * Key distinction: a pending row is an ORPHAN only if its notification config no longer
	 * applies (deactivated, removed, type-mismatch, sms-disabled). A row whose config still
	 * applies but whose computed queue time is now in the past is NOT an orphan — it's a
	 * pending fire that cron will pick up. Only timing-mismatches against a still-future
	 * expected time count as STALE.
	 *
	 * @param int        $appointment_id
	 * @param array|null $pending Optional pre-loaded async_action rows for this appointment.
	 *                            When null, falls back to a per-appointment query.
	 * @return array{
	 *   expected: array,
	 *   missing:  int[],                 // notification_ids that should be queued but aren't
	 *   stale:    int[],                 // notification_ids queued with wrong date_queued
	 *   orphans:  int[],                 // notification_ids queued whose config no longer applies
	 *   row_id_by_notification_id: array<int,int>,  // pending row id keyed by notification id
	 * }
	 */
	public function compute_appointment_reminder_diff( $appointment_id, $pending = null ) {
		$expected = $this->get_expected_start_date_notifications( $appointment_id );

		if ( null === $pending ) {
			$pending = $this->plugin->async_action_model->query( array(
				'object_id'      => $appointment_id,
				'object_type'    => 'appointment',
				'action'         => array( 'ssa_fire_appointment_start_date_notifications' ),
				'date_processed' => '0000-00-00 00:00:00',
			) );
		}

		$expected_by_id = array();
		foreach ( $expected as $e ) {
			$expected_by_id[ $e['notification_id'] ] = $e['date_queued'];
		}

		// Set of notification ids whose config still applies to this appointment, regardless
		// of whether their freshly-computed queue time is past or future. Used to distinguish
		// "no longer applies" (true orphan) from "applies but timing past" (cron will fire it).
		$applicable_ids = $this->get_applicable_start_date_notification_ids( $appointment_id );

		$actual_by_id              = array();
		$row_id_by_notification_id = array();
		foreach ( $pending as $row ) {
			$nid = isset( $row['payload']['notification']['id'] ) ? (int) $row['payload']['notification']['id'] : 0;
			if ( empty( $nid ) ) {
				continue;
			}
			$actual_by_id[ $nid ]              = $row['date_queued'];
			$row_id_by_notification_id[ $nid ] = (int) $row['id'];
		}

		$missing = array();
		$stale   = array();
		foreach ( $expected_by_id as $nid => $date_queued ) {
			if ( ! isset( $actual_by_id[ $nid ] ) ) {
				$missing[] = (int) $nid;
			} elseif ( $actual_by_id[ $nid ] !== $date_queued ) {
				$stale[] = (int) $nid;
			}
		}

		$orphans = array();
		foreach ( $actual_by_id as $nid => $_ ) {
			if ( ! isset( $applicable_ids[ $nid ] ) ) {
				$orphans[] = (int) $nid;
			}
		}

		return array(
			'expected'                  => $expected,
			'missing'                   => $missing,
			'stale'                     => $stale,
			'orphans'                   => $orphans,
			'row_id_by_notification_id' => $row_id_by_notification_id,
		);
	}

	/**
	 * Notification ids whose config currently applies to this appointment — regardless of
	 * whether their fresh queue time is past or future. Returned as a set: [id => true].
	 *
	 * @param int $appointment_id
	 * @return array<int,true>
	 */
	protected function get_applicable_start_date_notification_ids( $appointment_id ) {
		$applicable = array();
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return $applicable;
		}
		$notifications = $this->plugin->notifications_settings->get_notifications();
		if ( empty( $notifications ) ) {
			return $applicable;
		}
		try {
			$appt = new SSA_Appointment_Object( $appointment_id );
			$appt->get();
		} catch ( Exception $e ) {
			return $applicable;
		}
		foreach ( $notifications as $n ) {
			if ( empty( $n['id'] ) || empty( $n['trigger'] ) ) {
				continue;
			}
			if ( 'appointment_start_date' !== $n['trigger'] ) {
				continue;
			}
			if ( ! $this->notification_applies_to_appointment( $n, $appt ) ) {
				continue;
			}
			$applicable[ (int) $n['id'] ] = true;
		}
		return $applicable;
	}

	/**
	 * Wipe pending start_date async actions for one appointment and re-queue fresh via the
	 * plugin's own queue_start_date_notifications(). If no drift is detected, skips untouched.
	 *
	 * @param int        $appointment_id
	 * @param array|null $pending Optional pre-loaded async_action rows for this appointment.
	 *                            When null, falls back to a per-appointment query.
	 */
	public function resync_reminder_for_appointment( $appointment_id, $pending = null ) {
		$result = array(
			'appointment_id' => (int) $appointment_id,
			'deleted'        => 0,
			'queued'         => 0,
			'skipped'        => false,
		);

		if ( null === $pending ) {
			$pending = $this->plugin->async_action_model->query( array(
				'object_id'      => $appointment_id,
				'object_type'    => 'appointment',
				'action'         => array( 'ssa_fire_appointment_start_date_notifications' ),
				'date_processed' => '0000-00-00 00:00:00',
			) );
		}

		$diff = $this->compute_appointment_reminder_diff( $appointment_id, $pending );
		if ( empty( $diff['missing'] ) && empty( $diff['stale'] ) && empty( $diff['orphans'] ) ) {
			$result['skipped'] = true;
			return $result;
		}

		// Targeted delete: only rows whose notification id appears in stale (timing wrong) or
		// orphans (config gone). Pending rows for still-applicable notifs with matching timing
		// are LEFT ALONE — cron fires them on their own schedule.
		$to_delete_ids = array();
		$row_by_nid    = $diff['row_id_by_notification_id'];
		foreach ( array_merge( $diff['stale'], $diff['orphans'] ) as $nid ) {
			if ( isset( $row_by_nid[ $nid ] ) ) {
				$to_delete_ids[] = (int) $row_by_nid[ $nid ];
			}
		}
		$to_delete_ids = array_values( array_unique( $to_delete_ids ) );

		// Targeted re-queue: only missing + stale notification ids. Already-correct rows stay.
		$to_queue_nids = array_values( array_unique( array_merge( $diff['missing'], $diff['stale'] ) ) );

		$result['deleted'] = count( $to_delete_ids );
		$result['queued']  = count( $to_queue_nids );

		if ( ! empty( $to_delete_ids ) ) {
			$this->log_drift_cancellations( $appointment_id, $pending, $to_delete_ids, $diff['orphans'] );
			$this->plugin->async_action_model->bulk_delete( array( 'id' => $to_delete_ids ) );
		}

		if ( ! empty( $to_queue_nids ) ) {
			$this->queue_start_date_notifications( (int) $appointment_id, array(), array(), null, $to_queue_nids );
		}

		return $result;
	}

	/**
	 * Emit a `notification_canceled` revision per row the drift resync is about to delete,
	 * so the appointment's history shows what changed and why. Re-queued notifs already get a
	 * `notification_scheduled` revision through queue_notifications → ssa/notification/scheduled.
	 *
	 * @param int   $appointment_id
	 * @param array $pending           Pending async_action rows under consideration.
	 * @param int[] $to_delete_row_ids Row ids targeted for deletion (orphan + stale).
	 * @param int[] $orphan_nids       Notification ids classified as orphans (vs. stale).
	 */
	protected function log_drift_cancellations( $appointment_id, $pending, $to_delete_row_ids, $orphan_nids ) {
		if ( empty( $to_delete_row_ids ) ) {
			return;
		}
		$delete_set = array_flip( array_map( 'intval', $to_delete_row_ids ) );
		$orphan_set = array_flip( array_map( 'intval', $orphan_nids ) );

		$notif_by_id = array();
		foreach ( $this->plugin->notifications_settings->get_notifications() as $n ) {
			if ( ! empty( $n['id'] ) ) {
				$notif_by_id[ (int) $n['id'] ] = $n;
			}
		}

		// insert_revision_appointment reads data_after['status']; load the appointment once
		// so the revision rows carry the current status and we avoid undefined-key warnings.
		$appt_data = array( 'status' => '' );
		try {
			$appt = new SSA_Appointment_Object( (int) $appointment_id );
			$appt_data = $appt->get_data( 0 );
		} catch ( Exception $e ) {
			// Appointment vanished; status='' is fine, the revision still inserts.
		}

		foreach ( $pending as $row ) {
			if ( ! isset( $delete_set[ (int) $row['id'] ] ) ) {
				continue;
			}
			$nid = isset( $row['payload']['notification']['id'] ) ? (int) $row['payload']['notification']['id'] : 0;
			if ( empty( $nid ) ) {
				continue;
			}
			$notif      = isset( $notif_by_id[ $nid ] ) ? $notif_by_id[ $nid ] : array();
			$recipients = ! empty( $notif['sent_to'] ) ? $notif['sent_to'] : ( ! empty( $notif['sms_to'] ) ? $notif['sms_to'] : array() );
			$reason     = isset( $orphan_set[ $nid ] )
				? esc_html__( 'Notification configuration no longer applies to this appointment.', 'simply-schedule-appointments' )
				: esc_html__( 'Notification settings changed — reminder rescheduled to a new time.', 'simply-schedule-appointments' );

			$this->plugin->revision_model->insert_revision_on_notification_canceled( (int) $appointment_id, array(
				'data_after'                      => $appt_data,
				'data_before'                     => array(),
				'recipient_type'                  => is_array( $recipients ) ? ssa_get_recipient_type_for_recipients_array( $recipients ) : '',
				'notification_type'               => isset( $notif['type'] ) ? $notif['type'] : '',
				'notification_title'              => isset( $notif['title'] ) ? $notif['title'] : '',
				'notification_cancelation_reason' => $reason,
			) );
		}
	}

	/**
	 * Scan a batch of future booked appointments and resync any whose pending start_date
	 * reminders drifted from current settings. Fires 5 minutes after a notification
	 * settings change — see schedule_reminder_drift_fix().
	 *
	 * Self-chains: when a full batch comes back we queue a +2-min follow-up at the next
	 * offset, so sites with >50 drifted appointments still get fully resolved without
	 * any single run hogging the WP-Cron slot. Chain exits naturally when a batch returns
	 * fewer appointments than the batch size.
	 *
	 * Tradeoffs / known limitations:
	 *   - Past appointments are never scanned. Stale pending rows there are no-ops:
	 *     maybe_fire_notification's status/time guards prevent bad sends at fire time.
	 *   - start_date_min is anchored at the first run of the chain and passed through each
	 *     continuation so offset pagination stays stable as appointments pass their start
	 *     during the chain's lifetime. Otherwise the window would shift forward each batch
	 *     and rows at the edge would be skipped.
	 *   - New bookings or cancellations mid-chain can still cause a small window of
	 *     re-processing or skipping on the next batch boundary (offset shifts by 1 per
	 *     insert/delete within the already-processed range). Reprocessing is a cheap
	 *     no-op; skipping is benign (runtime guards still prevent bad sends).
	 *   - If a settings change lands while a chain is mid-flight, schedule_reminder_drift_fix()
	 *     sees the continuation pending and raises a restart flag instead of queueing a new
	 *     run. At the end of the current batch we detect the flag and restart from offset 0
	 *     with a fresh start_date_min so the new settings get applied to every appointment.
	 *   - Cancelled appointments are excluded — stale pending rows self-clean when their
	 *     date_queued passes.
	 *
	 * @param int    $offset         Starting offset for this batch. Chain continuations pass the next offset.
	 * @param string $start_date_min GMT datetime anchor. Empty → anchor at now (first run / restart).
	 */
	public function run_reminder_drift_fix( $offset = 0, $start_date_min = '' ) {
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return;
		}

		$batch_size = 50;
		$offset     = absint( $offset );

		if ( empty( $start_date_min ) ) {
			$start_date_min = gmdate( 'Y-m-d H:i:s' );
		}

		$appointments = $this->plugin->appointment_model->query( array(
			'status'         => array( 'booked' ),
			'start_date_min' => $start_date_min,
			'number'         => $batch_size,
			'offset'         => $offset,
			'orderby'        => 'start_date',
			'order'          => 'ASC',
		) );

		if ( empty( $appointments ) ) {
			return;
		}

		$appointment_ids = array();
		foreach ( $appointments as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}
			$appointment_ids[] = (int) $row['id'];
			// Prime SSA_Appointment_Object's static cache so the diff loop skips a per-appointment DB read.
			SSA_Appointment_Object::from_data( $row );
		}

		if ( empty( $appointment_ids ) ) {
			return;
		}

		$pending_by_appt = $this->bulk_query_pending_start_date_actions( $appointment_ids );

		$changed       = 0;
		$deleted_total = 0;
		$queued_total  = 0;

		foreach ( $appointment_ids as $appointment_id ) {
			$pending = isset( $pending_by_appt[ $appointment_id ] ) ? $pending_by_appt[ $appointment_id ] : array();
			$row     = $this->resync_reminder_for_appointment( $appointment_id, $pending );
			if ( empty( $row['skipped'] ) ) {
				$changed++;
				$deleted_total += (int) $row['deleted'];
				$queued_total  += (int) $row['queued'];
			}
		}

		if ( $changed > 0 ) {
			ssa_debug_log( 'reminder drift fix committed: ' . wp_json_encode( array(
				'scanned'        => count( $appointment_ids ),
				'offset'         => $offset,
				'start_date_min' => $start_date_min,
				'changed'        => $changed,
				'deleted_total'  => $deleted_total,
				'queued_total'   => $queued_total,
			) ), 10 );
		}

		// If a settings change landed while this run was in flight, restart the chain from
		// offset 0 so appointments already scanned under the old settings get a fresh pass.
		// Empty start_date_min arg → the restart run re-anchors at its own "now".
		$restart = (bool) get_transient( 'ssa_notifications_drift_restart' );
		if ( $restart ) {
			delete_transient( 'ssa_notifications_drift_restart' );
			try {
				ssa_schedule_single_action(
					strtotime( '+2 minutes' ),
					'ssa/notifications/fix_reminder_drift',
					array( 0, '' )
				);
			} catch ( Exception $e ) {
				// noop
			}
			return;
		}

		if ( count( $appointments ) >= $batch_size ) {
			try {
				ssa_schedule_single_action(
					strtotime( '+2 minutes' ),
					'ssa/notifications/fix_reminder_drift',
					array( $offset + $batch_size, $start_date_min )
				);
			} catch ( Exception $e ) {
				// noop
			}
		}
	}

	/**
	 * Debounce-schedule a single-shot drift fix 5 minutes after notification settings change.
	 * Fires on ssa/settings/notifications/updated (which covers both save and delete since
	 * deleting a notification is really update_section('notifications', ...) with it removed).
	 *
	 * If an earlier save already queued a fix (or a chain continuation is mid-flight) we
	 * leave it in place — rapid sequential edits collapse into one scheduled run instead
	 * of piling up. In that case we also raise a restart flag so run_reminder_drift_fix()
	 * will reset the chain to offset 0 after its current batch, ensuring appointments
	 * already scanned under stale settings get a fresh pass.
	 */
	public function schedule_reminder_drift_fix( $new_section = null, $old_section = null ) {
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return;
		}

		// Only schedule if something about the start_date-trigger subset actually changed.
		// Edits to appointment_booked / appointment_canceled notifications can't cause
		// start_date drift; content-only edits (title/subject/message) don't affect what
		// gets queued either.
		if ( is_array( $new_section ) && is_array( $old_section ) ) {
			$new_subset = $this->project_start_date_drift_fields( isset( $new_section['notifications'] ) ? $new_section['notifications'] : array() );
			$old_subset = $this->project_start_date_drift_fields( isset( $old_section['notifications'] ) ? $old_section['notifications'] : array() );
			if ( $new_subset === $old_subset ) {
				return;
			}
		}

		$hook = 'ssa/notifications/fix_reminder_drift';

		try {
			if ( ssa_has_scheduled_action( $hook ) ) {
				set_transient( 'ssa_notifications_drift_restart', 1, DAY_IN_SECONDS );
				return;
			}
			ssa_schedule_single_action( strtotime( '+5 minutes' ), $hook );
		} catch ( Exception $e ) {
			// noop
		}
	}

	/**
	 * Project a notifications array onto the fields that can actually cause start_date drift,
	 * keyed by notification id and sorted so the result is stable for equality comparison.
	 *
	 * Changes to any field NOT in this projection (title, subject, message, sent_to, replyTo,
	 * etc.) will not produce a different result from compute_appointment_reminder_diff(), so
	 * there's no reason to schedule a fix run for them.
	 *
	 * @param array $notifications
	 * @return array
	 */
	protected function project_start_date_drift_fields( $notifications ) {
		if ( ! is_array( $notifications ) ) {
			return array();
		}
		$projected = array();
		foreach ( $notifications as $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}
			if ( empty( $notification['trigger'] ) || 'appointment_start_date' !== $notification['trigger'] ) {
				continue;
			}
			$appointment_types = array();
			if ( isset( $notification['appointment_types'] ) && is_array( $notification['appointment_types'] ) ) {
				$appointment_types = array_map( 'intval', $notification['appointment_types'] );
				sort( $appointment_types );
			}
			$sms_to = array();
			if ( isset( $notification['sms_to'] ) && is_array( $notification['sms_to'] ) ) {
				$sms_to = $notification['sms_to'];
				sort( $sms_to );
			}
			$projected[ (int) ( isset( $notification['id'] ) ? $notification['id'] : 0 ) ] = array(
				'active'            => ! isset( $notification['active'] ) || ! empty( $notification['active'] ),
				'duration'          => isset( $notification['duration'] ) ? (int) $notification['duration'] : 0,
				'when'              => isset( $notification['when'] ) ? (string) $notification['when'] : 'before',
				'type'              => isset( $notification['type'] ) ? (string) $notification['type'] : '',
				'appointment_types' => $appointment_types,
				'sms_to'            => $sms_to,
			);
		}
		ksort( $projected );
		return $projected;
	}

	public function fail_async_action( $async_action, $error_code = 500, $error_message = '', $context = array() ) {
		$response = array(
			'status_code' => $error_code,
			'error_message' => $error_message,
			'context' => $context,
		);
		// ssa_debug_log( $async_action, 1, 'async_action failed' );
		ssa_complete_action( $async_action['id'], $response );
	}

	/**
	 * Remove notifications corresponding to an appointment that is being deleted
	 * 
	 */
	public function cleanup_notifications_corresponding_to_appointment( $appointment_id ) {
		$corresponding_scheduled_actions = $this->plugin->async_action_model->query(
			array(
				'object_id' => $appointment_id,
				'object_type' => 'appointment',
			)
		);

		$to_remove_action_ids = array_column( $corresponding_scheduled_actions, 'id' );
		
		if ( ! empty( $to_remove_action_ids ) ) {
			$this->plugin->async_action_model->bulk_delete( array( 
				'id' => $to_remove_action_ids
			) );
		}
	}
	
	/**
	 * Remove outdated actions for rescheduled appointments
	 *
	 * @param $single_notification_settings
	 * @param $payload
	 * @return void
	 */
	 public function cleanup_outdated_notifications(  $appointment_id, $data, $data_before = array(), $response = null  ) {
		$appointment_object = new SSA_Appointment_Object( $appointment_id );
		$to_remove_action_ids = array();
		$corresponding_scheduled_actions = $this->plugin->async_action_model->query(
			array(
				'object_id' => $appointment_object->id,
				'action' => ['ssa_fire_appointment_start_date_notifications', 'ssa_fire_appointment_booked_notifications']
			)
		);
		foreach ( $corresponding_scheduled_actions as $scheduled_action ) {
			if( ! empty( $scheduled_action['payload']['appointment']['start_date'] ) ) {
				if ( $scheduled_action['payload']['appointment']['start_date'] !== $appointment_object->start_date ) {
					$to_remove_action_ids[] = $scheduled_action['id'];
				}
			}
		}
		if ( ! empty( $to_remove_action_ids ) ) {
			$this->plugin->async_action_model->bulk_delete( array( 
				'id' => $to_remove_action_ids
			) );
		}
	 }

	/**
	* Check if the customer has opted out from receiving notifications
	*/
	public function customer_has_not_opted_in( $appointment_object, $notification ) {
		if ( ! empty($notification['sent_to']) && ! in_array( '{{customer_email}}', $notification['sent_to'] ) ) {
			return false; // this is not even a customer email notification
		}

		if ( ! empty($notification['sms_to']) && ! in_array( '{{ customer_phone }}', $notification['sms_to'] ) ) {
			return false; // this is not even a customer sms notification
		}

		// Check appointment type settings for optin notifications
		$appointment_type_id = $appointment_object->appointment_type_id;
		$appointment_type = new SSA_Appointment_Type_Object( $appointment_type_id );
		$is_enabled = $appointment_type->is_notifications_optin_enabled();

		if ( empty( $is_enabled ) ) {
			return false;
		}

		return $appointment_object->customer_has_not_opted_in();
	}
	 
	public function should_fire_notification( $single_notification_settings, $payload ) {
		// ssa_debug_log( __FUNCTION__ .'()' );
		// ssa_debug_log( $single_notification_settings, 1, '$single_notification_settings' );
		// ssa_debug_log( $payload, 1, '$payload' );

		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return false;
		}

		if ( isset( $single_notification_settings['active'] ) && empty( $single_notification_settings['active'] ) ) {
			return false; // if it isn't set yet, then the settings may have been stored before the active toggle existed. They default on, so if 'active' isn't set, we'll assume it should be on.
		}

		// Only try to send if the notification IDs match
		if ( empty( $single_notification_settings['id'] ) || empty( $payload['notification']['id'] ) || $payload['notification']['id'] != $single_notification_settings['id'] || empty( $payload['action'] ) ) {
			return false;
		}

		// Check appointment type
		if ( is_array( $single_notification_settings ) && ! isset( $single_notification_settings['appointment_types'] ) ) {
			return false;
		}

		if ( empty( $payload['appointment']['id'] ) ) {
			return false;
		}
		$appointment_object = new SSA_Appointment_Object( $payload['appointment']['id'] );
		try {
			$status = $appointment_object->get();
		} catch (Exception $e) {
			ssa_debug_log( 'Appointment ID ' . $payload['appointment']['id'] . ' not found in should_fire_notification()' );
			return false;
		}
		
		if ( $this->customer_has_not_opted_in( $appointment_object, $single_notification_settings ) ) {
			return false;
		}

		if ( ! empty( $payload['appointment']['meta']['status'] ) && $payload['appointment']['meta']['status'] === 'no_show' ) {
			$data = [
				'data_after' => $payload['appointment'],
				'data_before' => isset($payload['data_before']) ? $payload['data_before'] : array(),
				'recipient_type' => ssa_get_recipient_type_for_recipients_array( $single_notification_settings['sent_to'] ),
				'notification_type' => $single_notification_settings['type'],
				'notification_title' => $single_notification_settings['title'],
				'notification_cancelation_reason' => esc_html__( 'Appointment marked as no-show.', 'simply-schedule-appointments' ),
			];
			$this->plugin->revision_model->insert_revision_on_notification_canceled( $appointment_object->id, $data );
			return false;
		}

		if ( $appointment_object->status === 'canceled' ) {
			// We shouldn't send notifications if the appointment was canceled after this action was queued
			if (  $payload['action'] !== 'appointment_canceled' ) {
				if ( ! $appointment_object->is_group_parent() || $appointment_object->is_group_canceled() ) {
					return false;  
				} else if( in_array( '{{customer_email}}', $single_notification_settings['sent_to'] ) ) {
					// this is a cancelled group parent, and this is not the staff email skip it
					return false;
				}
			}
			// unless this is specifically an "appointment_canceled" trigger, in which case we continue on...
		}

		if ( $appointment_object->status === 'abandoned' ) {
			// We shouldn't send notifications if the appointment was abandoned after this action was queued
			if (  $payload['action'] !== 'appointment_abandoned') {
				return false;
			}
			// unless this is specifically an "appointment_abandoned" trigger, in which case we continue on...
		}

		if ( $single_notification_settings['when'] === 'before' && $single_notification_settings['trigger'] === 'appointment_start_date' ) {
			// We shouldn't send notifications if the appointment already started and this was supposed to go out *before* the appointment start time
			if ( ssa_datetime() >= $appointment_object->start_date_datetime ) {
				return false;
			}
		}
		
		// avoid sending duplicate emails to admin and staff, for the same notification
		// if is staff notification and trigger is appointment_start_date
		if( !empty( $single_notification_settings['sent_to'] ) ) {
			if( $single_notification_settings['trigger'] === 'appointment_start_date' && ! in_array( '{{customer_email}}', $single_notification_settings['sent_to'] ) ) {
				// if is group event and is not group parent, skip, so that we're sending only one email to admin
				if( $appointment_object->is_group_event() && ! $appointment_object->is_group_parent() ){
					return false;
				}
			}
		 }

		// Default is all appointment types if not specifically set
		if ( empty( $single_notification_settings['appointment_types'] ) ) {
			return true;
		}

		// Let's check if the appointment type is one of the allowed ones
		if ( in_array( $appointment_object->get_appointment_type()->id, $single_notification_settings['appointment_types'] ) ) {
			return true;
		}
		
		// We've reached this in error, default to not sending the notification
		return false;
	}

	public function maybe_fire_notification( $payload, $async_action ) {
		$notifications = $this->plugin->notifications_settings->get_notifications();
		$responses = array();
		if ( empty( $notifications ) ) {
			$this->fail_async_action( $async_action, 500, 'No notifications in settings', array( 'notifications' => $notifications ) );
			return;
		}

		$appointment_id = $payload['appointment']['id'];

		// Refresh appointment data. If the appointment was deleted between when
		// this action was queued and when the cron picked it up (e.g. via the
		// Purge Past Appointments tool), SSA_Appointment_Object::get() throws
		// "Appointment ID not found". Catching it here so a single orphaned
		// async action doesn't terminate the rest of the batch.
		try {
			$appointment_object = new SSA_Appointment_Object( $appointment_id );
			$payload['appointment'] = $appointment_object->get_data( 0 );
		} catch ( Exception $e ) {
			$this->fail_async_action(
				$async_action,
				404,
				'Could not load appointment for notification: ' . $e->getMessage(),
				array( 'appointment_id' => $appointment_id )
			);
			return;
		}


		foreach ( $notifications as $notification_key => $notification ) {
			if ( empty( $payload['notification']['id'] ) || $payload['notification']['id'] != $notification['id'] ) {
				continue; // skip any non-matches
			}
			if ( ! $this->should_fire_notification( $notification, $payload ) ) {
				$responses[] = array(
					'action' => $payload['action'],
					'skipped' => true,
					'notification' => $notification,
				);				
				continue;
			}

			$responses[] = array(
				'action' => $payload['action'],
				'notification' => $notification,
				'payload' => $payload,
				'response' => $this->fire_notification( $notification, $payload ),
			);

		}

		ssa_complete_action( $async_action['id'], $responses );
		return true;
	}

	public function prepare_notification_template( $string ) {
		$string = str_replace( '<br>', '<br />', $string );
		$string = str_replace(
			array( '<p><br />', '<br /></p>', '}}<br />', '%}<br />' ),
			array( '<p>'      , '</p>'      , '}}'      , '%}'       ),
			$string
		);
		$string = str_replace( '{{ Appointment.customer_information_summary }}', '{% for label, entered_value in Appointment.customer_information_strings %}{% if entered_value|trim %}{{ label|internationalize(Appointment.customer_locale) }}: {{ entered_value|trim|raw }} <br />{%endif%}{% endfor %}', $string );
		$string = str_replace( '{{ Appointment.customer_information_summary_admin_locale }}', '{% for label, entered_value in Appointment.customer_information_strings %}{% if entered_value|trim %}{{ label|internationalize }}: {{ entered_value|trim|raw }} <br />{%endif%}{% endfor %}', $string );
		$instructions = "{{ Appointment.AppointmentType.instructions|raw }} 

			{% if Appointment.web_meeting_url %}
			{{ Appointment.web_meeting_url }} 
			{% endif %}";
		$string = str_replace( '{{ instructions }}', $instructions, $string );

		return $string;
	}

	public function fire_notification( $notification_to_fire, $payload ) {
		// ssa_debug_log( __FUNCTION__ .'()' );
		// ssa_debug_log( $notification_to_fire, 1, '$notification_to_fire' );
		// ssa_debug_log( $payload, 1, '$payload' );

		if ( empty( $payload['appointment']['id'] ) ) {
			return false;
		}

		// Async actions can be queued with a delay, and the row may be gone
		// by the time cron fires (test teardown, group cleanup, reschedule).
		// Skip the notification rather than fataling inside template rendering.
		// db_get_field avoids the notice cascade that a full get() would trip
		// when walking an empty row's relationships.
		$existing_id = $this->plugin->appointment_model->db_get_field( 'id', $payload['appointment']['id'] );
		if ( empty( $existing_id ) ) {
			return false;
		}

		$settings = $this->plugin->settings->get();
		$notifications = $this->plugin->notifications_settings->get_notifications();
		sleep(1); // Throttle emails for shared hosts and prevent race condition with Google Meet web meeting urls
		$appointment_object = new SSA_Appointment_Object( $payload['appointment']['id'] );

		foreach ($notifications as $key => $notification) {

			if ( $notification_to_fire['id'] != $notification['id'] ) {
				continue;
			}

			$appointment_object = new SSA_Appointment_Object( $payload['appointment']['id'] );
			$notification_vars = $this->plugin->templates->get_template_vars( 'notification', array(
				'appointment_id' => $payload['appointment']['id'],
			) );

			if ( empty( $notification['subject'] ) ) {
				$subject = '';
			} else {
				$subject = wp_strip_all_tags( $this->get_rendered_template_string_for_appointment( $appointment_object, $notification['subject'], $notification_vars ), true );
				// Email subjects are plain-text MIME headers and don't decode HTML
				// entities. The kses-final render pipeline leaves customer_information
				// values in their entity-encoded form (e.g. "Smith &amp; Jones"), so
				// decode here for legibility. Safe after wp_strip_all_tags because no
				// HTML markup remains to be reanimated.
				$subject = html_entity_decode( $subject );
			}
			$message = $this->get_rendered_template_string_for_appointment( $appointment_object, $notification['message'], $notification_vars );

			$recipients = array(
				'sent_to' => array(),
				'sms_to' => array(),
				'cc' => array(),
				'bcc' => array(),
			);
			$recipient_type = 'customer';
			foreach ( $recipients as $recipients_key => $recipient_addresses ) {
				if ( empty( $notification[$recipients_key] ) || ! is_array( $notification[$recipients_key] ) ) {
					continue;
				}

				if ( $recipients_key === 'sent_to' ) {
					$recipient_type = ssa_get_recipient_type_for_recipients_array( $notification[$recipients_key] );
				}

				foreach ( $notification[$recipients_key] as $recipient_address_key => $recipient_address ) {
					if ( 'sms' === $notification['type'] && ! empty( $notification['sms_to'] ) && 'sms_to' === $recipients_key && '{{ customer_phone }}' === $recipient_address ) {
						$allow_sms = $appointment_object->allow_sms;
						if ( empty ( $allow_sms ) ) {
							continue;
						}
					}


					$address = $this->plugin->templates->render_template_string( $recipient_address, $notification_vars );
					$address = str_replace( "\n", '', $address );
					if ( empty( $address ) ) {
						continue;
					}
					if ( strpos( $address, ', ' ) ) {
						$addresses = explode( ', ', $address );
						foreach ($addresses as $address) {
							if ( 'email' === $notification['type'] && ! is_email( $address ) ) {
								continue;
							}
							$recipients[$recipients_key][] = $address;
							$recipient_variables[$recipients_key][] = $recipient_address;
						}
					} else {
						if ( 'email' === $notification['type'] && ! is_email( $address ) ) {
							continue;
						}
						$recipients[$recipients_key][] = $address;
						$recipient_variables[$recipients_key][] = $recipient_address;
					}
				}
			}

			if ( 'sms' === $notification['type'] && ! empty( $recipients['sms_to'] ) ) {
				if ( ! $this->plugin->settings_installed->is_enabled( 'sms' ) ) {
					continue;
				}

				// SMS is plain-text. Strip any HTML the kses pipeline allowed
				// through and decode entities so customer_information values
				// like "Smith & Jones" don't arrive as "Smith &amp; Jones".
				// Safe in this order because wp_strip_all_tags removes any
				// markup before html_entity_decode runs.
				$sms_message = html_entity_decode( wp_strip_all_tags( $message ) );

				$response = array();
				foreach ($recipients['sms_to'] as $key => $to_number) {
					$sms_args = apply_filters( 'ssa/notifications/sms/args', array(
						'to_number' => $to_number,
						'notification' => $notification,
						'notification_vars' => $notification_vars,
						'appointment_object' => $appointment_object,
						'subject' => $subject,
						'message' => $sms_message,
					) );

					if ( empty( $sms_args['to_number'] ) ) {
						continue;
					}
					$response[] = $this->plugin->sms->deliver_notification( $sms_args );
				}
				
				$appointment_id = $payload['appointment']['id'];
				$action_noun = $payload['action_noun'];
				$action_verb = $payload['action_verb'];
				$data_after = $payload['appointment'];
				$data_before = $payload['data_before'];
				$notification_type = $notification['type'];
				do_action( 'ssa/notification/sent', $appointment_id, $response, $action_noun, $action_verb, $recipient_type, $notification_type, $data_after, $data_before, isset( $notification['title'] ) ? (string) $notification['title'] : '' );
				return $response;


			}

			if ( 'email' === $notification['type'] && ! empty( $recipients['sent_to'] ) ) {			
				$headers = array(
					'Reply-To: '.$this->get_reply_to_email_for_appointment( $appointment_object, $recipient_type, 'notification', $notification ),
					'Content-Type: text/html',
				);
				if ( ! empty( $recipients['cc'] ) ) {
					$headers[] = 'Cc: '.implode( ',', $recipients['cc'] );
				}

				if ( ! empty( $recipients['bcc'] ) ) {
					$headers[] = 'Bcc: '.implode( ',', $recipients['bcc'] );
				}

				$from_email = $settings['global']['admin_email'];
				$from_name = $this->get_from_name_for_appointment( $appointment_object, $recipient_type, 'notification' );
				$attachments = array();
				// ssa_debug_log($recipients, 10, 'recipients for ' . $notification['title']);
				
				$email_args = apply_filters( 'ssa/notifications/email/args', array(
					'sent_to' => $recipients['sent_to'],
					'subject' => $subject,
					'message' => $message,
					'headers' => $headers,
					'attachments' => $attachments,
					'from_email' => $from_email,
					'from_name' => $from_name,
				), $notification, $notification_vars, $appointment_object, $recipient_type, $recipient_variables );
				
				if ( empty( $email_args ) || empty( $email_args['sent_to'] ) ) {
					return;
				}
				
				$response = $this->ssa_wp_mail(
					$email_args['sent_to'],
					$email_args['subject'],
					$email_args['message'],
					$email_args['headers'],
					$email_args['attachments'],
					$email_args['from_email'],
					$email_args['from_name']
				);
			$appointment_id = $payload['appointment']['id'];
			$action_noun = $payload['action_noun'];
			$action_verb = $payload['action_verb'];
			$data_after = $payload['appointment'];
			$data_before = $payload['data_before'];
			$notification_type = $notification['type'];
			do_action( 'ssa/notification/sent', $appointment_id, $response, $action_noun, $action_verb, $recipient_type, $notification_type, $data_after, $data_before, isset( $notification['title'] ) ? (string) $notification['title'] : '' );
			}
		}

	}

	private function get_template_rendered_for_appointment( SSA_Appointment_Object $appointment_object, $template ) {
		$content = $this->plugin->templates->get_template_rendered( 
			'notifications/email/text/'.$template.'.php',
			array(
				'appointment_id' => $appointment_object->id,
			)
		);

		return $content;
	}

	public function get_rendered_template_string_for_appointment( SSA_Appointment_Object $appointment_object, $template_string, $notification_vars = array() ) {
		if ( empty( $notification_vars ) ) {
			$notification_vars = $this->plugin->templates->get_template_vars( 'notification', array(
				'appointment_id' => $appointment_object->id,
			) );
		}

		$template_string = $this->plugin->templates->cleanup_variables_in_string( $template_string );
		$template_string = $this->prepare_notification_template( $template_string );
		$template_string = $this->plugin->templates->render_template_string( $template_string, $notification_vars );
		$template_string = str_replace(
			array( '&nbsp;' ),
			array( ' ' ),
			$template_string
		);
		// wp_kses_post (inside render_template_string) must remain the last
		// sanitizing transformation — a decode after it can resurrect encoded
		// payloads. make_clickable composes safely with kses output: its URL
		// regex matches "&amp;" in full and esc_url re-encodes it as "&#038;".
		$template_string = make_clickable( $template_string );

		return $template_string;
	}

	public function get_rendered_template_string_for_example_appointment_type( SSA_Appointment_Type_Object $appointment_type_object, $template_string, $notification_vars = array() ) {
		if ( empty( $notification_vars ) ) {
			$notification_vars = $this->plugin->templates->get_template_vars( 'notification', array(
				'example_appointment_type_id' => $appointment_type_object->id,
			) );
		}

		$template_string = $this->plugin->templates->cleanup_variables_in_string( $template_string );
		$template_string = $this->prepare_notification_template( $template_string );
		$template_string = $this->plugin->templates->render_template_string( $template_string, $notification_vars );
		$template_string = str_replace(
			array( '&nbsp;' ),
			array( ' ' ),
			$template_string
		);
		// wp_kses_post (inside render_template_string) must remain the last
		// sanitizing transformation — a decode after it can resurrect encoded
		// payloads. make_clickable composes safely with kses output: its URL
		// regex matches "&amp;" in full and esc_url re-encodes it as "&#038;".
		$template_string = make_clickable( $template_string );

		return $template_string;
	}


	/**
	 * Get mail headers (From/Cc/Bcc) for a given appointment or appointment type
	 *
	 * @param SSA_Appointment_Object $appointment_object
	 * @param string $template
	 * @return array
	 * @author 
	 **/
	public function get_mail_headers_for_appointment( SSA_Appointment_Object $appointment_object, $template ) {
		$headers = array();

		
	}

	public function get_from_name_for_appointment( SSA_Appointment_Object $appointment_object, $recipient, $template ) {
		$settings = $this->plugin->settings->get();

		if ( $recipient == 'customer' ) {
			$value = str_replace( '"', '', $settings['global']['staff_name'] ) .' '.__('at', 'simply-schedule-appointments' ).' '.str_replace( '"', '', $settings['global']['company_name'] );
		} elseif ( $recipient == 'staff' ) {
			$value = $appointment_object->customer_information['Name'] .' ('.$settings['global']['company_name'].')';
		}

		return $value;
	}

	// public function get_from_email_for_appointment( SSA_Appointment_Object $appointment_object, $template ) {
	// 	$settings = $this->plugin->settings->get();

	// 	$value = str_replace( '"', '', $settings['global']['staff_name'] ) .' at '.str_replace( '"', '', $settings['global']['company_name'] );

	// 	return $value;
	// }

	public function get_reply_to_email_for_appointment( SSA_Appointment_Object $appointment_object, $recipient, $template, $notification ) {
		$settings = $this->plugin->settings->get();

		if ( ! empty( $notification['replyTo'] ) && is_array( $notification['replyTo'] ) ) {
			$value = current( $notification['replyTo'] );
		} elseif ( $recipient == 'customer' ) {
			$value = $settings['global']['admin_email'];
		} elseif ( $recipient == 'staff' ) {
			$value = $appointment_object->customer_information['Email'];
		}
		
		return is_email( $value ) ? $value : $settings['global']['admin_email'];
	}

	public function set_ssa_from_name( $name ) {
		global $ssa_wp_mail_from_name;
		global $ssa_wp_mail_from_name_swp;
		$ssa_wp_mail_from_name_swp = $ssa_wp_mail_from_name;
		$ssa_wp_mail_from_name = $name;
	}

	public function reset_ssa_from_name() {
		global $ssa_wp_mail_from_name;
		global $ssa_wp_mail_from_name_swp;
		$ssa_wp_mail_from_name = $ssa_wp_mail_from_name_swp;
		unset( $ssa_wp_mail_from_name_swp );
	}

	public function get_ssa_from_name() {
		global $ssa_wp_mail_from_name;
		return $ssa_wp_mail_from_name;
	}

	public function ssa_wp_mail( $to, $subject, $message, $headers = '', $attachments = array(), $from_email = '', $from_name = '' ) {
		if ( empty( $to ) ) {
			return;
		}

		$args = array (
			'to' => $to,
			'subject' => $subject,
			'message' => '<style>.emoji {width: 1em;height: 1em;vertical-align: -0.1em;display: inline-block;}</style>' . $message,
			'headers' => $headers,
			'attachments' => $attachments,
			'from_email' => $from_email,
			'from_name' => $from_name
		);
		
		$args = apply_filters( 'ssa/email/args', $args );

		$this->set_ssa_from_name( $args['from_name'] );

		add_filter( 'wp_mail_from_name', array( $this, 'get_ssa_from_name' ), 5 );
		$result = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'], $args['attachments'] );
		remove_filter( 'wp_mail_from_name', array( $this, 'get_ssa_from_name' ), 5 );
		
		$this->reset_ssa_from_name();
		return $result;

	}



}
