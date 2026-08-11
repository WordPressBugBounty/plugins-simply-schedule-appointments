<?php
/**
 * Simply Schedule Appointments Users.
 *
 * @since   3.6.2
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Users.
 *
 * @since 3.6.2
 */
class SSA_Users {
	/**
	 * Parent plugin class.
	 *
	 * @since 3.6.2
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  3.6.2
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
	 * @since  3.6.2
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_users_endpoint' ) );
	}

	public function register_users_endpoint() {
		$namespace = 'ssa/v1';
		$base = 'users';

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
		) );
	}

	public function get_items_permissions_check( $request ) {
		return current_user_can( 'ssa_manage_appointments' );
	}

	public function get_items( $request ) {
		global $wpdb;

		$data = array();

		// Match staff-capable users by CAPABILITY, not role: staff caps can be
		// granted per-user (e.g. via the Members plugin) without any role carrying
		// them. This mirrors WP_User_Query's capability__in (every role that has
		// the cap, OR the cap granted directly on the user), hand-rolled because
		// capability__in needs WP 5.9 and is silently ignored below it — which
		// would return every user, the exact disclosure this endpoint must never
		// fall back to. The direct-cap clauses also guarantee the meta_query is
		// never empty, so the all-users fall-through is structurally impossible.
		$capability_meta_query = array( 'relation' => 'OR' );
		foreach ( wp_roles()->roles as $role_slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_posts'] ) || ! empty( $role['capabilities']['ssa_manage_appointments'] ) ) {
				$capability_meta_query[] = array(
					'key'     => $wpdb->get_blog_prefix() . 'capabilities',
					'value'   => '"' . $role_slug . '"',
					'compare' => 'LIKE',
				);
			}
		}
		foreach ( array( 'edit_posts', 'ssa_manage_appointments' ) as $capability ) {
			$capability_meta_query[] = array(
				'key'     => $wpdb->get_blog_prefix() . 'capabilities',
				'value'   => '"' . $capability . '"',
				'compare' => 'LIKE',
			);
		}

		$wp_user_query = new WP_User_Query( array(
			'meta_query' => $capability_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This is the same wp_capabilities meta_query WP_User_Query builds for capability__in; matching by capability is the point of the query and there is no non-meta equivalent.
			// website may have many subscribers, avoid fatal errors
			'number'     => 1000,
		) );
		$users = $wp_user_query->get_results();

		// The admin staff list resolves each linked WP user's avatar/name from
		// this endpoint, so include users already linked to a staff record
		// regardless of their role (e.g. a Subscriber linked with can_login off).
		// Staff is Business-only; the model is an SSA_Missing stub on lower
		// editions, so guard before using it.
		if ( ! ( $this->plugin->staff_model instanceof SSA_Missing ) ) {
			$listed_ids = array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
			$staff_rows = $this->plugin->staff_model->query( array(
				'fields' => array( 'user_id' ),
				'number' => 1000,
			) );

			$linked_ids = array();
			foreach ( $staff_rows as $staff_row ) {
				$user_id = (int) $staff_row['user_id'];
				if ( $user_id && ! in_array( $user_id, $listed_ids, true ) ) {
					$linked_ids[] = $user_id;
				}
			}

			if ( ! empty( $linked_ids ) ) {
				$users = array_merge( $users, get_users( array( 'include' => array_unique( $linked_ids ) ) ) );
			}
		}

		if ( empty( $users ) ) {
			return $data;
		}

		foreach ($users as $user) {
			$user_data = get_userdata($user->ID);
			$data[] = array(
				'id' => $user_data->ID,
				'email' => $user_data->user_email,
				'display_name' => $user_data->display_name,
				'gravatar_url' => get_avatar_url( $user_data->ID ),
			);
		}

		$response = array(
			'response_code' => 200,
			'error' => '',
			'data' => $data,
		);

		return new WP_REST_Response( $response, 200 );
	}
}
