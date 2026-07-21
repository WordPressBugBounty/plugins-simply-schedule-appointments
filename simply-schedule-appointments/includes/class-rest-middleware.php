<?php
/**
 * Simply Schedule Appointments REST Middleware.
 *
 * Sanitizes incoming SSA REST requests before any controller callback runs.
 *
 * @package Simply_Schedule_Appointments
 */

class SSA_REST_Middleware {
	/**
	 * Parameter keys that are strictly internal and must never be accepted
	 * from an incoming HTTP request, regardless of method or content type.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_REQUEST_PARAMS = array(
		'append_where_sql',
	);

	protected $plugin = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	public function hooks() {
		add_filter( 'rest_pre_dispatch', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Strip internal-only parameters from every incoming REST request.
	 *
	 * The strip runs for all routes, not just SSA-namespaced ones. Gating it on
	 * the request route is unsafe: WP matches routes case-insensitively, so a
	 * route filter like '/ssa/' is bypassed by requesting '/SSA/...', which lets
	 * a forbidden key (e.g. append_where_sql) survive into a controller and be
	 * concatenated into SQL. These keys are never legitimate on any HTTP request,
	 * so stripping unconditionally makes that bypass class unreachable.
	 *
	 * WP_REST_Request::offsetUnset removes the key from every parameter source
	 * (URL, GET, POST, JSON body, form-urlencoded body, defaults), so by the
	 * time any controller calls $request->get_params() or $request->get_param(),
	 * the forbidden key is gone — regardless of HTTP method or content type.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function handle( $result, $server, $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		foreach ( self::FORBIDDEN_REQUEST_PARAMS as $forbidden ) {
			unset( $request[ $forbidden ] );
		}

		return $result;
	}
}
