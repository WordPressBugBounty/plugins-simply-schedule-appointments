<?php
/**
 * Simply Schedule Appointments Debug.
 *
 * @since   4.0.1
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments Debug.
 *
 * @since 4.0.1
 */
class SSA_Debug {
	/**
	 * Parent plugin class.
	 *
	 * @since 4.0.1
	 *
	 * @var   Simply_Schedule_Appointments
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  4.0.1
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
	 * @since  4.0.1
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'debug_settings' ) );
		add_action( 'init', 	  array( $this, 'display_ssa_debug_logs' ) );
	}

	public function debug_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only debug display gated by current_user_can( 'ssa_manage_site_settings' ); no state change.
		if ( ! isset( $_GET['ssa-debug-settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'ssa_manage_site_settings' ) ) {
			return;
		}

		$settings = $this->plugin->settings->get();
		if ( ! empty( $_GET['ssa-debug-settings'] ) ) {
			if ( empty( $settings[sanitize_text_field( wp_unslash( $_GET['ssa-debug-settings'] ) )] ) ) {
				die( 'setting slug not found' ); // phpcs:ignore
			}
			$settings = $settings[sanitize_text_field( wp_unslash( $_GET['ssa-debug-settings'] ) )];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<pre>'.print_r($settings, true).'</pre>'; // phpcs:ignore
		exit;
	}

	public function display_ssa_debug_logs() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only debug log display gated by a site-unique hash token; no state change.
		if ( empty( $_GET['ssa-debug-logs'] )) {
			return;
		}

		if ( self::get_site_unique_hash_for_debug_logs() !== sanitize_text_field( wp_unslash( $_GET['ssa-debug-logs'] ) ) ) {
			return;
		}
		
		if ( isset( $_GET['revisions'] ) ) {
			$this->display_ssa_revisions_logs();
			return;
		}

		if ( isset( $_GET['revisions_meta'] ) ) {
			$this->display_ssa_revisions_meta_logs();
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$path = $this->plugin->support_status->get_log_file_path( 'debug' );
		if ( file_exists( $path ) && is_readable( $path ) ) {
			$content = file_get_contents( $path );
			echo '<pre>'.print_r($content, true).'</pre>'; // phpcs:ignore
			exit;
		}

	}

	public static function get_site_unique_hash_for_debug_logs() {
		return SSA_Utils::site_unique_hash( 'ssa-debug-logs' );
	}

	public function display_ssa_revisions_logs() {

		$args = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only debug display reached only via display_ssa_debug_logs() after a site-unique hash gate; no state change.
		if ( ! empty( $_GET['appointment_id'] )) {
			$args['appointment_id'] = sanitize_text_field( wp_unslash( $_GET['appointment_id'] ) );
		}
		if ( ! empty( $_GET['appointment_type_id'] )) {
			$args['appointment_type_id'] = sanitize_text_field( wp_unslash( $_GET['appointment_type_id'] ) );
		}
		if ( ! empty( $_GET['user_id'] )) {
			$args['user_id'] = sanitize_text_field( wp_unslash( $_GET['user_id'] ) );
		}
		if ( ! empty( $_GET['staff_id'] )) {
			$args['staff_id'] = sanitize_text_field( wp_unslash( $_GET['staff_id'] ) );
		}
		if ( ! empty( $_GET['id'] )) {
			$args['id'] = sanitize_text_field( wp_unslash( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array_merge( array(
			'orderby' => 'id',
			'order'   => 'DESC',
			'number'	=> 100
		), $args );

		$revisions = $this->plugin->revision_model->query( $args );

		if ( empty( $revisions ) ) {
			echo 'No revisions have been found';
			exit;
		}

		$revisions = array_reverse( $revisions );	

		ob_start();
		include $this->plugin->dir('templates/ssa-logs/revisions.php');
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a rendered HTML fragment from the hardcoded internal template templates/ssa-logs/revisions.php; blanket-escaping the buffer would corrupt the markup.
		exit;
	}

	public function display_ssa_revisions_meta_logs() {
		$args = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only debug display reached only via display_ssa_debug_logs() after a site-unique hash gate; no state change.
		if ( ! empty( $_GET['revision_id'] )) {
			$args['revision_id'] = sanitize_text_field( wp_unslash( $_GET['revision_id'] ) );
		}
		if ( ! empty( $_GET['meta_key'] )) {
			$args['meta_key'] = sanitize_text_field( wp_unslash( $_GET['meta_key'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 'meta_key' filters the custom ssa_revision_meta table (SSA_Revision_Meta_Model), bound via $wpdb->prepare(); not WP post-meta.
		}
		if ( ! empty( $_GET['meta_value_before'] )) {
			$args['meta_value_before'] = sanitize_text_field( wp_unslash( $_GET['meta_value_before'] ) );
		}
		if ( ! empty( $_GET['meta_value'] )) {
			$args['meta_value'] = sanitize_text_field( wp_unslash( $_GET['meta_value'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 'meta_value' filters the custom ssa_revision_meta table (SSA_Revision_Meta_Model), bound via $wpdb->prepare(); not WP post-meta.
		}
		if ( ! empty( $_GET['id'] )) {
			$args['id'] = sanitize_text_field( wp_unslash( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array_merge( array(
			'orderby' => 'id',
			'order'   => 'DESC',
			'number'	=> 100
		), $args );

		$revisions_meta = $this->plugin->revision_meta_model->query( $args );

		if ( empty( $revisions_meta ) ) {
			echo 'No revisions meta have been found';
			exit;
		}

		$revisions_meta = array_reverse( $revisions_meta );	

		ob_start();
		include $this->plugin->dir('templates/ssa-logs/revisions-meta.php');
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a rendered HTML fragment from the hardcoded internal template templates/ssa-logs/revisions-meta.php; blanket-escaping the buffer would corrupt the markup.
		exit;
	}

}
