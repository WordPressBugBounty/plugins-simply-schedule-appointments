<?php
/**
 * Simply Schedule Appointments Divi module.
 *
 * @since   3.7.6
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Divi module.
 *
 * Handles both Divi 4 and Divi 5 compatibility:
 * - Divi 4: Uses DiviExtension class
 * - Divi 5: Uses WordPress Block API with module.json
 *
 * @since 3.7.6
 */
class SSA_Divi {
	/**
	 * Shortcode attributes this route refuses, whatever the tag.
	 *
	 * Allowlisting the tag is not enough: [ssa_booking edit=<id>] makes
	 * SSA_Shortcodes::ssa_booking() mint an id-token for that appointment through
	 * get_id_token(), which performs no capability check, and hand it back in the
	 * iframe URL -- so a non-manager could iterate ids and harvest a token each.
	 * The builder only previews a booking form, so neither attribute has a
	 * legitimate use here.
	 *
	 * @var string[]
	 */
	const PRIVILEGED_ATTS = array(
		'edit',
		'token',
	);

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
	 * Setup hooks if the builder is installed and activated.
	 */
	public function hooks() {
		// Check Divi version BEFORE adding any hooks so Divi's compatibility
		// check doesn't see Divi 4 hooks when we're running Divi 5
		$is_divi_5 = $this->is_divi_5();
		
		if ( $is_divi_5 ) {
			// Divi 5: Load server files IMMEDIATELY so the hook registration
			// is in $wp_filter when Divi's compatibility check runs
			$this->load_divi5_server_files();
			
			// Load Divi 5 module
			add_action( 'init', array( $this, 'maybe_load_divi5_module' ), 5 );
			
			// Enqueue scripts for Divi 5
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_divi5_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_divi5_scripts' ) );
		} else {
			// Divi 4: Only hook into Divi 4 hooks if NOT Divi 5
			add_action( 'divi_extensions_init', array( $this, 'maybe_load_divi4_module' ) );
		}
		
		// REST API for Visual Builder (both versions)
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Load Divi 5 server files for module registration.
	 * 
	 * @since 5.9.0
	 */
	public function load_divi5_server_files() {
		if ( ! $this->is_divi_5() ) {
			return;
		}
		
		$server_modules_file = __DIR__ . '/divi5/server/Modules.php';
		if ( file_exists( $server_modules_file ) ) {
			require_once $server_modules_file;
		}
	}

	/**
	 * Check if Divi 5 is active.
	 *
	 * @return bool True if Divi 5 is active, false otherwise.
	 */
	public function is_divi_5() {
		// Check active theme
		$theme = wp_get_theme();
		$theme_name = $theme->get( 'Name' );
		$template   = $theme->get_template();

		// Use the parent theme's version when a child theme is active.
		$version_source = ( $template && $template !== $theme->get_stylesheet() ) ? wp_get_theme( $template ) : $theme;
		$theme_version  = $version_source->exists() ? $version_source->get( 'Version' ) : $theme->get( 'Version' );

		// Check if the active theme is Divi and version 5+
		if ( ( $theme_name === 'Divi' || $template === 'Divi' ) && version_compare( $theme_version, '5.0', '>=' ) ) {
			return true;
		}
		
		// Check if Divi 5 ModuleRegistration class exists
		if ( class_exists( 'ET\Builder\Packages\ModuleLibrary\ModuleRegistration' ) ) {
			return true;
		}

		// Also check ET_BUILDER_PRODUCT_VERSION constant (Divi 5 is version 5.x)
		if ( defined( 'ET_BUILDER_PRODUCT_VERSION' ) ) {
			$version = ET_BUILDER_PRODUCT_VERSION;
			// Check if version starts with 5
			if ( version_compare( $version, '5.0', '>=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loads Divi 4 module.
	 * 
	 * Only loads if Divi 5 is NOT active to prevent duplicate entries in migrator.
	 *
	 * @since 3.7.6
	 */
	public function maybe_load_divi4_module() {
		// Skip Divi 4 module if Divi 5 is active
		if ( $this->is_divi_5() ) {
			return;
		}
		
		require_once ( __DIR__ . '/divi/includes/SsaDiviModule.php' );
	}

	/**
	 * Loads Divi 5 module if Divi 5 IS active.
	 *
	 * @since 5.9.0
	 */
	public function maybe_load_divi5_module() {
		// Check if this is actually Divi 5
		if ( ! $this->is_divi_5() ) {
			return;
		}
		
		$this->load_divi5_module();
	}

	/**
	 * Loads Divi 5 module.
	 *
	 * @since 5.9.0
	 */
	public function load_divi5_module() {
		// The module class will be loaded and instantiated via server/Modules.php
		// when Divi fires the divi_module_library_modules_dependency_tree hook.
		// Divi's dependency management system will automatically call the load() method.
		// We don't need to do anything here - just keeping this method for potential future use.
	}

	/**
	 * Enqueue scripts for Divi 5 Visual Builder.
	 *
	 * @since 5.9.0
	 */
	public function enqueue_divi5_scripts() {
		$script_url = plugin_dir_url( __FILE__ ) . 'divi5/build/ssa-booking-module.js';
		$script_path = __DIR__ . '/divi5/build/ssa-booking-module.js';

		if ( file_exists( $script_path ) ) {
			wp_register_script(
				'ssa-divi5-booking-module',
				$script_url,
				array( 'react', 'react-dom', 'wp-blocks', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n', 'divi-module-library' ),
				filemtime( $script_path ),
				true // Load in footer after dependencies
			);
			
			// Get appointment types
			$appointment_types = $this->get_appointment_types_for_js();
			
			// Localize appointment types data
			wp_localize_script(
				'ssa-divi5-booking-module',
				'ssaAppointmentTypes',
				$appointment_types
			);
			
			// Enqueue in block editor OR Visual Builder
			if ( is_admin() || ( function_exists( 'et_fb_is_enabled' ) && et_fb_is_enabled() ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only enqueue decision; only detects presence of the Divi Visual Builder query flag, no state change and the value is not used.
				wp_enqueue_script( 'ssa-divi5-booking-module' );
			}
		}
	}

	/**
	 * Get appointment types for JavaScript.
	 *
	 * @since 5.9.0
	 * @return array Array of appointment types.
	 */
	private function get_appointment_types_for_js() {
		$types = array();
		
		// Add "All Types" option
		$types[] = array(
			'label' => 'All Types',
			'value' => '',
		);

		// Get appointment types from SSA
		if ( isset( $this->plugin->appointment_type_model ) ) {
			$appointment_types = $this->plugin->appointment_type_model->query( array(
				'status' => 'publish',
				'orderby' => 'title',
				'order' => 'ASC',
			) );

			foreach ( $appointment_types as $type ) {
				$types[] = array(
					'label' => $type['title'],
					'value' => (string) $type['id'],
				);
			}
		}

		return $types;
	}

	/**
	 * Register REST API routes for Divi 5.
	 *
	 * @since 5.9.0
	 */
	public function register_rest_routes() {
		// Only the Divi 5 Visual Builder calls this route, but hooks() registers it
		// on every install -- the add_action sits outside the is_divi_5() branch --
		// so sites running Elementor, Beaver Builder or plain Gutenberg were exposing
		// it too. The allowlist makes it safe either way; not registering it at all
		// removes the endpoint outright from the large majority of sites that can
		// never legitimately call it.
		//
		// Checked here rather than in hooks(): this runs on rest_api_init, by which
		// point the theme is loaded, so the Divi theme/class/constant probes are
		// meaningful. At hooks() time (plugin load) the theme has not loaded yet and
		// the check would false-negative on real Divi sites.
		if ( ! $this->is_divi_5() ) {
			return;
		}

		register_rest_route( 'ssa/v1', '/render-shortcode', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'render_shortcode_callback' ),
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			},
		) );
	}

	/**
	 * Render shortcode callback for REST API.
	 *
	 * @since 5.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function render_shortcode_callback( $request ) {
		$shortcode = $request->get_param( 'shortcode' );

		// empty() would also catch a literal "0", which is a real payload and belongs
		// to the allowlist decision below (a 403 -- it holds no shortcode) rather
		// than to "nothing was sent". Every other falsy shape still 400s as before.
		if ( empty( $shortcode ) && '0' !== $shortcode ) {
			return new WP_Error( 'no_shortcode', 'No shortcode provided', array( 'status' => 400 ) );
		}

		if ( ! $this->render_shortcode_is_allowed( $shortcode ) ) {
			return new WP_Error( 'ssa_shortcode_not_allowed', 'This shortcode may not be rendered here.', array( 'status' => 403 ) );
		}

		// Render the shortcode
		$html = do_shortcode( $shortcode );

		return rest_ensure_response( array(
			'html' => $html,
		) );
	}

	/**
	 * Whether the current user may render every shortcode contained in $content
	 * through this route.
	 *
	 * The route exists only so the Divi builder can preview a booking form, but it
	 * runs do_shortcode() on caller-supplied input and is gated only on edit_posts,
	 * so without an allowlist a Contributor could render the admin appointment
	 * shortcodes and read every booking on the site (WPScan report). Access is
	 * capability-scoped: any edit_posts caller gets the public booking shortcodes,
	 * a signed-in caller also gets the customer-scoped appointment shortcodes, and
	 * the admin appointment shortcodes, which can read other people's records, need
	 * ssa_manage_appointments.
	 *
	 * @param string $content Raw shortcode string from the request.
	 * @return bool True when every shortcode in the string is allowed for the
	 *              current user, none carries a PRIVILEGED_ATTS attribute, and
	 *              none uses the enclosed form.
	 */
	protected function render_shortcode_is_allowed( $content ) {
		// e.g. an array posted as shortcode[]= -- reject rather than coerce.
		if ( ! is_string( $content ) ) {
			return false;
		}

		// The public booking shortcodes are safe for any caller. The customer-scoped
		// appointment shortcodes render only the *signed-in* caller's own bookings
		// (SSA_Shortcodes::USER_GATED_SHORTCODES) and return nothing for a logged-out
		// visitor, so only offer them once there is a signed-in user to scope to.
		// Only the admin shortcodes, which can read other people's records, require
		// ssa_manage_appointments.
		$allowed = SSA_Shortcodes::PUBLIC_SHORTCODES;

		if ( is_user_logged_in() ) {
			$allowed = array_merge( $allowed, SSA_Shortcodes::USER_GATED_SHORTCODES );
		}

		if ( current_user_can( 'ssa_manage_appointments' ) ) {
			$allowed = array_merge( $allowed, SSA_Shortcodes::SENSITIVE_SHORTCODES );
		}

		// Every sibling tag is checked, not just the first: do_shortcode() runs them
		// all, so "[ssa_booking][ssa_admin_upcoming_appointments]" must be refused.
		// Unregistered tags cannot execute (they render verbatim) and are ignored.
		preg_match_all( '/' . get_shortcode_regex() . '/', $content, $matches );
		$found = empty( $matches[2] ) ? array() : array_filter( (array) $matches[2] );

		if ( empty( $found ) ) {
			return false;
		}

		foreach ( $found as $index => $tag ) {
			if ( ! in_array( $tag, $allowed, true ) ) {
				return false;
			}

			// Refuse any tag carrying enclosed content. get_shortcode_regex() puts
			// that content in $matches[5] and does NOT recurse into it, so a nested
			// tag there is invisible to this loop -- checking sibling tags above is
			// not enough on its own. Nothing legitimate sends it: the builder only
			// ever posts a self-closing tag. This also keeps the content away from
			// ssa_booking()'s second parameter, which WordPress fills with it and
			// that method reads as $is_embedded_page.
			//
			// "[tag][/tag]" with empty content stays allowed: there is no nested tag
			// to hide and the empty string is falsy, so neither risk applies.
			if ( isset( $matches[5][ $index ] ) && '' !== $matches[5][ $index ] ) {
				return false;
			}

			$atts = isset( $matches[3][ $index ] ) ? shortcode_parse_atts( $matches[3][ $index ] ) : array();
			if ( ! is_array( $atts ) ) {
				continue; // No named attributes to inspect.
			}

			foreach ( self::PRIVILEGED_ATTS as $privileged_att ) {
				if ( array_key_exists( $privileged_att, $atts ) ) {
					return false;
				}
			}
		}

		return true;
	}

}
