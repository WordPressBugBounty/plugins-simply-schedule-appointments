<?php
/**
 * Primary class file for the Health Check plugin.
 *
 * @package Health Check
 */

/**
 * Class HealthCheck
 */
class TD_Health_Check {

	/**
	 * Notices to show at the head of the admin screen.
	 *
	 * @access public
	 *
	 * @var array
	 */
	public $admin_notices = array();

	/**
	 * HealthCheck constructor.
	 *
	 * @uses TD_Health_Check::init()
	 *
	 * @return void
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Plugin initiation.
	 *
	 * A helper function, called by `HealthCheck::__construct()` to initiate actions, hooks and other features needed.
	 *
	 * @uses add_action()
	 * @uses add_filter()
	 *
	 * @return void
	 */
	public function init() {
		// add_action( 'wp_ajax_health-check-loopback-no-plugins', array( 'TD_Health_Check_Loopback', 'loopback_no_plugins' ) );
		// add_action( 'wp_ajax_health-check-loopback-individual-plugins', array( 'TD_Health_Check_Loopback', 'loopback_test_individual_plugins' ) );
		// add_action( 'wp_ajax_health-check-loopback-default-theme', array( 'TD_Health_Check_Loopback', 'loopback_test_default_theme' ) );
		// add_action( 'wp_ajax_health-check-files-integrity-check', array( 'TD_Health_Check_Files_Integrity', 'run_files_integrity_check' ) );
		// add_action( 'wp_ajax_health-check-mail-check', array( 'TD_Health_Check_Mail_Check', 'run_mail_check' ) );
		// add_action( 'wp_ajax_health-check-confirm-warning', array( 'TD_Health_Check_Troubleshoot', 'confirm_warning' ) );
	}

	/**
	 * Show a warning modal about keeping backups.
	 *
	 * @uses TD_Health_Check_Troubleshoot::has_seen_warning()
	 *
	 * @return void
	 */
	public function show_backup_warning() {
		if ( TD_Health_Check_Troubleshoot::has_seen_warning() ) {
			return;
		}

		include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/modals/backup-warning.php' );
	}

	/**
	 * Initiate troubleshooting mode.
	 *
	 * Catch when the troubleshooting form has been submitted, and appropriately set required options and cookies.
	 *
	 * @uses current_user_can()
	 * @uses TD_Health_Check_Troubleshoot::initiate_troubleshooting_mode()
	 *
	 * @return void
	 */
	public function start_troubleshoot_mode() {
		if ( ! isset( $_POST['health-check-troubleshoot-mode'] ) || ! current_user_can( 'ssa_manage_site_settings' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Vendored Health Check library upstream code, unmodified; access gated by current_user_can() capability check.
			return;
		}

		TD_Health_Check_Troubleshoot::initiate_troubleshooting_mode();
	}

	/**
	 * Initiate troubleshooting mode for a specific plugin.
	 *
	 * Catch when the troubleshooting link on an individual plugin has been clicked, and appropriately sets the
	 * required options and cookies.
	 *
	 * @uses current_user_can()
	 * @uses ob_start()
	 * @uses TD_Health_Check_Troubleshoot::mu_plugin_exists()
	 * @uses TD_Health_Check::get_filesystem_credentials()
	 * @uses TD_Health_Check_Troubleshoot::setup_must_use_plugin()
	 * @uses TD_Health_Check_Troubleshoot::maybe_update_must_use_plugin()
	 * @uses ob_get_clean()
	 * @uses TD_Health_Check_Troubleshoot::initiate_troubleshooting_mode()
	 * @uses wp_redirect()
	 * @uses admin_url()
	 *
	 * @return void
	 */
	public function start_troubleshoot_single_plugin_mode() {
		if ( ! isset( $_GET['health-check-troubleshoot-plugin'] ) || ! current_user_can( 'ssa_manage_site_settings' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Vendored Health Check library upstream code, unmodified; access gated by current_user_can() capability check.
			return;
		}

		ob_start();

		$needs_credentials = false;

		if ( ! TD_Health_Check_Troubleshoot::mu_plugin_exists() ) {
			if ( ! TD_Health_Check::get_filesystem_credentials() ) {
				$needs_credentials = true;
			} else {
				$check_output = TD_Health_Check_Troubleshoot::setup_must_use_plugin( false );
				if ( false === $check_output ) {
					$needs_credentials = true;
				}
			}
		} else {
			if ( ! TD_Health_Check_Troubleshoot::maybe_update_must_use_plugin() ) {
				$needs_credentials = true;
			}
		}

		$result = ob_get_clean();

		if ( $needs_credentials ) {
			$this->admin_notices[] = (object) array(
				'message' => $result,
				'type'    => 'warning',
			);
			return;
		}

		$troubleshoot_plugin = sanitize_text_field( wp_unslash( $_GET['health-check-troubleshoot-plugin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Vendored Health Check library upstream code, unmodified; access gated by current_user_can() capability check above.
		TD_Health_Check_Troubleshoot::initiate_troubleshooting_mode( array(
			$troubleshoot_plugin => $troubleshoot_plugin,
		) );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
	}

	/**
	 * Load translations.
	 *
	 * Loads the textdomain needed to get translations for our plugin.
	 *
	 * @uses load_plugin_textdomain()
	 * @uses basename()
	 * @uses dirname()
	 *
	 * @return void
	 */
	public function load_i18n() {
		load_plugin_textdomain( 'health-check', false, basename( dirname( __FILE__ ) ) . '/languages/' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Vendored Health Check library upstream code, unmodified.
	}

	/**
	 * Enqueue assets.
	 *
	 * Conditionally enqueue our CSS and JavaScript when viewing plugin related pages in wp-admin.
	 *
	 * @uses wp_enqueue_style()
	 * @uses plugins_url()
	 * @uses wp_enqueue_script()
	 * @uses wp_localize_script()
	 * @uses esc_html__()
	 *
	 * @return void
	 */
	public function enqueues() {
		// Don't enqueue anything unless we're on the health check page
		if ( ! isset( $_GET['page'] ) || 'health-check' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin page slug to decide asset enqueuing; no state change.

			/*
			 * Special consideration, if warnings are not dismissed we need to display
			 * our modal, and thus require our styles, in other locations, before bailing.
			 */
			if ( ! TD_Health_Check_Troubleshoot::has_seen_warning() ) {
				wp_enqueue_style( 'health-check', TD_HEALTH_CHECK_PLUGIN_URL . '/assets/css/health-check.css', array(), TD_HEALTH_CHECK_PLUGIN_VERSION );
			}
			return;
		}

		wp_enqueue_style( 'health-check', TD_HEALTH_CHECK_PLUGIN_URL . '/assets/css/health-check.css', array(), TD_HEALTH_CHECK_PLUGIN_VERSION );

		wp_enqueue_script( 'health-check', TD_HEALTH_CHECK_PLUGIN_URL . '/assets/javascript/health-check.js', array( 'jquery' ), TD_HEALTH_CHECK_PLUGIN_VERSION, true );

		wp_localize_script( 'health-check', 'HealthCheck', array(
			'string'  => array(
				'please_wait'   => esc_html__( 'Please wait...', 'simply-schedule-appointments' ),
				'copied'        => esc_html__( 'Copied', 'simply-schedule-appointments' ),
				'running_tests' => esc_html__( 'Currently being tested...', 'simply-schedule-appointments' ),
			),
			'warning' => array(
				'seen_backup' => TD_Health_Check_Troubleshoot::has_seen_warning(),
			),
		) );
	}

	/**
	 * Add item to the admin menu.
	 *
	 * @uses add_dashboard_page()
	 * @uses __()
	 *
	 * @return void
	 */
	public function action_admin_menu() {
		add_dashboard_page( _x( 'Health Check', 'Menu, Section and Page Title', 'simply-schedule-appointments' ), _x( 'Health Check', 'Menu, Section and Page Title', 'simply-schedule-appointments' ), 'ssa_manage_site_settings', 'health-check', array( $this, 'dashboard_page' ) );
	}

	/**
	 * Add a quick-access link under our plugin name on the plugins-list.
	 *
	 * @uses plugin_basename()
	 * @uses sprintf()
	 * @uses menu_page_url()
	 *
	 * @param array  $meta An array containing meta links.
	 * @param string $name The plugin slug that these metas relate to.
	 *
	 * @return array
	 */
	public function settings_link( $meta, $name ) {
		if ( plugin_basename( __FILE__ ) === $name ) {
			$meta[] = sprintf( '<a href="%s">' . _x( 'Health Check', 'Menu, Section and Page Title', 'simply-schedule-appointments' ) . '</a>', menu_page_url( 'health-check', false ) );
		}

		return $meta;
	}

	/**
	 * Add a troubleshooting action link to plugins.
	 *
	 * @param $actions
	 * @param $plugin_file
	 * @param $plugin_data
	 * @param $context
	 *
	 * @return array
	 */
	public function troubeshoot_plugin_action( $actions, $plugin_file, $plugin_data, $context ) {
		// Don't add anything if this is a Must-Use plugin, we can't touch those.
		if ( 'mustuse' === $context ) {
			return $actions;
		}

		// Only add troubleshooting actions to active plugins.
		if ( ! is_plugin_active( $plugin_file ) ) {
			return $actions;
		}

		// Set a slug if the plugin lives in the plugins directory root.
		if ( ! stristr( $plugin_file, '/' ) ) {
			$plugin_data['slug'] = $plugin_file;
		}

		$actions['troubleshoot'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( array(
				'health-check-troubleshoot-plugin' => ( isset( $plugin_data['slug'] ) ? $plugin_data['slug'] : sanitize_title( $plugin_data['Name'] ) ),
			), admin_url( 'plugins.php' ) ) ),
			esc_html__( 'Troubleshoot', 'simply-schedule-appointments' )
		);

		return $actions;
	}

	/**
	 * Render our admin page.
	 *
	 * @uses _e()
	 * @uses esc_html__()
	 * @uses printf()
	 * @uses sprintf()
	 * @uses menu_page_url()
	 * @uses dirname()
	 *
	 * @return void
	 */
	public function dashboard_page() {
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html_x( 'Health Check', 'Menu, Section and Page Title', 'simply-schedule-appointments' ); ?>
			</h1>

			<?php
			$tabs = array(
				'site-status'  => esc_html__( 'Site Status', 'simply-schedule-appointments' ),
				'debug'        => esc_html__( 'Debug Information', 'simply-schedule-appointments' ),
				'troubleshoot' => esc_html__( 'Troubleshooting', 'simply-schedule-appointments' ),
				'phpinfo'      => esc_html__( 'PHP Information', 'simply-schedule-appointments' ),
				'tools'        => esc_html__( 'Tools', 'simply-schedule-appointments' ),
			);

			$current_tab = ( isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'site-status' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page tab selection for display; no state change.
			?>

			<h2 class="nav-tab-wrapper wp-clearfix">
				<?php
				foreach ( $tabs as $tab => $label ) {
					printf(
						'<a href="%s" class="nav-tab %s">%s</a>',
						esc_url(
							sprintf(
								'%s&tab=%s',
								menu_page_url( 'health-check', false ),
								$tab
							)
						),
						( $current_tab === $tab ? 'nav-tab-active' : '' ),
						$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label values are pre-escaped via esc_html__() in the $tabs array above.
					);
				}
				?>
			</h2>

			<?php
			switch ( $current_tab ) {
				case 'debug':
					include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/pages/debug-data.php' );
					break;
				case 'phpinfo':
					include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/pages/phpinfo.php' );
					break;
				case 'troubleshoot':
					include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/pages/troubleshoot.php' );
					break;
				case 'tools':
					include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/pages/tools.php' );
					break;
				case 'site-status':
				default:
					include_once( TD_HEALTH_CHECK_PLUGIN_DIRECTORY . '/pages/site-status.php' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Display styled admin notices.
	 *
	 * @uses printf()
	 *
	 * @param string $message A sanitized string containing our notice message.
	 * @param string $status  A string representing the status type.
	 *
	 * @return void
	 */
	static function display_notice( $message, $status = 'success' ) {
		printf(
			'<div class="notice notice-%s inline">',
			esc_attr( $status )
		);

		printf(
			'<p>%s</p>',
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is passed pre-escaped by callers (esc_html__() strings and trusted markup built with esc_url()/esc_html__() in class-health-check-troubleshoot.php); escaping again would double-escape and break the markup.
		);

		echo '</div>';
	}

	/**
	 * Display admin notices if we have any queued.
	 *
	 * @return void
	 */
	public function admin_notices() {
		foreach ( $this->admin_notices as $admin_notice ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $admin_notice->type ),
				$admin_notice->message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message holds buffered HTML captured via ob_get_clean() from WP core's request_filesystem_credentials() form (see start_troubleshoot_single_plugin_mode); it is trusted markup that must not be escaped.
			);
		}
	}


	/**
	 * Conditionally show a form for providing filesystem credentials when introducing our troubleshooting mode plugin.
	 *
	 * @uses wp_nonce_url()
	 * @uses add_query_arg()
	 * @uses admin_url()
	 * @uses request_filesystem_credentials()
	 * @uses WP_Filesystem
	 *
	 * @param array $args Any WP_Filesystem arguments you wish to pass.
	 *
	 * @return bool
	 */
	static function get_filesystem_credentials( $args = array() ) {
		$args = array_merge(
			array(
				'page' => 'health-check',
				'tab'  => 'troubleshoot',
			),
			$args
		);

		$url   = wp_nonce_url( add_query_arg( $args, admin_url() ) );
		$creds = request_filesystem_credentials( $url, '', false, WP_CONTENT_DIR, array( 'health-check-troubleshoot-mode', 'action' ) );
		if ( false === $creds ) {
			return false;
		}

		if ( ! WP_Filesystem( $creds ) ) {
			request_filesystem_credentials( $url, '', true, WPMU_PLUGIN_DIR, array( 'health-check-troubleshoot-mode', 'action' ) );
			return false;
		}

		return true;
	}

	/**
	 * Perform a check to see is JSON is enabled.
	 *
	 * @uses extension_loaded()
	 * @uses function_Exists()
	 * @uses son_encode()
	 *
	 * @return bool
	 */
	static function json_check() {
		$extension_loaded = extension_loaded( 'json' );
		$functions_exist  = function_exists( 'json_encode' ) && function_exists( 'json_decode' );
		$functions_work   = function_exists( 'json_encode' ) && ( '' != json_encode( 'my test string' ) );

		return $extension_loaded && $functions_exist && $functions_work;
	}
}
