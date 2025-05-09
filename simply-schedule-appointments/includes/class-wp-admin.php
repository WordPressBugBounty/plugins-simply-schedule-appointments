<?php
/**
 * Simply Schedule Appointments Wp Admin.
 *
 * @since   0.0.3
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Wp Admin.
 *
 * @since 0.0.3
 */
class SSA_Wp_Admin {
	protected $script_handle_whitelist = array();
	protected $style_handle_whitelist = array();

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
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		add_action( 'admin_init', array( $this, 'ssa_gravity_forms_editor_styles' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'dashboard_upcoming_appointments_widget' ) );
		add_action( 'admin_init', array( $this, 'upcoming_appointments_widget' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ), 0 );
		add_action( 'admin_init', array( $this, 'store_enqueued_styles_scripts' ), 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'disable_third_party_styles_scripts' ), 9999999 );
		add_action( 'admin_body_class', array( $this, 'body_class' ) );

		add_action( 'admin_print_scripts', array( $this, 'remove_admin_notices' ) );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_upgrade_link'), 10, 2 );
		
		// rest api
		add_action( 'rest_api_init', array( $this, 'register_wp_endpoints' ) );

	}
	
	public function register_wp_endpoints(){
		$namespace = 'ssa/v1';

		register_rest_route( $namespace, '/pages', array(
			array(
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_wp_pages' ),
				'permission_callback' => array( $this, 'get_wp_pages_permissions_check' ),
				'args'            => array(
					'context'          => array(
						'default'      => 'view',
					),
				),
			),
		) );
	}
	
	public function get_wp_pages_permissions_check( $request ) {
		return current_user_can( 'ssa_manage_site_settings' );
	}
	
	public function get_wp_pages( $request ) {
		$args = array(
			'sort_order' => 'ASC',
			'sort_column' => 'title',
		);
		
		$pages = get_pages($args);
		
		// Adjust structure to match what the front-end expects
		foreach ($pages as $key => $page) {
			$pages[$key]->id = $page->ID;
			$pages[$key]->title = array(
				'rendered' => $page->post_title,
			);
		}
		
		return rest_ensure_response( $pages );
	}

	public function ssa_gravity_forms_editor_styles() {
		wp_enqueue_style( 'ssa_gravity_forms_editor_styles', $this->plugin->url( 'assets/css/ssa-gravity-forms-editor-styles.css' ), array(), Simply_Schedule_Appointments::VERSION );
	}
	
	public function dashboard_upcoming_appointments_widget() {
		wp_enqueue_style( 'dashboard_upcoming_appointments_widget_styles', $this->plugin->url( 'assets/css/dashboard-upcoming-appointments-widget.css' ), array(), Simply_Schedule_Appointments::VERSION );
	}
	
	public function upcoming_appointments_widget() {
		wp_enqueue_style( 'upcoming_appointments_widget_styles', $this->plugin->url( 'assets/css/upcoming-appointments.css' ), array(), Simply_Schedule_Appointments::VERSION );
	}

	public function maybe_redirect() {
		if ( !empty( $_GET['page'] ) && $_GET['page'] === 'simply-schedule-appointments-settings' ) {
			wp_redirect( $this->url( '/ssa/settings/all' ) );
			exit;
		}

		if ( !empty( $_GET['page'] ) && $_GET['page'] === 'simply-schedule-appointments-types' ) {
			wp_redirect( $this->url( '/ssa/appointment-types/all' ) );
			exit;
		}

		if ( !empty( $_GET['page'] ) && $_GET['page'] === 'simply-schedule-appointments-support' ) {
			wp_redirect( $this->url( '/ssa/support' ) );
			exit;
		}

		if ( !empty( $_GET['page'] ) && $_GET['page'] === 'simply-schedule-appointments-team' ) {
			wp_redirect( $this->url( '/ssa/settings/staff/all' ) );
			exit;
		}

		if ( !empty( $_GET['page'] ) && $_GET['page'] === 'simply-schedule-appointments-ssa-support-admin' ) {
			wp_redirect( admin_url( 'admin.php?page=simply-schedule-appointments&ssa-support-admin=1' ) );
			exit;
		}
	}

	public function url( $path='' ) {
		$url = admin_url( 'admin.php?page=simply-schedule-appointments' );
		if ( empty( $path ) ) {
			return $url;
		}

		$path = ltrim( $path, '/' );
		$url .= '/#/'.$path;

		return $url;
	}

	public function remove_admin_notices() {
		if ( !$this->is_admin_page() ) {
			return;
		}
		global $wp_filter;
		if (is_user_admin()) {
			if (isset($wp_filter['user_admin_notices'])) {
				unset($wp_filter['user_admin_notices']);
			}
		} elseif (isset($wp_filter['admin_notices'])) {
			unset($wp_filter['admin_notices']);
		}
		if (isset($wp_filter['all_admin_notices'])) {
			unset($wp_filter['all_admin_notices']);
		}
	}

	public function is_admin_page() {
		if ( empty( $_GET['page'] ) || strpos( $_GET['page'], 'simply-schedule-appointments' ) === false ) {
			return false;
		}

		return true;
	}

	public function store_enqueued_styles_scripts() {
		if ( !$this->is_admin_page() ) {
			return;
		}

		global $wp_scripts;
		$this->script_handle_whitelist = $wp_scripts->queue;
	}

	public function disable_third_party_styles_scripts() {
		if ( ! $this->is_admin_page() ) {
			return;
		}

		if ( $this->should_restrict_access_to_admin_page() ) {
			return;
		}

		$custom_whitelist = array(

		);

		global $wp_scripts;
		foreach ($wp_scripts->queue as $key => $handle) {
			if ( strpos( $handle, 'ssa-' ) === 0 ) {
				continue;
			}

			if ( in_array( $handle, $this->script_handle_whitelist ) || in_array( $handle, $custom_whitelist ) ) {
				continue;
			}

			wp_dequeue_script( $handle );
		}

		global $wp_styles;
		foreach ($wp_styles->queue as $key => $handle) {
			if ( strpos( $handle, 'ssa-' ) === 0 ) {
				continue;
			}

			if ( in_array( $handle, $this->style_handle_whitelist ) || in_array( $handle, $custom_whitelist ) ) {
				continue;
			}

			wp_dequeue_style( $handle );
		}

	}

	public function plugin_action_upgrade_link( $links, $file ) {
		if ( strpos($file, 'simply-schedule-appointments.php') === false ) {
			return $links;
		}

		if ( $this->plugin->get_current_edition() !== 1 ) {
			return $links;
		}

		$ssa_pricing_url = 'https://simplyscheduleappointments.com/pricing/?utm_source=plugin&utm_medium=ads&utm_campaign=upgrade&utm_content=upgrade-plugin-listing';

		echo "<style id='ssa-admin-dash-upgrade-link-css'>
			.ssa-basic-upgrade-link-admin-dash {
				padding: 2px 4px;
				border-radius: 2px;
				background-color: #037b77;
				color: #fff;
				border: none;
			}
			
			.ssa-basic-upgrade-link-admin-dash:hover {
				background-color: #046a66;
				color: white;
			}
		</style>";

		$upgrade_link = '<a class="ssa-basic-upgrade-link-admin-dash" target="_blank" href="' . $ssa_pricing_url . '">' . __('Upgrade', 'simply-schedule-appointments') . '</a>';

		$links['upgrade'] = $upgrade_link;
		return $links;
		
	}

	public function register_admin_menu() {
		add_menu_page(
			__('Appointments', 'simply-schedule-appointments' ),
			__('Appointments', 'simply-schedule-appointments' ),
			'ssa_manage_appointments',
			'simply-schedule-appointments',
			array( $this, 'render_admin_page' ),
			'dashicons-calendar',
			null
		);

		$settings = $this->plugin->settings->get();
		if ( !empty( $settings['global']['wizard_completed'] ) ) {
			add_submenu_page(
				'simply-schedule-appointments',
				__('Appointment Types', 'simply-schedule-appointments' ),
				__('Appointment Types', 'simply-schedule-appointments' ),
				'ssa_manage_appointment_types',
				'simply-schedule-appointments-types',
				array( $this, 'render_admin_page' )
			);

			if( class_exists( 'SSA_Staff' ) && $this->plugin->settings_installed->is_enabled( 'staff' ) ) {
				add_submenu_page(
					'simply-schedule-appointments',
					__('Team', 'simply-schedule-appointments' ),
					__('Team', 'simply-schedule-appointments' ),
					'ssa_manage_staff',
					'simply-schedule-appointments-team',
					array( $this, 'render_admin_page' )
				);
			}

			add_submenu_page(
				'simply-schedule-appointments',
				__('Settings', 'simply-schedule-appointments' ),
				__('Settings', 'simply-schedule-appointments' ),
				'ssa_manage_site_settings',
				'simply-schedule-appointments-settings',
				array( $this, 'render_admin_page' )
			);			
			
			if ( SSA_Support::should_display_support_tab() ) {
				add_submenu_page(
					'simply-schedule-appointments',
					__('Support', 'simply-schedule-appointments' ),
					__('Support', 'simply-schedule-appointments' ),
					'ssa_manage_site_settings',
					'simply-schedule-appointments-support',
					array( $this, 'render_admin_page' )
				);
			}
		}

	}

	public function should_restrict_access_to_admin_page() {
		if ( ! current_user_can( 'ssa_manage_others_appointments' ) ) {
			if ( ! current_user_can( 'ssa_manage_site_settings' ) ) {
				$staff = $this->plugin->staff_model->find_by_user_id( get_current_user_id() );
				if ( empty( $staff['id'] ) ) {
					// This is user with the "Team Member" role but who isn't associated to a staff_id
					return true;
				} else if ( 'publish' !== $staff['status'] ) {
					// This is user with the "Team Member" role but is linked to an inactive/deleted Team Member
					return true;
				}
			}
		}

		return false;
	}
	
	public function render_admin_page() {
		if ( $this->should_restrict_access_to_admin_page() ) {
			wp_die( __( 'Please ask your administrator to link your account to an active team member.', 'simply-schedule-appointments' ), __( 'Permission Denied', 'simply-schedule-appointments' ) );
		}
		
		remove_filter( 'script_loader_tag', 'mesmerize_defer_js_scripts', 11 ); // remove bug with 3rd party "Mesmerize" theme
		remove_filter( 'style_loader_tag', 'mesmerize_defer_css_scripts', 11 ); // remove bug with 3rd party "Mesmerize" theme
		//
		wp_enqueue_style( 'ssa-admin-material-icons', $this->plugin->url('assets/css/material-icons.css'), array(), Simply_Schedule_Appointments::VERSION );
		wp_enqueue_style( 'ssa-admin-vendor', $this->plugin->url('admin-app/dist/static/css/chunk-vendors.css'), array(), Simply_Schedule_Appointments::VERSION );
		wp_enqueue_style( 'ssa-admin-style', $this->plugin->url('admin-app/dist/static/css/app.css'), array('ssa-admin-vendor'), Simply_Schedule_Appointments::VERSION );
		wp_enqueue_style( 'ssa-admin-roboto-font', $this->plugin->url('assets/css/roboto-font.css'), array(), Simply_Schedule_Appointments::VERSION );
		wp_enqueue_style( 'ssa-unsupported-style', $this->plugin->url('assets/css/unsupported.css'), array(), Simply_Schedule_Appointments::VERSION );
		wp_enqueue_style( 'ssa-admin-style-custom', $this->plugin->templates->locate_template_url('admin-app/custom.css'), array(), Simply_Schedule_Appointments::VERSION );

		wp_enqueue_script( 'ssa-unsupported-script', $this->plugin->url('assets/js/unsupported.js'), array(), Simply_Schedule_Appointments::VERSION);

		wp_enqueue_script( 'ssa-admin-manifest', $this->plugin->url('admin-app/dist/static/js/manifest.js'), array(), Simply_Schedule_Appointments::VERSION, true );
		wp_enqueue_script( 'ssa-admin-vendor', $this->plugin->url('admin-app/dist/static/js/chunk-vendors.js'), array( 'ssa-admin-manifest' ), Simply_Schedule_Appointments::VERSION, true );
		wp_register_script( 'ssa-admin-app', $this->plugin->url('admin-app/dist/static/js/app.js'), array( 'ssa-admin-vendor' ), Simply_Schedule_Appointments::VERSION, true );

		$pinned_notices = $this->plugin->notices->get_pinned_notices();
		$error_notices = $this->plugin->error_notices->get_error_notices();

		$settings = $this->plugin->settings->get();
		$settings = $this->plugin->settings->remove_unauthorized_settings_for_current_user( $settings );

		$appointment_types = $this->plugin->appointment_type_model->get_all_appointment_types();
		$appointment_type_labels = $this->plugin->appointment_type_label_model->query();

		wp_localize_script( 'ssa-admin-app', 'ssa',
			$this->plugin->bootstrap->get_api_vars() );
		wp_localize_script( 'ssa-admin-app', 'ssa_pinned_notices', $pinned_notices );
		wp_localize_script( 'ssa-admin-app', 'ssa_error_notices', $error_notices );
		wp_localize_script( 'ssa-admin-app', 'ssa_settings', $settings );
		wp_localize_script( 'ssa-admin-app', 'ssa_appointment_types', $appointment_types );
		wp_localize_script( 'ssa-admin-app', 'ssa_appointment_type_labels', $appointment_type_labels );
		wp_localize_script( 'ssa-admin-app', 'ssa_translations', $this->get_translations() );
		wp_enqueue_script( 'ssa-admin-app' );
		echo '
		<style>
			body.wp-admin.admin-bar.ssa-admin-app #wpadminbar,
			body.wp-admin.admin-bar.ssa-admin-app #adminmenumain,
			body.wp-admin.admin-bar.ssa-admin-app #wpfooter,
			body.wp-admin.admin-bar.ssa-admin-app .hidden,
			body.wp-admin.admin-bar.ssa-admin-app .wpsso-notice.notice,
			#wpadminbar,
			#adminmenumain,
			body.ssa-admin-app.wp-admin.has-toolbar-notices #wpadminbar,
			body.ssa-admin-app.wp-admin.is-fullscreen-mode.has-toolbar-notices #wpadminbar,
			body.ssa-admin-app.wp-admin.is-wp-toolbar-disabled.has-toolbar-notices #wpadminbar,
			body.ssa-admin-app.wp-admin.woocommerce-embed-page.has-toolbar-notices #wpadminbar,
			#wpfooter {
				display: none !important;
			}
			.hidden {
				display: none;
			}
		</style>
		<div id="ssa-admin-app">
			<noscript>
				<div class="unsupported">
					<div class="unsupported-container">
						<img class="unsupported-icon" src="' . $this->plugin->url('admin-app/dist/static/images/foxes/fox-sleeping.svg') . '"/>
						<h1 class="unsupported-label">' . __('Simply Schedule Appointments requires JavaScript', 'simply-schedule-appointments') . '</h1>
						<p class="unsupported-description">' . __('Please make sure you enable JavaScript in your browser.', 'simply-schedule-appointments') . '</p>
					</div>
				</div>
			</noscript>
		</div>
		<div id="ssa-unsupported" style="display:none;">
				<div class="unsupported">
					<div class="unsupported-container">
						<img class="unsupported-icon" src="' . $this->plugin->url('admin-app/dist/static/images/foxes/fox-sleeping.svg') . '"/>
						<h1 class="unsupported-label">' . __('Unsupported Browser', 'simply-schedule-appointments') . '</h1>
						<p class="unsupported-description">' . __('Please update your browser to something more modern. We recommend Firefox or Chrome.', 'simply-schedule-appointments') . '</p>
					</div>
				</div>
		</div>
		';
	}

	public function get_translations() {
		include $this->plugin->dir( 'languages/admin-app-translations.php' );
		return $translations;
	}

	public function body_class( $classes ) {
		if ( !$this->is_admin_page() ) {
			return $classes;
		}

		$classes = "$classes ssa-admin-app "; // adding a trailing space for conflicts with poorly coded plugins

		return $classes;
	}

	public function maybe_create_booking_page() {
		$settings = $this->plugin->settings->get();
		if ( empty( $settings['global']['booking_post_id'] ) ) {
			return $this->create_booking_page();
		}

		$appointment = $this->plugin->appointment_model->get( $settings['global']['booking_post_id'] );
		if ( empty( $appointment['id'] ) ) {
			return $this->create_booking_page();
		}

		return $settings['global']['booking_post_id'];
	}

	public function create_booking_page() {
		$wp_error = null;
		$post_id = wp_insert_post( array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Schedule an Appointment',
			'post_name' => 'appointments',
			'post_content' => '[ssa_booking]',
		), $wp_error );

		$settings = $this->plugin->settings->get();
		$settings['global']['booking_post_id'] = $post_id;
		$this->plugin->settings->update_section( 'global', $settings['global'] );

		return $post_id;
	}

}
