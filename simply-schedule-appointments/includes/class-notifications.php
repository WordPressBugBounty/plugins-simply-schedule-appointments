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
	
	public function queue_start_date_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_start_date', 'ssa_fire_appointment_start_date_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_customer_information_edited_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_customer_information_edited', 'ssa_fire_appointment_customer_information_edited_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_canceled_notifications( $appointment_id, $data, $data_before = array(), $response = null ) {
		$this->queue_notifications( 'appointment_canceled', 'ssa_fire_appointment_canceled_notifications', $appointment_id, $data, $data_before, $response );
	}

	public function queue_notifications( $hook, $action_to_fire, $appointment_id, $data, $data_before = array(), $response = null ) {
		if ( ! $this->plugin->settings_installed->is_enabled( 'notifications' ) ) {
			return false;
		}

		$notifications = $this->plugin->notifications_settings->get_notifications();
		if ( empty( $notifications ) ) {
			return;
		}

		$appointment_object = new SSA_Appointment_Object( $appointment_id );
		foreach ($notifications as $key => $notification) {
			if ( ! empty( $notification['appointment_types'] ) && is_array( $notification['appointment_types'] ) && ! in_array( $appointment_object->appointment_type_id, $notification['appointment_types'] ) ) {
				continue;
			}

			if ( $notification['trigger'] !== $hook ) {
				continue;
			}

			if ( isset( $notification['active'] ) && empty( $notification['active'] ) ) {
				continue; // if it isn't set yet, then the settings may have been stored before the active toggle existed. They default on, so if 'active' isn't set, we'll assume it should be on.
			}

			if ( 'sms' === $notification['type'] && ! empty( $notification['sms_to'] ) ) {
				if ( ! $this->plugin->settings_installed->is_enabled( 'sms' ) ) {
					continue;
				}
			}

			$meta = array();
			$date_queued_datetime = ssa_datetime();
			if ( $notification['trigger'] === 'appointment_start_date' ) {
				$date_queued_datetime = $appointment_object->start_date_datetime;
			}

			// Add 3 seconds to notification date_queued to allow the web_meeting_url to return
			if ( $notification['trigger'] === 'appointment_booked' ) {
				$date_queued_datetime = $date_queued_datetime->add( new DateInterval( 'PT5S' ) );

			}

			if ( ! empty( $notification['duration'] ) ) {
				$interval_string = 'PT'.absint( $notification['duration'] ).'M';
				if ( $notification['when'] === 'after' ) {
					$date_queued_datetime = $date_queued_datetime->add( new DateInterval( $interval_string ) );
				} else {
					$date_queued_datetime = $date_queued_datetime->sub( new DateInterval( $interval_string ) );
				}
			}

			if ( $notification['trigger'] === 'appointment_start_date' && $date_queued_datetime <= ssa_datetime() ) {
				continue; // Don't schedule reminders if they would be sent before the appointment was actually booked
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
			do_action( 'ssa/notification/scheduled', $appointment_id, $action_noun, $action_verb, $notification_date, $notification_time, $duration, $recipient_type,$data_after, $data_before);
		}

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

		// Refresh appointmnent data
		$appointment_object = new SSA_Appointment_Object( $appointment_id );
		$payload['appointment'] = $appointment_object->get_data( 0 );


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

				$response = array();
				foreach ($recipients['sms_to'] as $key => $to_number) {
					$sms_args = apply_filters( 'ssa/notifications/sms/args', array(
						'to_number' => $to_number,
						'notification' => $notification,
						'notification_vars' => $notification_vars,
						'appointment_object' => $appointment_object,
						'subject' => $subject,
						'message' => $message,
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
				do_action( 'ssa/notification/sent', $appointment_id, $response, $action_noun, $action_verb, $recipient_type, $notification_type, $data_after, $data_before);
				return $response;


			}

			if ( 'email' === $notification['type'] && ! empty( $recipients['sent_to'] ) ) {			
				$headers = array(
					'Reply-To: '.$this->get_reply_to_email_for_appointment( $appointment_object, $recipient_type, 'notification' ),
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
			do_action( 'ssa/notification/sent', $appointment_id, $response, $action_noun, $action_verb, $recipient_type, $notification_type, $data_after, $data_before);
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
		$template_string = htmlspecialchars_decode( $template_string );
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
		$template_string = htmlspecialchars_decode( $template_string );
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

	public function get_reply_to_email_for_appointment( SSA_Appointment_Object $appointment_object, $recipient, $template ) {
		$settings = $this->plugin->settings->get();

		if ( $recipient == 'customer' ) {
			$value = $settings['global']['admin_email'];
		} elseif ( $recipient == 'staff' ) {
			$value = $appointment_object->customer_information['Email'];
		}
		
		return $value;
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
			'message' => $message,
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
