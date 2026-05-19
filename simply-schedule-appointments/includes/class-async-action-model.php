<?php
/**
 * Simply Schedule Appointments Action Model.
 *
 * @since   1.9.4
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Action Model.
 *
 * @since 1.9.4
 */
class SSA_Async_Action_Model extends TD_Async_Action_Model {
	protected $hook_namespace = 'ssa';
	protected $db_namespace   = 'ssa';
	protected $api_namespace  = 'ssa';
	protected $api_version    = '1';

	/**
	 * Parent plugin class.
	 *
	 * @since 1.9.4
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  1.9.4
	 *
	 * @param  Simply_Schedule_Appointments $plugin Main plugin object.
	 */
	public function __construct( $plugin ) {
		parent::__construct( $plugin );

		$this->hooks();
	}

	/**
	 * Initiate our hooks.
	 *
	 * @since  1.9.4
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		if ( ! wp_next_scheduled( 'ssa_cron_process_async_actions' ) ) {
			add_action( 'init', array( $this, 'schedule_cron' ) );
		}
		add_action( 'ssa_cron_process_async_actions', array( $this, 'execute_cron_process_async_actions' ) );

		add_action( 'init', array( $this, 'schedule_async_action_cleanup' ) );
		add_action( 'ssa/async_actions/cleanup', array( $this, 'cleanup_async_actions' ) );

		add_action( 'ssa/appointment/booked', array( $this, 'mint_async_delay_token' ), 10, 1 );
	}

	/**
	 * Mint a one-shot, short-lived token that authorises a single /async?delay=N
	 * request for this appointment.
	 *
	 * The /async endpoint sleeps for the requested delay so notifications/webhooks
	 * queued at +5s have time to mature before the queue is drained. Without a
	 * gate, the unauthenticated endpoint amplifies any anonymous request into a
	 * worker hold (CVE-2026-7493). Tying the sleep to a freshly-completed booking
	 * forces an attacker to complete a real booking per attempt and limits
	 * lifetime to 30s.
	 */
	public function mint_async_delay_token( $appointment_id ) {
		if ( empty( $appointment_id ) ) {
			return;
		}
		set_transient( 'ssa_async_delay_' . absint( $appointment_id ), 1, 30 );
	}

	/**
	 * Verify and consume the one-shot delay token for $appointment_id.
	 * Returns true exactly once per minted token.
	 */
	protected function consume_async_delay_token( $appointment_id ) {
		if ( empty( $appointment_id ) ) {
			return false;
		}
		$key = 'ssa_async_delay_' . absint( $appointment_id );
		if ( false === get_transient( $key ) ) {
			return false;
		}
		delete_transient( $key );
		return true;
	}

	/**
	 * Filter the where conditions for the query
	 *
	 * @param string $where
	 * @param array $args
	 * @return string
	 */
	public function filter_where_conditions( $where, $args ) {
		global $wpdb;
		
		if ( ! empty( $args['object_id'] ) ) {
			$where .= $wpdb->prepare( ' AND object_id=%d', sanitize_text_field( $args['object_id'] ) );
		}
		
		if ( ! empty( $args['object_type'] ) ) {
			$where .= $wpdb->prepare( ' AND object_type=%s', sanitize_text_field( $args['object_type'] ) );
		}
		
		return $where;
	}
	
	/**
	 * Scheduling the cleanup of completed async actions
	 *
	 * @return void
	 */
	public function schedule_async_action_cleanup() {
		if( ssa_should_skip_async_logic() ) {
			return;
		}

		if ( false === ssa_has_scheduled_action( 'ssa/async_actions/cleanup' ) ) {
			ssa_schedule_recurring_action( strtotime( 'now' ), DAY_IN_SECONDS, 'ssa/async_actions/cleanup' );
		}
	}

	/**
	 * completed async actions cleanup
	 *
	 * @return void
	 */
	public function cleanup_async_actions() {
		$all_async_actions = $this->query(
			array(
				'date_completed_max' => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
				'date_completed_min' => SSA_Constants::EPOCH_START_DATE,
				'number'              => 10000,
			)
		);

		if ( empty( $all_async_actions ) ) { 
			return;
		}
		
		// get ids of async actions as an array
		$all_async_actions_ids = wp_list_pluck( $all_async_actions, 'id' );
		if ( ! empty( $all_async_actions_ids ) ) {
			// delete async actions rows
			$this->bulk_delete( array(
				'id' => $all_async_actions_ids,
			) );
		}
	}

	public function filter_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['ssa_async_interval'] ) ) {
			$interval_in_seconds = 60;
			if ( defined( 'SSA_ASYNC_CRON_INTERVAL' ) ) {
				$interval_in_seconds = SSA_ASYNC_CRON_INTERVAL;
			}

			$schedules['ssa_async_interval'] = array(
				'interval' => $interval_in_seconds,
				'display'  => __( 'Once every minute', 'simply-schedule-appointments' ),
			);
		}

		return $schedules;
	}
	public function schedule_cron() {
	wp_schedule_event( time(), 'ssa_async_interval', 'ssa_cron_process_async_actions' ); }

	public function execute_cron_process_async_actions() {
		$this->process();
	}

	public function register_routes() {
		$version   = '1';
		$namespace = 'ssa/v' . $version;
		$base      = 'async';

		register_rest_route(
			 $namespace,
			'/' . $base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_endpoint' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'process_endpoint' ),
					'permission_callback' => '__return_true',
				),
			)
			);
	}

	public function process_endpoint( $request ) {
		define( 'SSA_DOING_ASYNC', true );
		$params = $request->get_params();

		if ( ! empty( $params['delay'] ) ) {
			// Cap [0, 10] as defence-in-depth; legitimate caller passes 7. Even
			// with a valid token the sleep is bounded so a worker can't be held
			// arbitrarily long.
			$delay          = min( 10, max( 0, (int) $params['delay'] ) );
			$appointment_id = ! empty( $params['object_id'] ) ? absint( $params['object_id'] ) : 0;

			if ( $delay > 0 && $this->consume_async_delay_token( $appointment_id ) ) {
				sleep( $delay );
			}
		}

		$params = shortcode_atts(
			 array(
				 'object_type' => '',
				 'object_id'   => '',
			 ),
			$params
			);

		// TODO: narrow scope to only appointment type

		$this->process();

		// Call the runner() on wp_actionscheduler_actions
		ssa_run_action_scheduler_queue();

		return true;
	}
}

function ssa_doing_async() {
	return defined( 'SSA_DOING_ASYNC' ) && SSA_DOING_ASYNC;
}

function ssa_queue_action( $hook, $action = null, $priority = 10, $payload = array(), $object_type = null, $object_id = null, $action_group = null, $meta = array() ) {
	if ( empty( $action ) ) {
		$action = 'ssa_async_' . $hook;
	}

	ssa()->async_action_model->queue_action( $hook, $action, $priority, $payload, $object_type, $object_id, $action_group, $meta );
}

function ssa_complete_action( $action_id, $response = array() ) {
	ssa()->async_action_model->complete_action( $action_id, $response );
}
