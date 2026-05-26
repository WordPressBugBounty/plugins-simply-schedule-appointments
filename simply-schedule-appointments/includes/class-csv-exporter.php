<?php
/**
 * Simply Schedule Appointments CSV Exporter.
 *
 * @since   4.8.9
 * @package Simply_Schedule_Appointments
 */

/**
 * Simply Schedule Appointments CSV Exporter.
 *
 * @since 4.8.9
 */
class SSA_CSV_Exporter {
	/**
	 * CSV file base path.
	 *
	 * @var string
	 */
	protected $file_path = null;

	/**
	 * CSV file base url.
	 *
	 * @var string
	 */
	protected $file_url = null;

	/**
	 * Get .csv for appointments.
	 *
	 * @param  array  $appointments Array with SSA_Appointment_Object objects.
	 * @param  string $filename .csv filename.
	 *
	 * @return string .csv path
	 */
	public function get_csv( $appointments = array(), $filename = '' ) {
		if ( empty( $appointments ) ) {
			return new WP_Error( 'ssa_export_csv_no_appointments', __( 'No appointments to export to .csv file.', 'simply-schedule-appointments' ) );
		}
		if ( '' === $filename ) {
			$filename = 'deleted-appointments-' . time();
		}

		$this->file_path = $this->get_file_path( $filename );
		$this->file_url  = $this->get_file_url( $filename );

		// Create the .csv.
		$this->create( $appointments );

		if ( ! file_exists( $this->file_path ) ) {
			return new WP_Error( 'ssa_export_csv_file_not_found', __( 'Error creating .csv file.', 'simply-schedule-appointments' ) );
		}

		return array(
			'file_path' => $this->file_path,
			'file_url'  => $this->file_url,
		);
	}

	/**
	 * Append rows to an existing .csv created via get_csv(). Skips the header
	 * row (the file already has one) and writes appointment values in the same
	 * column order as the existing header by re-emitting array_keys() of the
	 * first appointment row.
	 *
	 * Used by multi-batch flows (purge_appointments) where every batch should
	 * contribute to a single backup file scoped by drain id, instead of each
	 * batch overwriting its own file and orphaning the previous one on disk.
	 *
	 * @since 6.7.13
	 *
	 * @param array  $appointments Rows to append (same shape as get_csv()).
	 * @param string $filename     Same filename passed to the original get_csv() call.
	 * @return array|WP_Error file_path/file_url on success, WP_Error if the file
	 *                        does not exist or the append handle could not be opened.
	 */
	public function append_csv( $appointments = array(), $filename = '' ) {
		if ( empty( $appointments ) ) {
			return new WP_Error( 'ssa_export_csv_no_appointments', __( 'No appointments to export to .csv file.', 'simply-schedule-appointments' ) );
		}
		if ( '' === $filename ) {
			return new WP_Error( 'ssa_export_csv_no_filename', __( 'A filename is required to append to an existing .csv.', 'simply-schedule-appointments' ) );
		}

		$this->file_path = $this->get_file_path( $filename );
		$this->file_url  = $this->get_file_url( $filename );

		if ( ! file_exists( $this->file_path ) ) {
			return new WP_Error( 'ssa_export_csv_file_not_found', __( 'Cannot append: .csv file does not exist.', 'simply-schedule-appointments' ) );
		}

		// @codingStandardsIgnoreStart
		$handle = @fopen( $this->file_path, 'a' );
		if ( false === $handle ) {
			return new WP_Error( 'ssa_export_csv_file_not_writable', __( 'Cannot append: failed to open .csv for writing.', 'simply-schedule-appointments' ) );
		}
		foreach ( $appointments as $appointment ) {
			fputcsv( $handle, $appointment );
		}
		@fclose( $handle );
		// @codingStandardsIgnoreEnd

		return array(
			'file_path' => $this->file_path,
			'file_url'  => $this->file_url,
		);
	}

	/**
	 * Get file path.
	 *
	 * @param  string $filename Filename.
	 *
	 * @return string
	 */
	protected function get_file_path( $filename ) {
		$path = SSA_Filesystem::get_uploads_dir_path();
		if ( empty( $path ) ) {
			return false;
		}

		$path .= '/csv';
		if ( ! wp_mkdir_p( $path ) ) {
			return false;
		}

		if ( ! file_exists( $path . '/index.html' ) ) {
		// @codingStandardsIgnoreStart
			$handle = @fopen( $path . '/index.html', 'w' );
			@fwrite( $handle, '' );
			@fclose( $handle );
			// @codingStandardsIgnoreEnd
		}

		return $path . '/' . sanitize_title( $filename ) . '.csv';
	}

	/**
	 * Get file url
	 *
	 * @param  string $filename Filename.
	 *
	 * @return string
	 */
	protected function get_file_url( $filename ) {
		$url = SSA_Filesystem::get_uploads_dir_url();
		if ( empty( $url ) ) {
			return false;
		}

		$url .= '/csv';

		return $url . '/' . sanitize_title( $filename ) . '.csv';
	}

	/**
	 * Create the .ics file.
	 *
	 * @param array $appointments The appointments array.
	 *
	 * @return void
	 */
	protected function create( $appointments ) {
		// @codingStandardsIgnoreStart
		$handle = @fopen( $this->file_path, 'w' );
		fputcsv( $handle, array_keys( $appointments[0] ) );
		foreach ( $appointments as $appointment ) {
			fputcsv( $handle, $appointment );
		}
		@fclose( $handle );
		// @codingStandardsIgnoreEnd
	}

}
