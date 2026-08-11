<?php
/**
 * Simply Schedule Appointments Customers.
 *
 * @since   2.7.1
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Customers.
 *
 * @since 2.7.1
 */
class SSA_Customers {
	/**
	 * Parent plugin class.
	 *
	 * @since 2.7.1
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  2.7.1
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
	 * @since  2.7.1
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_customers_endpoint' ) );
	}

	public function register_customers_endpoint( $request ) {
		$namespace = 'ssa/v1';
		$base = 'customers';

		register_rest_route( $namespace, '/' . $base . '/', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(
					'context'          => array(
						'default'      => 'view',
					),
				),
			),
			array(
				'methods'         => WP_REST_Server::CREATABLE,
				'callback'        => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => array(),
			),
		) );
	}

	public function get_items_permissions_check( $request ) {
		return current_user_can( 'ssa_manage_appointments' );
	}

	public function get_items( $request ) {
		$params = $request->get_params();
		
		// Support both GET (legacy) and POST (to avoid 414 errors with large ID lists)
		if ( 'POST' === $request->get_method() ) {
			$body = $request->get_json_params();
			if ( ! empty( $body['ids'] ) ) {
				$params['ids'] = $body['ids'];
			}
		}

		$data = array();
		if ( ! empty( $params['ids'] ) ) {
			if ( ! is_array( $params['ids'] ) ) {
				$params['ids'] = json_decode( $params['ids'], true );
			}

			if ( is_array( $params['ids'] ) ) {
				// Only resolve IDs referenced by appointments the requester can actually
				// reach — as the customer, or as the author who created the booking (the
				// admin app displays both) — so a low-privilege staff account can't
				// enumerate the email-hash of arbitrary users it never booked.
				$visible_user_ids = $this->get_visible_user_ids( $params['ids'] );

				foreach ($params['ids'] as $key => $id) {
					if ( ! in_array( (int) $id, $visible_user_ids, true ) ) {
						continue;
					}

					$user = new WP_User( $id );
					if ( empty( (array)$user->data ) ) {
						continue;
					}

					$user_data = (array)$user->data;
					$data[] = array(
						'id' => $user_data['ID'],
						'name' => $user_data['display_name'],
						'gravatar_url' => get_avatar_url( $user_data['ID'] )
					);
				}
			}
		}

		$response = array(
			'response_code' => 200,
			'error' => '',
			'data' => $data,
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Of the requested user IDs, return only those referenced by an appointment
	 * the current user is allowed to see — as its customer, or as the author who
	 * created it. Reuses the appointment query so the same visibility rules as
	 * the appointment listing apply.
	 *
	 * @param array $requested_ids Raw requested user IDs.
	 * @return int[] The subset the current user may resolve.
	 */
	protected function get_visible_user_ids( $requested_ids ) {
		global $wpdb;

		$requested_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $requested_ids ) ) ) );
		if ( empty( $requested_ids ) ) {
			return array();
		}

		$placeholders     = implode( ',', array_fill( 0, count( $requested_ids ), '%d' ) );
		$reference_filter = $wpdb->prepare(
			' AND (customer_id IN (' . $placeholders . ') OR author_id IN (' . $placeholders . '))', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated list of %d placeholders, not user input; every id is int-cast above and bound through the second argument.
			array_merge( $requested_ids, $requested_ids )
		);

		// Which appointments this caller may reach is the appointment model's rule to
		// state, not ours — asking it keeps this endpoint and the appointment listing
		// from drifting apart, which is how the Staff-stripped editions ended up with
		// no ownership check here at all.
		$query_args = ssa()->appointment_model->get_current_user_visibility_query_args();

		$append_where_sql = isset( $query_args['append_where_sql'] ) ? (array) $query_args['append_where_sql'] : array();
		array_unshift( $append_where_sql, $reference_filter );

		$query_args['append_where_sql'] = $append_where_sql;
		$query_args['fields']           = array( 'customer_id', 'author_id' );
		// The reference filter matches every appointment that touches a requested id,
		// which for a repeat customer is unbounded; without a cap the model's 100000
		// default hydrates all of them on every admin appointment-list render.
		$query_args['number'] = 1000;

		$appointments = ssa()->appointment_model->query( $query_args );

		$visible = array();
		foreach ( $appointments as $appointment ) {
			$visible[] = (int) $appointment['customer_id'];
			$visible[] = (int) $appointment['author_id'];
		}

		return array_values( array_unique( $visible ) );
	}
}
